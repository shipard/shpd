<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Preprocess;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Document\ValidationError;
use Shipard\Module\Core\Mail\Preprocess\PreprocessRuleDocument;

/**
 * Validace a beforeSave PreprocessRuleDocument: aspoň jedna podmínka,
 * formáty, regexy, akce (jen implementované + povinné parametry),
 * rule_id formát/unikátnost/generování, normalizace.
 */
class PreprocessRuleDocumentTest extends TestCase
{
    private const ACTIONS = '[{"action":"fetchLinkedDocument","linkHrefRegex":"invoice\\\\.bolt\\\\.eu","allowedDomains":["bolt.eu"]}]';

    private function doc(?\Dibi\Row $duplicate = null, bool $withDb = false): PreprocessRuleDocument
    {
        $doc = new PreprocessRuleDocument();
        if ($withDb) {
            $db = $this->createMock(\Dibi\Connection::class);
            $db->method('fetch')->willReturn($duplicate);
            $doc->setDb($db);
        }
        return $doc;
    }

    /** @return array<string, mixed> */
    private function valid(array $overrides = []): array
    {
        return array_merge(['body_regex' => 'invoice\.bolt\.eu', 'actions' => self::ACTIONS], $overrides);
    }

    /** @return list<array{column: string, code: string}> */
    private function errors(\Shipard\Core\Document\ValidationResult $result): array
    {
        return array_map(static fn(array $e): array => ['column' => $e['column'], 'code' => $e['code']], $result->toArray());
    }

    public function testValidRuleWithBodyRegexOnly(): void
    {
        $data = $this->valid();

        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testAtLeastOneMatchConditionIsRequiredAsFormLevelError(): void
    {
        $data = ['actions' => self::ACTIONS, 'sender_email' => '  '];

        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains(['column' => ValidationError::FIELD_FORM, 'code' => 'match_required'], $this->errors($result));
    }

    public function testSenderEmailAndDomainFormats(): void
    {
        $data = $this->valid(['sender_email' => 'not-an-email']);
        $this->assertContains(['column' => 'sender_email', 'code' => 'invalid_format'], $this->errors($this->doc()->validate($data)));

        $data = $this->valid(['sender_domain' => 'someone@bolt.eu']);
        $this->assertContains(['column' => 'sender_domain', 'code' => 'invalid_format'], $this->errors($this->doc()->validate($data)));

        $data = $this->valid(['sender_domain' => 'localhost']);
        $this->assertContains(['column' => 'sender_domain', 'code' => 'invalid_format'], $this->errors($this->doc()->validate($data)));

        $data = $this->valid(['sender_email' => 'Billing@Bolt.EU', 'sender_domain' => '@Bolt.eu']);
        $this->assertTrue($this->doc()->validate($data)->isValid());
        $this->assertSame('billing@bolt.eu', $data['sender_email'], 'validate normalizuje lowercase');
        $this->assertSame('bolt.eu', $data['sender_domain']);
    }

    public function testInvalidRegexesAreRejected(): void
    {
        $data = $this->valid(['subject_regex' => '(unclosed', 'body_regex' => '[']);

        $errors = $this->errors($this->doc()->validate($data));

        $this->assertContains(['column' => 'subject_regex', 'code' => 'invalid_regex'], $errors);
        $this->assertContains(['column' => 'body_regex', 'code' => 'invalid_regex'], $errors);
    }

    public function testActionsMustBeJsonListOfActions(): void
    {
        foreach (['{not json', '[]', '[{"foo":1}]', '{"action":"fetchLinkedDocument"}', null] as $broken) {
            $data = $this->valid(['actions' => $broken]);
            $this->assertContains(['column' => 'actions', 'code' => 'invalid_json'], $this->errors($this->doc()->validate($data)), var_export($broken, true));
        }
    }

    public function testReservedActionIsRejectedAsUnknown(): void
    {
        $data = $this->valid(['actions' => '[{"action":"convertOfficeToPdf"}]']);

        $this->assertContains(['column' => 'actions', 'code' => 'unknown_action'], $this->errors($this->doc()->validate($data)));
    }

    public function testRenderIfHtmlMustBeBoolean(): void
    {
        $bad = $this->valid(['actions' => '[{"action":"fetchLinkedDocument","linkHrefRegex":"x","allowedDomains":["a.example"],"renderIfHtml":"yes"}]']);
        $this->assertContains(['column' => 'actions', 'code' => 'invalid_param'], $this->errors($this->doc()->validate($bad)));

        $good = $this->valid(['actions' => '[{"action":"fetchLinkedDocument","linkHrefRegex":"x","allowedDomains":["a.example"],"renderIfHtml":true}]']);
        $this->assertTrue($this->doc()->validate($good)->isValid());
    }

    public function testRenderBodyToPdfActionIsAccepted(): void
    {
        $data = $this->valid(['actions' => '[{"action":"renderBodyToPdf"}]']);

        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testFetchActionRequiresRegexAndDomains(): void
    {
        $data = $this->valid(['actions' => '[{"action":"fetchLinkedDocument"}]']);
        $errors = $this->errors($this->doc()->validate($data));
        $this->assertCount(2, array_filter($errors, static fn(array $e): bool => $e['code'] === 'param_required'));

        $data = $this->valid(['actions' => '[{"action":"fetchLinkedDocument","linkHrefRegex":"(","allowedDomains":["bolt.eu"]}]']);
        $this->assertContains(['column' => 'actions', 'code' => 'invalid_regex'], $this->errors($this->doc()->validate($data)));

        $data = $this->valid(['actions' => '[{"action":"fetchLinkedDocument","linkHrefRegex":"x","allowedDomains":"bolt.eu, example.com"}]']);
        $this->assertTrue($this->doc()->validate($data)->isValid(), 'allowedDomains jako CSV string je přijatelný');
    }

    public function testRuleIdFormatAndUniqueness(): void
    {
        $data = $this->valid(['rule_id' => 'Bad Key']);
        $this->assertContains(['column' => 'rule_id', 'code' => 'invalid_format'], $this->errors($this->doc()->validate($data)));

        $data = $this->valid(['rule_id' => 'bolt-invoice-link']);
        $result = $this->doc(new \Dibi\Row(['id' => 7]), true)->validate($data);
        $this->assertContains(['column' => 'rule_id', 'code' => 'duplicate_rule_id'], $this->errors($result));

        $data = $this->valid(['rule_id' => 'bolt-invoice-link']);
        $this->assertTrue($this->doc(null, true)->validate($data)->isValid());

        $data = $this->valid(); // prázdný rule_id se při validaci nekontroluje — vygeneruje ho beforeSave
        $this->assertTrue($this->doc(null, true)->validate($data)->isValid());
    }

    public function testBeforeSaveSetsDefaultsAndGeneratesRuleIdFromNotice(): void
    {
        $data = $this->valid([
            'notice' => '  Faktury Bolt — odkaz  ',
            'sender_domain' => ' Bolt.eu ',
            'actions' => "[ {\"allowedDomains\": [\"bolt.eu\"], \"action\": \"fetchLinkedDocument\", \"linkHrefRegex\": \"x\"} ]",
        ]);

        $this->doc(null, true)->beforeSave($data);

        $this->assertSame('user', $data['origin']);
        $this->assertSame(0, $data['hit_count']);
        $this->assertNotEmpty($data['created']);
        $this->assertNotEmpty($data['modified']);
        $this->assertSame('Faktury Bolt — odkaz', $data['notice']);
        $this->assertSame('bolt.eu', $data['sender_domain']);
        $this->assertSame('user-faktury-bolt-odkaz', $data['rule_id']);
        $this->assertSame(
            '[{"allowedDomains":["bolt.eu"],"action":"fetchLinkedDocument","linkHrefRegex":"x"}]',
            $data['actions'],
            'akce se re-serializují kompaktně',
        );
    }

    public function testBeforeSaveKeepsExplicitRuleIdAndGeneratesRandomWithoutNotice(): void
    {
        $data = $this->valid(['rule_id' => 'my-rule']);
        $this->doc()->beforeSave($data);
        $this->assertSame('my-rule', $data['rule_id']);

        $data = $this->valid();
        $this->doc()->beforeSave($data);
        $this->assertMatchesRegularExpression('~^user-[0-9a-f]{8}$~', $data['rule_id']);
    }

    public function testBeforeSaveOnExistingRuleDoesNotResetOriginOrCreated(): void
    {
        $data = $this->valid(['id' => 5, 'origin' => 'system', 'created' => '2026-01-01 00:00:00', 'rule_id' => 'bolt-invoice-link']);

        $this->doc()->beforeSave($data);

        $this->assertSame('system', $data['origin']);
        $this->assertSame('2026-01-01 00:00:00', $data['created']);
        $this->assertArrayNotHasKey('hit_count', $data);
    }

    public function testEmptyMatchColumnsBecomeNull(): void
    {
        $data = $this->valid(['sender_email' => '', 'subject_regex' => '   ']);

        $this->doc()->beforeSave($data);

        $this->assertNull($data['sender_email']);
        $this->assertNull($data['subject_regex']);
        $this->assertSame('invoice\.bolt\.eu', $data['body_regex']);
    }
}
