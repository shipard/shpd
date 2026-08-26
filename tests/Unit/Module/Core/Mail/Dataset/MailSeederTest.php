<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Dataset;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Mail\Dataset\MailSeeder;

class MailSeederTest extends TestCase
{
    public function testRemapRewritesKnownOrdinalsAndReportsUnknown(): void
    {
        $canonical = json_decode('{"attachments":[{"ref":"att:1"},{"ref":"att:2"},{"ref":"att:7"}],"source":{"raw":{}},"nested":["att:2",{"x":"att:1"}]}', false);

        $missing = null;
        $out = MailSeeder::remapAttachmentRefs($canonical, [1 => 501, 2 => 502], $missing);

        $this->assertSame('att:501', $out->attachments[0]->ref);
        $this->assertSame('att:502', $out->attachments[1]->ref);
        $this->assertSame('att:7', $out->attachments[2]->ref, 'unknown ordinal is left untouched');
        $this->assertSame('att:502', $out->nested[0]);
        $this->assertSame('att:501', $out->nested[1]->x);
        $this->assertInstanceOf(\stdClass::class, $out->source->raw, 'empty objects survive');
        $this->assertSame(['att:7'], $missing);
    }

    public function testRemapLeavesNonRefStringsAlone(): void
    {
        $missing = null;
        $this->assertSame('attachment:1', MailSeeder::remapAttachmentRefs('attachment:1', [1 => 5], $missing));
        $this->assertSame(12, MailSeeder::remapAttachmentRefs(12, [1 => 5], $missing));
        $this->assertSame([], $missing);
    }
}
