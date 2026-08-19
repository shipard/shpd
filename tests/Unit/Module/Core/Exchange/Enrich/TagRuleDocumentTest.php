<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Enrich;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Core\Exchange\Enrich\TagRuleDocument;

/**
 * TagRuleDocument (tasks/content-tag-ui.md D28): validace klíče štítku
 * proti taxonomii, unikátnost IČO, přepnutí origin → 'user' při
 * přeštítkování uživatelem.
 */
class TagRuleDocumentTest extends TestCase
{
    private function doc(bool $withTaxonomy = true): TagRuleDocument
    {
        $doc = new TagRuleDocument();
        if ($withTaxonomy) {
            $config = $this->createMock(ConfigRuntime::class);
            $config->method('cfgItem')->willReturnMap([
                ['core.exchange.contentTags', [
                    'vehicle.fuel'   => ['name' => 'Pohonné hmoty', 'order' => 10],
                    'it.software'    => ['name' => 'Software a SaaS', 'order' => 100],
                ]],
            ]);
            $doc->setConfig($config);
        }
        return $doc;
    }

    // --- validate ------------------------------------------------------------

    public function testValidateRequiresCompanyIdAndTag(): void
    {
        $doc = $this->doc();

        $data = ['tag' => 'vehicle.fuel'];
        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('company_id', $result->toArray()[0]['column']);

        $data = ['company_id' => '12345678'];
        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('tag', $result->toArray()[0]['column']);
    }

    public function testValidateRejectsUnknownTag(): void
    {
        $doc = $this->doc();
        $data = ['company_id' => '12345678', 'tag' => 'nonsense.tag'];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertSame('unknown_tag', $result->toArray()[0]['code']);
    }

    public function testValidateAcceptsKnownTag(): void
    {
        $doc = $this->doc();
        $data = ['company_id' => '12345678', 'tag' => 'vehicle.fuel'];

        $this->assertTrue($doc->validate($data)->isValid());
    }

    public function testValidateDegradesWithoutCompiledConfig(): void
    {
        // Chybějící compiled config → kontrola klíče se přeskočí (vzor
        // ItemDocument), validace nesmí spadnout.
        $doc = $this->doc(withTaxonomy: false);
        $data = ['company_id' => '12345678', 'tag' => 'whatever.tag'];

        $this->assertTrue($doc->validate($data)->isValid());
    }

    // --- beforeSave ----------------------------------------------------------

    public function testBeforeSaveNormalizesAndDefaultsNewRule(): void
    {
        $doc = $this->doc();
        $data = ['company_id' => ' 123 456 78 ', 'tag' => 'vehicle.fuel'];

        $doc->beforeSave($data);

        $this->assertSame('12345678', $data['company_id']);
        $this->assertSame('user', $data['origin']);
        $this->assertSame(1, $data['confirmed']);
        $this->assertSame(0, $data['hit_count']);
        $this->assertNotEmpty($data['created']);
        $this->assertNotEmpty($data['modified']);
    }

    public function testBeforeSaveTagChangeSwitchesOriginToUser(): void
    {
        $doc = $this->doc();
        $original = ['id' => 5, 'company_id' => '12345678', 'tag' => 'vehicle.fuel', 'origin' => 'learned'];
        $data = ['id' => 5, 'company_id' => '12345678', 'tag' => 'it.software', 'origin' => 'learned'];

        $doc->beforeSave($data, $original);

        $this->assertSame('user', $data['origin']);
    }

    public function testBeforeSaveUnchangedTagKeepsOrigin(): void
    {
        $doc = $this->doc();
        $original = ['id' => 5, 'company_id' => '12345678', 'tag' => 'vehicle.fuel', 'origin' => 'learned'];
        $data = ['id' => 5, 'company_id' => '12345678', 'tag' => 'vehicle.fuel', 'origin' => 'learned'];

        $doc->beforeSave($data, $original);

        $this->assertSame('learned', $data['origin']);
    }
}
