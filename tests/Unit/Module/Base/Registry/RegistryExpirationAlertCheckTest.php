<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Registry;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Alerts\AlertFinding;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Base\Registry\RegistryExpirationAlertCheck;

class RegistryExpirationAlertCheckTest extends TestCase
{
    public const TODAY = '2026-03-10';

    private const DOC_KINDS = [
        'contract'  => ['name' => 'Smlouva', 'expiration' => ['warnDaysBefore' => [30, 7]]],
        'quotation' => ['name' => 'Cenová nabídka', 'expiration' => ['warnDaysBefore' => [7]]],
        'other'     => ['name' => 'Ostatní', 'expiration' => null],
    ];

    /**
     * @param array<int, array<string, mixed>> $stubRows
     * @param array<int, mixed>|null $capturedArgs
     */
    private function makeCheck(
        array $stubRows,
        string $language = 'cs',
        ?array &$capturedArgs = null,
        array $docKinds = self::DOC_KINDS,
    ): RegistryExpirationAlertCheck {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            function (...$args) use ($stubRows, &$capturedArgs) {
                $capturedArgs = $args;
                return $stubRows;
            },
        );

        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            fn (string $id) => $id === 'base.registry.docKinds' ? $docKinds : null,
        );

        return new class($db, $config, $language) extends RegistryExpirationAlertCheck {
            protected function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable(RegistryExpirationAlertCheckTest::TODAY . ' 14:30:00');
            }
        };
    }

    /** @return array<string, mixed> */
    private function row(string $validTo, string $kind = 'contract', int $id = 1): array
    {
        return [
            'id'           => $id,
            'title'        => 'Nájemní smlouva',
            'doc_kind'     => $kind,
            'valid_to'     => $validTo,
            'partner_name' => null,
        ];
    }

    public function testKindsWithoutExpirationExcludedFromQuery(): void
    {
        $captured = null;
        $check = $this->makeCheck([], 'cs', $captured);
        $check->run();

        $this->assertNotNull($captured);
        $this->assertSame([40, 80], $captured[1]);
        $this->assertSame(['contract', 'quotation'], $captured[2]);
        // horizont = today + max(warnDaysBefore přes druhy) = +30 dní
        $this->assertSame('2026-04-09', $captured[3]);
    }

    public function testNoExpirationKindsSkipsQueryEntirely(): void
    {
        $captured = null;
        $check = $this->makeCheck([], 'cs', $captured, ['other' => ['name' => 'Ostatní', 'expiration' => null]]);
        $this->assertSame([], $check->run());
        $this->assertNull($captured);
    }

    public function testExpiredDocumentIsError(): void
    {
        $check = $this->makeCheck([$this->row('2026-03-01', 'contract', 42)]);
        $findings = $check->run();

        $this->assertCount(1, $findings);
        $f = $findings[0];
        $this->assertInstanceOf(AlertFinding::class, $f);
        $this->assertSame('error', $f->severity);
        $this->assertSame(-9, $f->context['days']);
        $this->assertSame('2026-03-01', $f->context['valid_to']);
        $this->assertSame('contract', $f->context['doc_kind']);
        $this->assertStringContainsString('před 9 dny', $f->message);
        $this->assertStringContainsString('1. 3. 2026', $f->message);
    }

    public function testWithinMinWarnDaysIsWarning(): void
    {
        // contract min(warnDaysBefore) = 7; 5 dní do termínu
        $check = $this->makeCheck([$this->row('2026-03-15')]);
        $f = $check->run()[0];
        $this->assertSame('warning', $f->severity);
        $this->assertSame(5, $f->context['days']);
        $this->assertStringContainsString('za 5 dní', $f->message);
    }

    public function testBetweenMinAndMaxWarnDaysIsInfo(): void
    {
        // contract: 20 dní — nad min 7, pod max 30
        $check = $this->makeCheck([$this->row('2026-03-30')]);
        $f = $check->run()[0];
        $this->assertSame('info', $f->severity);
        $this->assertSame(20, $f->context['days']);
    }

    public function testExpiresTodayIsWarning(): void
    {
        $check = $this->makeCheck([$this->row(self::TODAY)]);
        $f = $check->run()[0];
        $this->assertSame('warning', $f->severity);
        $this->assertSame(0, $f->context['days']);
        $this->assertStringContainsString('končí dnes', $f->message);
    }

    public function testRowBeyondItsKindHorizonIsSkipped(): void
    {
        // quotation max = 7; 20 dní je v globálním horizontu (30), ale mimo horizont druhu
        $check = $this->makeCheck([$this->row('2026-03-30', 'quotation')]);
        $this->assertSame([], $check->run());
    }

    public function testFindingKeyAndSubjectAndAction(): void
    {
        $check = $this->makeCheck([$this->row('2026-03-15', 'contract', 42)]);
        $f = $check->run()[0];

        $this->assertSame('doc_42', $f->findingKey);
        $this->assertSame(428, $f->subjectTableId);
        $this->assertSame(42, $f->subjectRowId);

        $this->assertCount(1, $f->actions);
        $action = $f->actions[0];
        $this->assertSame('open_doc', $action['id']);
        $this->assertSame('open_form', $action['kind']);
        $this->assertTrue($action['primary']);
        $this->assertSame('base_registry_documents', $action['target']['table']);
        $this->assertSame('edit', $action['target']['mode']);
        $this->assertSame(42, $action['target']['id']);
    }

    public function testFindingKeyStableAcrossSeverities(): void
    {
        // stejný dokument v info, warning i error pásmu → stejný finding_key
        $keys = [];
        foreach (['2026-03-30', '2026-03-15', '2026-03-01'] as $validTo) {
            $check = $this->makeCheck([$this->row($validTo, 'contract', 7)]);
            $keys[] = $check->run()[0]->findingKey;
        }
        $this->assertSame(['doc_7', 'doc_7', 'doc_7'], $keys);
    }

    public function testTitleUsesLocalizedKindLabel(): void
    {
        $check = $this->makeCheck([$this->row('2026-03-15')]);
        $this->assertSame('Smlouva: Nájemní smlouva', $check->run()[0]->title);
    }

    public function testPartnerAppearsInMessage(): void
    {
        $row = $this->row('2026-03-15');
        $row['partner_name'] = 'Acme s.r.o.';
        $check = $this->makeCheck([$row]);
        $this->assertStringContainsString('Partner: Acme s.r.o.', $check->run()[0]->message);
    }

    public function testEnglishLocalization(): void
    {
        $check = $this->makeCheck([$this->row('2026-03-15')], 'en');
        $f = $check->run()[0];
        $this->assertStringContainsString('Expires in 5 days (2026-03-15)', $f->message);
        $this->assertSame('Open document', $f->actions[0]['label']);

        $expired = $this->makeCheck([$this->row('2026-03-09')], 'en');
        $this->assertStringContainsString('Expired 1 day ago', $expired->run()[0]->message);
    }

    public function testCzechDayForms(): void
    {
        $cases = [
            ['2026-03-11', 'za 1 den'],
            ['2026-03-12', 'za 2 dny'],
            ['2026-03-15', 'za 5 dní'],
            ['2026-03-09', 'před 1 dnem'],
            ['2026-03-05', 'před 5 dny'],
        ];
        foreach ($cases as [$validTo, $expected]) {
            $check = $this->makeCheck([$this->row($validTo)]);
            $message = $check->run()[0]->message;
            $this->assertStringContainsString($expected, $message, "valid_to={$validTo}");
        }
    }

    public function testAcceptsDibiDateTimeValidTo(): void
    {
        $check = $this->makeCheck([$this->row('x', 'contract', 3)]);
        // přepiš valid_to na Dibi\DateTime (date sloupce tak z Dibi chodí)
        $row = $this->row('2026-03-15', 'contract', 3);
        $row['valid_to'] = new \Dibi\DateTime('2026-03-15');
        $check = $this->makeCheck([$row]);
        $f = $check->run()[0];
        $this->assertSame(5, $f->context['days']);
        $this->assertSame('2026-03-15', $f->context['valid_to']);
    }
}
