<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Tests\Fixtures\Module\Docs\Core\TestableDocsHeadsDocument;

/**
 * Coverage for DocDocument::trackStateChange — feeds doc_state_changed_at,
 * the input column for the docs.core.stale_in_repair alert check.
 */
class DocDocumentTrackStateChangeTest extends TestCase
{
    public function testNewRecordGetsCurrentTimestamp(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $data = ['docState' => 10];

        $before = time();
        $doc->trackStateChangePub($data, null);
        $after = time();

        $this->assertArrayHasKey('doc_state_changed_at', $data);
        $ts = strtotime((string) $data['doc_state_changed_at']);
        $this->assertNotFalse($ts);
        $this->assertGreaterThanOrEqual($before, $ts);
        $this->assertLessThanOrEqual($after, $ts);
    }

    public function testUnchangedStatePreservesOriginalTimestamp(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $original = [
            'docState'             => 40,
            'doc_state_changed_at' => '2024-01-01 10:00:00',
        ];
        $data = ['docState' => 40];

        $doc->trackStateChangePub($data, $original);

        $this->assertSame('2024-01-01 10:00:00', $data['doc_state_changed_at']);
    }

    public function testStateChangeUpdatesTimestampToNow(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $original = [
            'docState'             => 40,
            'doc_state_changed_at' => '2024-01-01 10:00:00',
        ];
        $data = ['docState' => 80];

        $before = time();
        $doc->trackStateChangePub($data, $original);
        $after = time();

        $ts = strtotime((string) $data['doc_state_changed_at']);
        $this->assertNotFalse($ts);
        $this->assertGreaterThanOrEqual($before, $ts);
        $this->assertLessThanOrEqual($after, $ts);
        $this->assertNotSame('2024-01-01 10:00:00', $data['doc_state_changed_at']);
    }

    public function testUnchangedStateWithNullOriginalTimestampFallsBackToNow(): void
    {
        // Defensive branch: pre-backfill row sneaks through with NULL timestamp.
        $doc = new TestableDocsHeadsDocument();
        $original = [
            'docState'             => 40,
            'doc_state_changed_at' => null,
        ];
        $data = ['docState' => 40];

        $before = time();
        $doc->trackStateChangePub($data, $original);
        $after = time();

        $ts = strtotime((string) $data['doc_state_changed_at']);
        $this->assertNotFalse($ts);
        $this->assertGreaterThanOrEqual($before, $ts);
        $this->assertLessThanOrEqual($after, $ts);
    }

    public function testClientPayloadCannotOverrideOnUnchangedState(): void
    {
        // The column is system: true — client must not be able to spoof it.
        $doc = new TestableDocsHeadsDocument();
        $original = [
            'docState'             => 40,
            'doc_state_changed_at' => '2024-06-15 12:00:00',
        ];
        $data = [
            'docState'             => 40,
            'doc_state_changed_at' => '2099-12-31 23:59:59', // malicious
        ];

        $doc->trackStateChangePub($data, $original);

        $this->assertSame('2024-06-15 12:00:00', $data['doc_state_changed_at']);
    }

    // ── getStateTransition (vstup pro documentEventHandlers dispatch) ───────

    public function testTransitionExposedOnStateChange(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $data = ['docState' => 40];
        $doc->trackStateChangePub($data, ['docState' => 10]);

        $this->assertSame(['old' => 10, 'new' => 40], $doc->getStateTransition());
    }

    public function testNoTransitionOnUnchangedState(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $data = ['docState' => 40, 'description' => 'x'];
        $doc->trackStateChangePub($data, ['docState' => 40, 'doc_state_changed_at' => '2024-06-15 12:00:00']);

        $this->assertNull($doc->getStateTransition());
    }

    public function testInsertInKonceptHasNoTransition(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $data = ['docState' => 10];
        $doc->trackStateChangePub($data, null);

        $this->assertNull($doc->getStateTransition());
    }

    public function testInsertOutsideKonceptHasTransitionFromZero(): void
    {
        // Import: doklad vzniká rovnou ve 40 — old = 0, ať se zaúčtuje.
        $doc = new TestableDocsHeadsDocument();
        $data = ['docState' => 40];
        $doc->trackStateChangePub($data, null);

        $this->assertSame(['old' => 0, 'new' => 40], $doc->getStateTransition());
    }
}
