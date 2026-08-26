<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Dataset\DatasetException;
use Shipard\Module\Core\Exchange\Dataset\DatasetManifest;

class DatasetManifestTest extends TestCase
{
    /** @return array<string, mixed> */
    private function valid(): array
    {
        return [
            'format'      => 'shpd.dataset.v1',
            'name'        => 'web-demo',
            'title'       => 'Demo webu — den účetní',
            'description' => 'Popis',
            'dateMode'    => 'fixed',
            'created'     => '2026-08-26T10:00:00Z',
            'counts'      => ['persons' => 12, 'docs' => 0],
        ];
    }

    public function testFromArrayParsesAllFields(): void
    {
        $m = DatasetManifest::fromArray($this->valid());

        $this->assertSame('web-demo', $m->name);
        $this->assertSame('Demo webu — den účetní', $m->title);
        $this->assertSame('Popis', $m->description);
        $this->assertSame('fixed', $m->dateMode);
        $this->assertSame('2026-08-26T10:00:00Z', $m->created);
        $this->assertSame(['persons' => 12, 'docs' => 0], $m->counts);
    }

    public function testToArrayKeepsDeterministicOrderAndFormatFirst(): void
    {
        $arr = DatasetManifest::fromArray($this->valid())->toArray();

        $this->assertSame(
            ['format', 'name', 'title', 'description', 'dateMode', 'created', 'counts'],
            array_keys($arr),
        );
        $this->assertSame('shpd.dataset.v1', $arr['format']);
    }

    public function testEmptyCountsSerializeAsObject(): void
    {
        $data = $this->valid();
        unset($data['counts'], $data['description']);
        $arr = DatasetManifest::fromArray($data)->toArray();

        $this->assertInstanceOf(\stdClass::class, $arr['counts']);
        $this->assertArrayNotHasKey('description', $arr);
        $this->assertStringContainsString('"counts": {}', json_encode($arr, JSON_PRETTY_PRINT));
    }

    public function testWrongFormatIsRejected(): void
    {
        $data = $this->valid();
        $data['format'] = 'shpd.dataset.v2';

        $this->expectException(DatasetException::class);
        $this->expectExceptionMessage("format must be 'shpd.dataset.v1'");
        DatasetManifest::fromArray($data);
    }

    public function testMissingFormatIsRejected(): void
    {
        $data = $this->valid();
        unset($data['format']);

        $this->expectException(DatasetException::class);
        DatasetManifest::fromArray($data);
    }

    public function testRelativeDateModeIsNotImplemented(): void
    {
        $data = $this->valid();
        $data['dateMode'] = 'relative';

        $this->expectException(DatasetException::class);
        $this->expectExceptionMessage("Not implemented: dateMode 'relative'");
        DatasetManifest::fromArray($data);
    }

    public function testInvalidNameIsRejected(): void
    {
        $data = $this->valid();
        $data['name'] = 'Web Demo';

        $this->expectException(DatasetException::class);
        $this->expectExceptionMessage('name');
        DatasetManifest::fromArray($data);
    }

    public function testMissingTitleIsRejected(): void
    {
        $data = $this->valid();
        unset($data['title']);

        $this->expectException(DatasetException::class);
        $this->expectExceptionMessage('title is required');
        DatasetManifest::fromArray($data);
    }

    public function testInvalidCreatedIsRejected(): void
    {
        $data = $this->valid();
        $data['created'] = 'včera';

        $this->expectException(DatasetException::class);
        $this->expectExceptionMessage('not a valid timestamp');
        DatasetManifest::fromArray($data);
    }

    public function testNegativeCountIsRejected(): void
    {
        $data = $this->valid();
        $data['counts'] = ['persons' => -1];

        $this->expectException(DatasetException::class);
        $this->expectExceptionMessage('counts.persons');
        DatasetManifest::fromArray($data);
    }

    public function testNonIntegerCountIsRejected(): void
    {
        $data = $this->valid();
        $data['counts'] = ['persons' => '12'];

        $this->expectException(DatasetException::class);
        DatasetManifest::fromArray($data);
    }

    public function testWithCountsReplacesCountsOnly(): void
    {
        $m = DatasetManifest::fromArray($this->valid())->withCounts(['mail' => 3]);

        $this->assertSame(['mail' => 3], $m->counts);
        $this->assertSame('web-demo', $m->name);
    }

    public function testSectionsOrderIsSeedOrder(): void
    {
        $this->assertSame(
            ['setup', 'persons', 'items', 'docs', 'registry', 'mail'],
            DatasetManifest::SECTIONS,
        );
    }
}
