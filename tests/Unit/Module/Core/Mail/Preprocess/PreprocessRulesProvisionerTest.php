<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Preprocess;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\Preprocess\PreprocessRuleMatcher;
use Shipard\Module\Core\Mail\Preprocess\PreprocessRulesProvisioner;

/**
 * Provisioner systémových pravidel: idempotentní upsert dle rule_id,
 * Fáze 2 pravidla vznikají archivovaná, archivované se nekřísí, obsah se
 * aktualizuje jen ve stavu 40; validace repo katalogu.
 */
class PreprocessRulesProvisionerTest extends TestCase
{
    private ?string $catalog = null;

    /** @var list<array{table: string, data: array<string, mixed>}> */
    private array $inserts = [];
    /** @var list<array{data: array<string, mixed>, id: int}> */
    private array $updates = [];

    protected function tearDown(): void
    {
        if ($this->catalog !== null) {
            @unlink($this->catalog);
            $this->catalog = null;
        }
    }

    /** @param list<array<string, mixed>> $rules */
    private function catalog(array $rules): string
    {
        $this->catalog = sys_get_temp_dir() . '/shpd_pp_catalog_' . uniqid() . '.jsonc';
        file_put_contents($this->catalog, json_encode(['rules' => $rules], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $this->catalog;
    }

    /** @param array<string, array<string, mixed>|null> $rowsByRuleId */
    private function db(array $rowsByRuleId): DataSourceConnection
    {
        $this->inserts = [];
        $this->updates = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            static fn(...$args): ?array => $rowsByRuleId[(string) end($args)] ?? null,
        );
        $db->method('insertRow')->willReturnCallback(function (string $table, array $data): int {
            $this->inserts[] = ['table' => $table, 'data' => $data];
            return count($this->inserts);
        });
        $db->method('updateWhere')->willReturnCallback(function (string $table, array $data, string $where, ...$params): void {
            $this->updates[] = ['data' => $data, 'id' => (int) $params[0]];
        });
        return $db;
    }

    /** @return array<string, mixed> */
    private function boltRule(array $overrides = []): array
    {
        return array_merge([
            'rule_id' => 'bolt-invoice-link',
            'notice' => 'Bolt',
            'body_regex' => 'invoice\.bolt\.eu',
            'actions' => [['action' => 'fetchLinkedDocument', 'linkHrefRegex' => 'invoice\.bolt\.eu', 'allowedDomains' => ['bolt.eu']]],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function boltRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 7,
            'docState' => 40,
            'origin' => 'system',
            'system_phase' => 1,
            'sender_email' => null,
            'sender_domain' => null,
            'subject_regex' => null,
            'body_regex' => 'invoice\.bolt\.eu',
            'actions' => '[{"action":"fetchLinkedDocument","linkHrefRegex":"invoice\\\\.bolt\\\\.eu","allowedDomains":["bolt.eu"]}]',
            'notice' => 'Bolt',
        ], $overrides);
    }

    public function testFreshDsCreatesLiveAndArchivedRules(): void
    {
        $path = $this->catalog([
            $this->boltRule(),
            ['rule_id' => 'apple-invoice-body', 'phase' => 2, 'body_regex' => 'apple', 'actions' => [['action' => 'renderBodyToPdf']]],
        ]);

        $result = new PreprocessRulesProvisioner($this->db([]), $path)->provision();

        $this->assertSame(['bolt-invoice-link', 'apple-invoice-body'], $result['created']);
        $this->assertCount(2, $this->inserts);

        $bolt = $this->inserts[0]['data'];
        $this->assertSame('core_mail_preprocess_rules', $this->inserts[0]['table']);
        $this->assertSame('system', $bolt['origin']);
        $this->assertSame(40, $bolt['docState']);
        $this->assertSame(3, $bolt['docStateMain']);
        $this->assertSame(0, $bolt['hit_count']);
        $this->assertNull($bolt['sender_email']);
        $this->assertSame('invoice\.bolt\.eu', $bolt['body_regex']);
        $this->assertSame('fetchLinkedDocument', json_decode($bolt['actions'], true)[0]['action']);

        $this->assertSame(1, $bolt['system_phase']);

        $apple = $this->inserts[1]['data'];
        $this->assertSame(70, $apple['docState'], 'Fáze 2 pravidlo vzniká archivované');
        $this->assertSame(4, $apple['docStateMain']);
        $this->assertSame(2, $apple['system_phase']);
    }

    // --- aktivace fáze-2 pravidel (D14) ------------------------------------

    /** @return array<string, mixed> */
    private function appleRule(array $overrides = []): array
    {
        return array_merge([
            'rule_id' => 'apple-invoice-body',
            'notice' => 'Apple',
            'body_regex' => 'Apple Distribution International',
            'actions' => [['action' => 'renderBodyToPdf']],
        ], $overrides);
    }

    /** Řádek založený dřívějším ds-upgrade z katalogu s phase 2 (archivovaný, stará kotva). */
    private function archivedAppleRow(array $overrides = []): array
    {
        return array_merge($this->boltRow([
            'id' => 9,
            'docState' => 70,
            'system_phase' => 2,
            'body_regex' => 'no_reply@email\.apple\.com',
            'actions' => '[{"action":"renderBodyToPdf"}]',
            'notice' => 'Apple (Fáze 2)',
        ]), $overrides);
    }

    public function testCatalogPhaseFlipActivatesSystemArchivedRule(): void
    {
        $path = $this->catalog([$this->appleRule()]);

        $result = new PreprocessRulesProvisioner($this->db(['apple-invoice-body' => $this->archivedAppleRow()]), $path)->provision();

        $this->assertSame(['apple-invoice-body'], $result['activated']);
        $this->assertSame([], $result['skipped']);
        $this->assertCount(1, $this->updates);
        $this->assertSame(9, $this->updates[0]['id']);
        $data = $this->updates[0]['data'];
        $this->assertSame(40, $data['docState']);
        $this->assertSame(3, $data['docStateMain']);
        $this->assertSame(1, $data['system_phase']);
        $this->assertSame('Apple Distribution International', $data['body_regex'], 'obsah se přebírá z katalogu (re-kotvení)');
        $this->assertSame('Apple', $data['notice']);
        foreach (['hit_count', 'last_hit_at', 'created', 'origin', 'rule_id'] as $untouched) {
            $this->assertArrayNotHasKey($untouched, $data, $untouched);
        }
    }

    public function testUserArchivedRuleWithPhaseOneIsNotResurrected(): void
    {
        $path = $this->catalog([$this->appleRule()]);
        $row = $this->archivedAppleRow(['system_phase' => 1]);

        $result = new PreprocessRulesProvisioner($this->db(['apple-invoice-body' => $row]), $path)->provision();

        $this->assertSame(['apple-invoice-body'], $result['skipped']);
        $this->assertSame([], $this->updates);
    }

    public function testArchivedRuleStaysArchivedWhileCatalogIsStillPhaseTwo(): void
    {
        $path = $this->catalog([$this->appleRule(['phase' => 2])]);

        $result = new PreprocessRulesProvisioner($this->db(['apple-invoice-body' => $this->archivedAppleRow()]), $path)->provision();

        $this->assertSame(['apple-invoice-body'], $result['skipped']);
        $this->assertSame([], $this->updates);
    }

    public function testUserOriginArchivedRuleIsNeverActivated(): void
    {
        $path = $this->catalog([$this->appleRule()]);
        $row = $this->archivedAppleRow(['origin' => 'user']);

        $result = new PreprocessRulesProvisioner($this->db(['apple-invoice-body' => $row]), $path)->provision();

        $this->assertSame(['apple-invoice-body'], $result['skipped']);
        $this->assertSame([], $this->updates);
    }

    public function testRunAfterActivationIsUnchanged(): void
    {
        $path = $this->catalog([$this->appleRule()]);
        $row = $this->boltRow([
            'id' => 9,
            'system_phase' => 1,
            'body_regex' => 'Apple Distribution International',
            'actions' => '[{"action":"renderBodyToPdf"}]',
            'notice' => 'Apple',
        ]);

        $result = new PreprocessRulesProvisioner($this->db(['apple-invoice-body' => $row]), $path)->provision();

        $this->assertSame(['apple-invoice-body'], $result['unchanged']);
        $this->assertSame([], $this->updates);
    }

    public function testConfirmedRuleWithStalePhaseIsAlignedWithoutStateChange(): void
    {
        $path = $this->catalog([$this->boltRule()]);
        $row = $this->boltRow(['system_phase' => 2]);

        $result = new PreprocessRulesProvisioner($this->db(['bolt-invoice-link' => $row]), $path)->provision();

        $this->assertSame(['bolt-invoice-link'], $result['updated']);
        $this->assertCount(1, $this->updates);
        $data = $this->updates[0]['data'];
        $this->assertSame(1, $data['system_phase']);
        $this->assertArrayHasKey('modified', $data);
        $this->assertArrayNotHasKey('docState', $data);
        $this->assertArrayNotHasKey('body_regex', $data, 'obsah shodný — dorovnává se jen fáze');
    }

    public function testSecondRunIsNoOp(): void
    {
        $path = $this->catalog([$this->boltRule()]);

        $result = new PreprocessRulesProvisioner($this->db(['bolt-invoice-link' => $this->boltRow()]), $path)->provision();

        $this->assertSame(['bolt-invoice-link'], $result['unchanged']);
        $this->assertSame([], $this->inserts);
        $this->assertSame([], $this->updates);
    }

    public function testChangedCatalogUpdatesConfirmedRuleContentOnly(): void
    {
        $path = $this->catalog([$this->boltRule(['notice' => 'Bolt v2', 'subject_regex' => 'receipt'])]);

        $result = new PreprocessRulesProvisioner($this->db(['bolt-invoice-link' => $this->boltRow()]), $path)->provision();

        $this->assertSame(['bolt-invoice-link'], $result['updated']);
        $this->assertCount(1, $this->updates);
        $this->assertSame(7, $this->updates[0]['id']);
        $data = $this->updates[0]['data'];
        $this->assertSame('Bolt v2', $data['notice']);
        $this->assertSame('receipt', $data['subject_regex']);
        $this->assertArrayHasKey('modified', $data);
        foreach (['hit_count', 'last_hit_at', 'created', 'docState', 'origin', 'rule_id'] as $untouched) {
            $this->assertArrayNotHasKey($untouched, $data, $untouched);
        }
    }

    public function testArchivedTrashedAndDraftRulesAreNotTouched(): void
    {
        $path = $this->catalog([
            $this->boltRule(['rule_id' => 'archived-rule']),
            $this->boltRule(['rule_id' => 'trashed-rule']),
            $this->boltRule(['rule_id' => 'draft-rule']),
        ]);
        $db = $this->db([
            'archived-rule' => $this->boltRow(['docState' => 70, 'notice' => 'old']),
            'trashed-rule' => $this->boltRow(['docState' => 90, 'notice' => 'old']),
            'draft-rule' => $this->boltRow(['docState' => 10, 'notice' => 'old']),
        ]);

        $result = new PreprocessRulesProvisioner($db, $path)->provision();

        $this->assertSame(['archived-rule', 'trashed-rule', 'draft-rule'], $result['skipped']);
        $this->assertSame([], $this->inserts, 'archivované se nekřísí novým řádkem');
        $this->assertSame([], $this->updates);
    }

    public function testActionsComparisonIgnoresJsonFormatting(): void
    {
        $path = $this->catalog([$this->boltRule()]);
        $row = $this->boltRow(['actions' => json_encode(
            [['allowedDomains' => ['bolt.eu'], 'action' => 'fetchLinkedDocument', 'linkHrefRegex' => 'invoice\.bolt\.eu']],
            JSON_PRETTY_PRINT,
        )]);

        $result = new PreprocessRulesProvisioner($this->db(['bolt-invoice-link' => $row]), $path)->provision();

        $this->assertSame(['bolt-invoice-link'], $result['unchanged']);
    }

    public function testRepoCatalogIsValidAndMatchesSamples(): void
    {
        $rules = PreprocessRulesProvisioner::loadCatalog();
        $byId = array_column($rules, null, 'rule_id');

        $this->assertArrayHasKey('bolt-invoice-link', $byId);
        foreach (['bolt-invoice-link', 'apple-invoice-body', 'google-play-order'] as $live) {
            $this->assertArrayNotHasKey('phase', $byId[$live], "{$live} je živé pravidlo");
        }

        // Apple: přímá pošta, adresa odesílatele v těle není — kotví právnická osoba (D15).
        $appleBody = '<table><tr><td>Apple Distribution International Ltd.</td></tr><tr><td>Faktura</td></tr></table>';
        $plan = PreprocessRuleMatcher::plan([$byId['apple-invoice-body'] + ['id' => 2]], 'no_reply@email.apple.com', 'Vaše faktura od Apple', $appleBody, null);
        $this->assertNotNull($plan);
        $this->assertSame('renderBodyToPdf', $plan[0]['actions'][0]['action']);
        $this->assertNull(
            PreprocessRuleMatcher::plan([$byId['apple-invoice-body'] + ['id' => 2]], 'x@y.example', 'Fwd: Apple newsletter', '<p>New in the App Store</p>', null),
            'marketing bez fakturační právnické osoby nematchne',
        );

        // Forward Bolt vzorku: odkaz zabalený v awstrack redirectu, odesílatel interní.
        $body = '<a href="https://awstrack.me/L0/https:%2F%2Finvoice.bolt.eu%2Fdl%2Fabc/1/x">Download invoice</a>';
        $plan = PreprocessRuleMatcher::plan([$byId['bolt-invoice-link'] + ['id' => 1]], 'kolega@firma.example', 'Fwd: Your Bolt receipt', $body, null);
        $this->assertNotNull($plan);
        $this->assertSame('fetchLinkedDocument', $plan[0]['actions'][0]['action']);
    }

    public function testCatalogValidationRejectsBrokenRules(): void
    {
        $cases = [
            'missing rule_id' => [['body_regex' => 'x', 'actions' => [['action' => 'a']]]],
            'bad rule_id chars' => [['rule_id' => 'Bad_Id', 'body_regex' => 'x', 'actions' => [['action' => 'a']]]],
            'duplicate' => [$this->boltRule(), $this->boltRule()],
            'no condition' => [['rule_id' => 'no-cond', 'actions' => [['action' => 'a']]]],
            'invalid regex' => [['rule_id' => 'bad-regex', 'body_regex' => '(', 'actions' => [['action' => 'a']]]],
            'no actions' => [['rule_id' => 'no-actions', 'body_regex' => 'x', 'actions' => []]],
            'phase zero' => [['rule_id' => 'phase-zero', 'body_regex' => 'x', 'phase' => 0, 'actions' => [['action' => 'a']]]],
            'phase string' => [['rule_id' => 'phase-str', 'body_regex' => 'x', 'phase' => '2', 'actions' => [['action' => 'a']]]],
        ];

        foreach ($cases as $label => $rules) {
            $path = $this->catalog($rules);
            try {
                PreprocessRulesProvisioner::loadCatalog($path);
                $this->fail("expected exception for {$label}");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('systemPreprocessRules.jsonc', $e->getMessage(), $label);
            }
            @unlink($path);
        }
        $this->catalog = null;
    }
}
