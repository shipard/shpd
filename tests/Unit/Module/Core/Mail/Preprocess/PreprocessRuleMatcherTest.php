<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Preprocess;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Core\Mail\Preprocess\PreprocessRuleMatcher;

/**
 * Matcher pravidel předzpracování — AND sémantika, nepovinný odesílatel,
 * case-insensitive regexy, forward vzorek přes body_regex, sjednocený
 * plán z více pravidel, obrana proti nevalidnímu regexu / JSON akcí.
 */
class PreprocessRuleMatcherTest extends TestCase
{
    private const ACTIONS = '[{"action":"fetchLinkedDocument","linkHrefRegex":"invoice\\\\.example\\\\.com","allowedDomains":["example.com"]}]';

    protected function setUp(): void
    {
        ErrorLogger::resetForTesting();
        ErrorLogger::setLogPath(sys_get_temp_dir() . '/shpd_preprocess_matcher_test.log');
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
        @unlink(sys_get_temp_dir() . '/shpd_preprocess_matcher_test.log');
    }

    /** @return array<string, mixed> */
    private function rule(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'rule_id' => 'test-rule',
            'sender_email' => null,
            'sender_domain' => null,
            'subject_regex' => null,
            'body_regex' => null,
            'actions' => self::ACTIONS,
        ], $overrides);
    }

    public function testBodyRegexAloneMatchesForwardedSample(): void
    {
        // Vzorek z alfy: Fwd: od interního uživatele, odkaz na fakturu v těle.
        $body = '<html><body>Fwd: <a href="https://awstrack.me/L0/https:%2F%2Finvoice.bolt.eu%2Fabc">Download invoice</a></body></html>';

        $plan = PreprocessRuleMatcher::plan(
            [$this->rule(['rule_id' => 'bolt-invoice-link', 'body_regex' => 'invoice\.bolt\.eu'])],
            'kolega@firma.example',
            'Fwd: Your Bolt receipt',
            $body,
            null,
        );

        $this->assertNotNull($plan);
        $this->assertCount(1, $plan);
        $this->assertSame('bolt-invoice-link', $plan[0]['ruleId']);
        $this->assertSame(1, $plan[0]['ruleNdx']);
        $this->assertSame('fetchLinkedDocument', $plan[0]['actions'][0]['action']);
    }

    public function testBodyRegexUsesUrlEncodedBodyToo(): void
    {
        // Body regex se vyhodnocuje nad HTML i plain textem — stačí shoda v jednom.
        $plan = PreprocessRuleMatcher::plan(
            [$this->rule(['body_regex' => 'invoice\.bolt\.eu'])],
            'a@b.example',
            'x',
            null,
            'Plain text only: https://invoice.bolt.eu/xyz',
        );

        $this->assertNotNull($plan);
    }

    public function testConditionsAreCombinedWithAnd(): void
    {
        $rule = $this->rule(['sender_domain' => 'bolt.eu', 'subject_regex' => 'receipt']);

        $this->assertTrue(PreprocessRuleMatcher::ruleMatches($rule, 'no-reply@bolt.eu', 'Your receipt', ''));
        $this->assertFalse(PreprocessRuleMatcher::ruleMatches($rule, 'no-reply@bolt.eu', 'Newsletter', ''));
        $this->assertFalse(PreprocessRuleMatcher::ruleMatches($rule, 'no-reply@other.example', 'Your receipt', ''));
    }

    public function testSenderEmailIsCaseInsensitiveExactMatch(): void
    {
        $rule = $this->rule(['sender_email' => 'Billing@Example.COM']);

        $this->assertTrue(PreprocessRuleMatcher::ruleMatches($rule, '  billing@example.com ', '', ''));
        $this->assertFalse(PreprocessRuleMatcher::ruleMatches($rule, 'other-billing@example.com', '', ''));
    }

    public function testSenderDomainMatchesSubdomains(): void
    {
        $rule = $this->rule(['sender_domain' => 'example.com']);

        $this->assertTrue(PreprocessRuleMatcher::ruleMatches($rule, 'a@example.com', '', ''));
        $this->assertTrue(PreprocessRuleMatcher::ruleMatches($rule, 'a@mail.example.com', '', ''));
        $this->assertFalse(PreprocessRuleMatcher::ruleMatches($rule, 'a@notexample.com', '', ''));
    }

    public function testRegexesAreCaseInsensitive(): void
    {
        $rule = $this->rule(['subject_regex' => '^faktura']);

        $this->assertTrue(PreprocessRuleMatcher::ruleMatches($rule, 'x@y.example', 'FAKTURA 2026/001', ''));
        $this->assertTrue(PreprocessRuleMatcher::ruleMatches($rule, 'x@y.example', 'Faktura č. 1', ''));
    }

    public function testRuleWithoutAnyConditionNeverMatches(): void
    {
        $this->assertFalse(PreprocessRuleMatcher::ruleMatches($this->rule(), 'x@y.example', 'anything', 'anything'));
        $this->assertNull(PreprocessRuleMatcher::plan([$this->rule()], 'x@y.example', 'anything', 'anything', null));
    }

    public function testMultipleMatchingRulesProduceUnionPlanInIdOrder(): void
    {
        $rules = [
            $this->rule(['id' => 7, 'rule_id' => 'second', 'subject_regex' => 'invoice']),
            $this->rule(['id' => 3, 'rule_id' => 'first', 'sender_domain' => 'example.com', 'actions' => '[{"action":"renderBodyToPdf"}]']),
            $this->rule(['id' => 9, 'rule_id' => 'nope', 'sender_email' => 'someone-else@example.com']),
        ];

        $plan = PreprocessRuleMatcher::plan($rules, 'billing@example.com', 'Invoice 42', '', '');

        $this->assertNotNull($plan);
        // Pořadí = pořadí vstupu (DB řadí dle id ASC), ne řazení v plan().
        $this->assertSame(['second', 'first'], array_column($plan, 'ruleId'));
        $this->assertSame('renderBodyToPdf', $plan[1]['actions'][0]['action']);
    }

    public function testInvalidRegexSkipsRuleWithoutThrowing(): void
    {
        $rules = [
            $this->rule(['id' => 1, 'rule_id' => 'broken', 'subject_regex' => '(unclosed']),
            $this->rule(['id' => 2, 'rule_id' => 'fine', 'subject_regex' => 'invoice']),
        ];

        $plan = PreprocessRuleMatcher::plan($rules, 'a@b.example', 'invoice', '', '');

        $this->assertNotNull($plan);
        $this->assertSame(['fine'], array_column($plan, 'ruleId'));
        $this->assertNotNull(PreprocessRuleMatcher::compileError('(unclosed'));
        $this->assertNull(PreprocessRuleMatcher::compileError('invoice\.bolt\.eu'));
    }

    public function testInvalidUtf8InBodyStillMatches(): void
    {
        $body = "Faktura \xC3\x28 invoice.bolt.eu"; // rozbité UTF-8

        $this->assertTrue(PreprocessRuleMatcher::regexMatches('invoice\.bolt\.eu', $body));
    }

    public function testTildeInPatternIsEscapedNotTreatedAsDelimiter(): void
    {
        $this->assertTrue(PreprocessRuleMatcher::regexMatches('a~b', 'xxA~Bxx'));
    }

    public function testRuleWithBrokenActionsJsonIsSkipped(): void
    {
        $rules = [
            $this->rule(['id' => 1, 'rule_id' => 'bad-json', 'subject_regex' => 'x', 'actions' => '{not json']),
            $this->rule(['id' => 2, 'rule_id' => 'empty', 'subject_regex' => 'x', 'actions' => '[]']),
            $this->rule(['id' => 3, 'rule_id' => 'no-action-key', 'subject_regex' => 'x', 'actions' => '[{"foo":1}]']),
        ];

        $this->assertNull(PreprocessRuleMatcher::plan($rules, 'a@b.example', 'x', '', ''));
    }

    public function testMatchQueriesOnlyConfirmedRules(): void
    {
        $captured = [];
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetchAll')->willReturnCallback(function (...$args) use (&$captured): array {
            $captured = $args;
            return [new \Dibi\Row($this->rule(['subject_regex' => 'invoice']))];
        });

        $plan = new PreprocessRuleMatcher($db)->match('a@b.example', 'Invoice', null, null);

        $this->assertStringContainsString('docState = %i', (string) $captured[0]);
        $this->assertContains(40, $captured);
        $this->assertContains('core_mail_preprocess_rules', $captured);
        $this->assertNotNull($plan);
        $this->assertSame('test-rule', $plan[0]['ruleId']);
    }
}
