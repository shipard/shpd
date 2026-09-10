<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\MessageTitleComposer;

/**
 * Serverový fallback titulku z canonicalu (D2) — docs / registry / null.
 */
final class MessageTitleComposerTest extends TestCase
{
    private function config(): ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['core.mail.primaryTypes', [
                'invoiceReceived' => ['name' => 'Přijatá faktura', 'target' => 'docs'],
                'creditNote'      => ['name' => 'Dobropis', 'target' => 'docs'],
                'contract'        => ['name' => 'Smlouva', 'target' => 'registry', 'docKind' => 'contract'],
            ]],
        ]);
        return $config;
    }

    /** @return array<string, mixed> */
    private function docsCanonical(): array
    {
        return [
            'docType' => 'invoiceReceived',
            'docNumber' => 'FV-2026-0042',
            'selfParty' => 'customer',
            'supplier' => ['name' => 'Dodavatel s.r.o.', 'companyId' => '12345678'],
            'currency' => 'CZK',
            'totals' => ['totalBase' => 10830.58, 'totalVat' => 2274.42, 'totalAmount' => 13105.00],
        ];
    }

    public function testDocsWithLabelNumberSupplierAndAmount(): void
    {
        $composer = new MessageTitleComposer($this->config());
        $this->assertSame(
            'Přijatá faktura FV-2026-0042 — Dodavatel s.r.o., 13 105 CZK',
            $composer->compose($this->docsCanonical(), 'invoiceReceived'),
        );
    }

    public function testDocsWithoutConfigOmitsTypeLabel(): void
    {
        $this->assertSame(
            'FV-2026-0042 — Dodavatel s.r.o., 13 105 CZK',
            (new MessageTitleComposer(null))->compose($this->docsCanonical(), 'invoiceReceived'),
        );
    }

    public function testMissingPartsAreOmitted(): void
    {
        $composer = new MessageTitleComposer($this->config());

        $noNumber = $this->docsCanonical();
        unset($noNumber['docNumber']);
        $this->assertSame('Přijatá faktura — Dodavatel s.r.o., 13 105 CZK', $composer->compose($noNumber, 'invoiceReceived'));

        $noSupplier = $this->docsCanonical();
        $noSupplier['supplier'] = null;
        $this->assertSame('Přijatá faktura FV-2026-0042 — 13 105 CZK', $composer->compose($noSupplier, 'invoiceReceived'));

        $noTotals = $this->docsCanonical();
        unset($noTotals['totals'], $noTotals['supplier']);
        $this->assertSame('Přijatá faktura FV-2026-0042', $composer->compose($noTotals, 'invoiceReceived'));

        // Účtenka: bez čísla, bez labelu (bez configu) → jen dodavatel a částka.
        $receipt = $this->docsCanonical();
        unset($receipt['docNumber']);
        $this->assertSame('Dodavatel s.r.o., 13 105 CZK', (new MessageTitleComposer(null))->compose($receipt, 'invoiceReceived'));
    }

    public function testSelfPartySupplierUsesCustomerName(): void
    {
        $canonical = $this->docsCanonical();
        $canonical['selfParty'] = 'supplier';
        $canonical['customer'] = ['name' => 'Odběratel a.s.'];
        $this->assertSame(
            'FV-2026-0042 — Odběratel a.s., 13 105 CZK',
            (new MessageTitleComposer(null))->compose($canonical, 'invoiceReceived'),
        );
    }

    public function testRegistryUsesCanonicalTitle(): void
    {
        $composer = new MessageTitleComposer($this->config());
        $canonical = [
            'schema' => 'shpd.registry.document.v1',
            'docType' => 'contract',
            'title' => '  Servisní smlouva —   Servis a.s. ',
            'party' => ['name' => 'Servis a.s.'],
        ];
        $this->assertSame('Servisní smlouva — Servis a.s.', $composer->compose($canonical, 'contract'));
        $this->assertNull($composer->compose(['schema' => 'shpd.registry.document.v1', 'docType' => 'contract'], 'contract'));
    }

    public function testNullAndWrapperAndEmptyGiveNull(): void
    {
        $composer = new MessageTitleComposer($this->config());
        $this->assertNull($composer->compose(null, 'invoiceReceived'));
        $this->assertNull($composer->compose(['_validationError' => 'x', '_rawOutput' => $this->docsCanonical()], 'invoiceReceived'));
        $this->assertNull((new MessageTitleComposer(null))->compose(['docType' => 'invoiceReceived'], 'invoiceReceived'));
    }

    public function testTitleIsTruncatedToColumnLength(): void
    {
        $canonical = $this->docsCanonical();
        $canonical['supplier']['name'] = str_repeat('x', 300);
        $title = (new MessageTitleComposer(null))->compose($canonical, 'invoiceReceived');
        $this->assertSame(MessageTitleComposer::MAX_LENGTH, mb_strlen((string) $title));
    }

    public function testFormatAmount(): void
    {
        $this->assertSame('13 105 CZK', MessageTitleComposer::formatAmount(13105.00, 'CZK'));
        $this->assertSame('1 234,50 EUR', MessageTitleComposer::formatAmount(1234.5, 'eur'));
        $this->assertSame('-0,05 CZK', MessageTitleComposer::formatAmount(-0.05, 'CZK'));
        $this->assertSame('500', MessageTitleComposer::formatAmount('500', null));
        $this->assertSame('500', MessageTitleComposer::formatAmount(500, '  '));
        $this->assertNull(MessageTitleComposer::formatAmount('abc', 'CZK'));
        $this->assertNull(MessageTitleComposer::formatAmount(null, 'CZK'));
    }

    // ── forDataSource: jazyk labelu = jazyk AI profilu ──────────────────────

    /** Dočasný DS adresář s main.json (defaultLanguage en) a compiled configy cs/en. */
    private function makeDsDir(bool $withCompiled = true): string
    {
        $dir = sys_get_temp_dir() . '/shpd_title_' . bin2hex(random_bytes(6));
        mkdir($dir . '/config/configuration', 0700, true);
        file_put_contents($dir . '/config/main.json', json_encode([
            'id' => 'test-test-test-test',
            'name' => 'Title Test',
            'database_name' => 'test_db',
            'database_user' => 'test',
            'database_password' => 'pw',
            'created' => date('c'),
            'defaultLanguage' => 'en',
        ]));
        if ($withCompiled) {
            foreach (['cs' => 'Přijatá faktura', 'en' => 'Received invoice'] as $lang => $label) {
                file_put_contents($dir . "/config/configuration/compiled.{$lang}.json", json_encode([
                    'items' => ['core.mail.primaryTypes' => ['invoiceReceived' => ['name' => $label, 'target' => 'docs']]],
                ]));
            }
        }
        return $dir;
    }

    private function removeDir(string $dir): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    private function dbWithProfileLanguage(?string $language): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn($language !== null ? ['language' => $language] : null);
        return $db;
    }

    public function testForDataSourceUsesProfileLanguageNotDsDefault(): void
    {
        // Nález z alfy: ISDOC titulek „Received invoice" — DS default en,
        // profil cs. Titulek je v jazyce profilu jako titulek od AI (D2).
        $dir = $this->makeDsDir();
        try {
            $composer = MessageTitleComposer::forDataSource($this->dbWithProfileLanguage('cs'), new DataSourceConfig($dir));
            $this->assertSame(
                'Přijatá faktura FV-2026-0042 — Dodavatel s.r.o., 13 105 CZK',
                $composer->compose($this->docsCanonical(), 'invoiceReceived'),
            );
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testForDataSourceFallsBackToDsDefaultLanguageWithoutProfile(): void
    {
        $dir = $this->makeDsDir();
        try {
            $composer = MessageTitleComposer::forDataSource($this->dbWithProfileLanguage(null), new DataSourceConfig($dir));
            $this->assertSame(
                'Received invoice FV-2026-0042 — Dodavatel s.r.o., 13 105 CZK',
                $composer->compose($this->docsCanonical(), 'invoiceReceived'),
            );
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testForDataSourceWithoutCompiledConfigOmitsLabel(): void
    {
        $dir = $this->makeDsDir(withCompiled: false);
        try {
            $composer = MessageTitleComposer::forDataSource($this->dbWithProfileLanguage('cs'), new DataSourceConfig($dir));
            $this->assertSame(
                'FV-2026-0042 — Dodavatel s.r.o., 13 105 CZK',
                $composer->compose($this->docsCanonical(), 'invoiceReceived'),
            );
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testProfileLanguageRoutesRunProfileVsDefaultProfile(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            static fn(mixed ...$args): ?array => str_contains((string) $args[0], 'is_default')
                ? ['language' => 'cs']
                : ['language' => 'de'],
        );
        $this->assertSame('de', MessageTitleComposer::profileLanguage($db, 7), 'profil běhu');
        $this->assertSame('cs', MessageTitleComposer::profileLanguage($db, null), 'výchozí profil DS');
        $this->assertSame('cs', MessageTitleComposer::profileLanguage($db, 0));

        $empty = $this->createMock(DataSourceConnection::class);
        $empty->method('fetchRow')->willReturn(['language' => '  ']);
        $this->assertNull(MessageTitleComposer::profileLanguage($empty, null));

        $down = $this->createMock(DataSourceConnection::class);
        $down->method('fetchRow')->willThrowException(new \RuntimeException('DB down'));
        $this->assertNull(MessageTitleComposer::profileLanguage($down, 7));
    }

    public function testClean(): void
    {
        $this->assertSame('Faktura 2026-0042', MessageTitleComposer::clean("  Faktura \n 2026-0042 "));
        $this->assertNull(MessageTitleComposer::clean('   '));
        $this->assertNull(MessageTitleComposer::clean(12));
        $this->assertSame(200, mb_strlen((string) MessageTitleComposer::clean(str_repeat('ř', 250))));
    }
}
