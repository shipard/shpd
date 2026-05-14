<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Resolve;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;

class ResolveResultTest extends TestCase
{
    public function testMatchedSerializesIdAndBy(): void
    {
        $r = ResolveResult::matched(42, 'companyId');
        $this->assertSame(ResolveStatus::Matched, $r->status);
        $this->assertSame(42, $r->matchedId);
        $this->assertSame('companyId', $r->matchedBy);
        $this->assertSame(
            ['status' => 'matched', 'matchedId' => 42, 'matchedBy' => 'companyId'],
            $r->toArray(),
        );
    }

    public function testAmbiguousCarriesCandidates(): void
    {
        $candidates = [
            ['id' => 1, 'name' => 'Acme s.r.o.'],
            ['id' => 2, 'name' => 'Acme a.s.'],
        ];
        $r = ResolveResult::ambiguous($candidates);
        $this->assertSame(ResolveStatus::Ambiguous, $r->status);
        $this->assertSame($candidates, $r->candidates);
        $this->assertSame(
            ['status' => 'ambiguous', 'candidates' => $candidates],
            $r->toArray(),
        );
    }

    public function testNotFoundIsBare(): void
    {
        $r = ResolveResult::notFound();
        $this->assertSame(ResolveStatus::NotFound, $r->status);
        $this->assertSame(['status' => 'notFound'], $r->toArray());
    }

    public function testCanCreateCarriesPayload(): void
    {
        $payload = ['full_name' => 'New Vendor s.r.o.', 'company_id' => '99887766'];
        $r = ResolveResult::canCreate($payload);
        $this->assertSame(ResolveStatus::CanCreate, $r->status);
        $this->assertSame($payload, $r->createPayload);
        $arr = $r->toArray();
        $this->assertSame('canCreate', $arr['status']);
        $this->assertSame($payload, $arr['createPayload']);
    }
}
