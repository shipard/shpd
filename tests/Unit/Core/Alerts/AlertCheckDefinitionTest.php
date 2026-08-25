<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Alerts;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Alerts\AlertCheckDefinition;

class AlertCheckDefinitionTest extends TestCase
{
    private static function baseData(): array
    {
        return [
            'id'       => 'base.persons.missing_own_person',
            'name'     => 'Own Person is missing',
            'class'    => 'Shipard\\Module\\Base\\Persons\\Checks\\MissingOwnPersonCheck',
            'interval' => '1h',
        ];
    }

    public function testMinimalConstruction(): void
    {
        $def = AlertCheckDefinition::fromArray(self::baseData(), 'base.persons');

        $this->assertSame('base.persons.missing_own_person', $def->id);
        $this->assertSame('Own Person is missing', $def->name);
        $this->assertSame('', $def->description);
        $this->assertSame('Shipard\\Module\\Base\\Persons\\Checks\\MissingOwnPersonCheck', $def->class);
        $this->assertSame('warning', $def->severity);
        $this->assertSame('1h', $def->interval);
        $this->assertSame(3600, $def->intervalSeconds);
        $this->assertTrue($def->enabled);
        $this->assertSame([], $def->tags);
        $this->assertSame('base.persons', $def->moduleId);
        $this->assertNull($def->navSection);
    }

    public function testFullConstruction(): void
    {
        $def = AlertCheckDefinition::fromArray([
            'id'          => 'iot.devices.low_battery',
            'name'        => 'Low battery',
            'description' => 'IoT device battery below threshold',
            'class'       => 'X\\Y',
            'severity'    => 'error',
            'interval'    => '30m',
            'enabled'     => false,
            'tags'        => ['iot', 'hardware'],
        ], 'iot.devices');

        $this->assertSame('iot.devices.low_battery', $def->id);
        $this->assertSame('IoT device battery below threshold', $def->description);
        $this->assertSame('error', $def->severity);
        $this->assertSame(1800, $def->intervalSeconds);
        $this->assertFalse($def->enabled);
        $this->assertSame(['iot', 'hardware'], $def->tags);
    }

    public function testMissingIdThrows(): void
    {
        $data = self::baseData(); unset($data['id']);
        $this->expectException(\InvalidArgumentException::class);
        AlertCheckDefinition::fromArray($data, 'base.persons');
    }

    public function testMissingNameThrows(): void
    {
        $data = self::baseData(); unset($data['name']);
        $this->expectException(\InvalidArgumentException::class);
        AlertCheckDefinition::fromArray($data, 'base.persons');
    }

    public function testMissingClassThrows(): void
    {
        $data = self::baseData(); unset($data['class']);
        $this->expectException(\InvalidArgumentException::class);
        AlertCheckDefinition::fromArray($data, 'base.persons');
    }

    public function testMissingIntervalThrows(): void
    {
        $data = self::baseData(); unset($data['interval']);
        $this->expectException(\InvalidArgumentException::class);
        AlertCheckDefinition::fromArray($data, 'base.persons');
    }

    public function testInvalidIdFormatThrows(): void
    {
        $data = self::baseData();
        $data['id'] = 'BadCAPS';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid id format');
        AlertCheckDefinition::fromArray($data, 'base.persons');
    }

    public function testInvalidSeverityThrows(): void
    {
        $data = self::baseData();
        $data['severity'] = 'critical';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('severity must be one of');
        AlertCheckDefinition::fromArray($data, 'base.persons');
    }

    public function testInvalidIntervalThrows(): void
    {
        $data = self::baseData();
        $data['interval'] = '5min';
        $this->expectException(\InvalidArgumentException::class);
        AlertCheckDefinition::fromArray($data, 'base.persons');
    }

    public function testTagsFilterNonStrings(): void
    {
        $data = self::baseData();
        $data['tags'] = ['ok', 42, '', null, 'fine'];
        $def = AlertCheckDefinition::fromArray($data, 'base.persons');
        $this->assertSame(['ok', 'fine'], $def->tags);
    }

    public function testEnabledDefaultsTrue(): void
    {
        $def = AlertCheckDefinition::fromArray(self::baseData(), 'base.persons');
        $this->assertTrue($def->enabled);
    }

    public function testNavSectionAcceptsSectionIdAndTopSentinel(): void
    {
        $data = self::baseData();
        $data['navSection'] = 'accounting';
        $this->assertSame('accounting', AlertCheckDefinition::fromArray($data, 'base.persons')->navSection);

        $data['navSection'] = '_top';
        $this->assertSame('_top', AlertCheckDefinition::fromArray($data, 'base.persons')->navSection);
    }

    public function testInvalidNavSectionThrows(): void
    {
        $data = self::baseData();
        $data['navSection'] = 'Bad-Section';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid navSection');
        AlertCheckDefinition::fromArray($data, 'base.persons');
    }
}
