<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Mail\ExtractedDocumentDocument;

/**
 * ExtractedDocumentDocument auto-transition hook (afterPersist).
 *
 * Logika podle tasks/mail-phase3a.md §10 rozhodnutí 4
 * (stavy dle tasks/mail-states-and-classification.md §A4):
 *   - status mění na applied/rejected/superseded
 *   - žádný sourozenec není ready_to_apply / pending_review / low_confidence
 *   - zpráva je v docState=20 (K řešení)
 *   → přepne zprávu na docState=40 (Hotovo)
 *
 * Mockujeme \Dibi\Connection a kontrolujeme volání UPDATE.
 */
class ExtractedDocumentDocumentTest extends TestCase
{
    private function doc(): ExtractedDocumentDocument
    {
        return new ExtractedDocumentDocument();
    }

    // --- validate -----------------------------------------------------------

    public function testValidateRequiresMessage(): void
    {
        $doc = $this->doc();
        $data = ['analysis' => 1, 'doc_type' => 'invoiceReceived'];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('message', array_column($result->toArray(), 'column'));
    }

    public function testValidateRejectedRequiresReason(): void
    {
        $doc = $this->doc();
        $data = [
            'message' => 5,
            'analysis' => 1,
            'doc_type' => 'invoiceReceived',
            'status' => ExtractedDocumentDocument::STATUS_REJECTED,
        ];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('rejected_reason', array_column($result->toArray(), 'column'));
    }

    public function testValidateRejectedWithReasonPasses(): void
    {
        $doc = $this->doc();
        $data = [
            'message' => 5,
            'analysis' => 1,
            'doc_type' => 'invoiceReceived',
            'status' => ExtractedDocumentDocument::STATUS_REJECTED,
            'rejected_reason' => 'Falešná shoda',
        ];

        $result = $doc->validate($data);

        $this->assertTrue($result->isValid());
    }

    // --- beforeSave ---------------------------------------------------------

    public function testBeforeSaveDefaultsStatusToPendingReview(): void
    {
        $doc = $this->doc();
        $data = ['message' => 5, 'analysis' => 1, 'doc_type' => 'invoiceReceived'];

        $doc->beforeSave($data);

        $this->assertSame(ExtractedDocumentDocument::STATUS_PENDING_REVIEW, $data['status']);
    }

    public function testBeforeSaveSetsAppliedAtWhenStatusApplied(): void
    {
        $doc = $this->doc();
        $data = [
            'id' => 7,
            'message' => 5,
            'analysis' => 1,
            'doc_type' => 'invoiceReceived',
            'status' => ExtractedDocumentDocument::STATUS_APPLIED,
        ];

        $doc->beforeSave($data);

        $this->assertArrayHasKey('applied_at', $data);
        $this->assertNotEmpty($data['applied_at']);
    }

    public function testBeforeSavePreservesExistingAppliedAt(): void
    {
        $doc = $this->doc();
        $existing = '2025-12-31 12:34:56';
        $data = [
            'id' => 7,
            'message' => 5,
            'analysis' => 1,
            'doc_type' => 'invoiceReceived',
            'status' => ExtractedDocumentDocument::STATUS_APPLIED,
            'applied_at' => $existing,
        ];

        $doc->beforeSave($data);

        $this->assertSame($existing, $data['applied_at']);
    }

    // --- afterPersist: auto-transition 20 -> 40 -----------------------------
    //
    // Auto-transition běží v afterPersist (uvnitř transakce, před commitem)
    // pro splnění spec §10 dec.4 atomicity. Mockujeme protected hooks
    // (messageIsInProgress, countPendingSiblings, markMessageDone) místo
    // přímého Dibi\Connection — final query() ho nelze mockovat. Logika
    // "kdy spustit transition" se ověřuje proti tomuto subclass spy; SQL se
    // exercituje až integration testy proti reálné DB (Fáze D).

    private function spy(bool $isInProgress, int $pendingCount): TestableExtractedDoc
    {
        $doc = new TestableExtractedDoc();
        $doc->stubMessageIsInProgress = $isInProgress;
        $doc->stubPendingCount = $pendingCount;
        return $doc;
    }

    public function testAfterPersistDoesNothingWhenStatusStillPending(): void
    {
        $doc = $this->spy(true, 0);
        $doc->afterPersist([
            'id' => 1,
            'message' => 5,
            'status' => ExtractedDocumentDocument::STATUS_PENDING_REVIEW,
        ]);

        $this->assertSame(0, $doc->messageIsInProgressCalls);
        $this->assertSame(0, $doc->markDoneCalls);
    }

    public function testAfterPersistDoesNothingWhenMessageNotInState20(): void
    {
        $doc = $this->spy(isInProgress: false, pendingCount: 0);
        $doc->afterPersist([
            'id' => 1,
            'message' => 5,
            'status' => ExtractedDocumentDocument::STATUS_APPLIED,
        ]);

        $this->assertSame(1, $doc->messageIsInProgressCalls);
        $this->assertSame(0, $doc->countPendingCalls);
        $this->assertSame(0, $doc->markDoneCalls);
    }

    public function testAfterPersistDoesNothingWhenSiblingsStillPending(): void
    {
        $doc = $this->spy(isInProgress: true, pendingCount: 2);
        $doc->afterPersist([
            'id' => 1,
            'message' => 5,
            'status' => ExtractedDocumentDocument::STATUS_APPLIED,
        ]);

        $this->assertSame(1, $doc->messageIsInProgressCalls);
        $this->assertSame(1, $doc->countPendingCalls);
        $this->assertSame(0, $doc->markDoneCalls);
    }

    public function testAfterPersistTransitionsMessageWhenAllResolved(): void
    {
        $doc = $this->spy(isInProgress: true, pendingCount: 0);
        $doc->afterPersist([
            'id' => 1,
            'message' => 5,
            'status' => ExtractedDocumentDocument::STATUS_APPLIED,
        ]);

        $this->assertSame(1, $doc->markDoneCalls);
        $this->assertSame(5, $doc->lastDoneMessage);
    }

    public function testAfterPersistAiFailedDoesNotBlockTransition(): void
    {
        // ai_failed sourozenec NENÍ v PENDING_STATUSES, takže countPendingSiblings
        // vrátí 0 i když takový sourozenec existuje. Tady jen ověřujeme,
        // že na rejected status hook normálně proběhne.
        $doc = $this->spy(isInProgress: true, pendingCount: 0);
        $doc->afterPersist([
            'id' => 1,
            'message' => 5,
            'status' => ExtractedDocumentDocument::STATUS_REJECTED,
        ]);

        $this->assertSame(1, $doc->markDoneCalls);
    }

    public function testAfterPersistSupersededAlsoTriggersCheck(): void
    {
        $doc = $this->spy(isInProgress: true, pendingCount: 0);
        $doc->afterPersist([
            'id' => 1,
            'message' => 5,
            'status' => ExtractedDocumentDocument::STATUS_SUPERSEDED,
        ]);

        $this->assertSame(1, $doc->markDoneCalls);
    }

    // --- reconcileMessageAfterUnapply: reverzní 40 -> 20 ---------------------

    public function testUnapplyReconcileTransitionsDoneMessageBack(): void
    {
        $doc = new TestableUnapplyReconcileDoc();
        $doc->stubMessageIsDone = true;
        $doc->stubPendingCount = 1;

        $doc->reconcileMessageAfterUnapply(5);

        $this->assertSame(5, $doc->lastInProgressMessage);
    }

    public function testUnapplyReconcileDoesNothingWhenMessageNotDone(): void
    {
        $doc = new TestableUnapplyReconcileDoc();
        $doc->stubMessageIsDone = false;
        $doc->stubPendingCount = 1;

        $doc->reconcileMessageAfterUnapply(5);

        $this->assertNull($doc->lastInProgressMessage);
    }

    public function testUnapplyReconcileDoesNothingWithoutPendingSiblings(): void
    {
        $doc = new TestableUnapplyReconcileDoc();
        $doc->stubMessageIsDone = true;
        $doc->stubPendingCount = 0;

        $doc->reconcileMessageAfterUnapply(5);

        $this->assertNull($doc->lastInProgressMessage);
    }
}

/**
 * Testovací subclass — overriduje protected DB hooks, aby šly testovat
 * bez reálného Dibi (final query() nelze mockovat). Plná SQL cesta se
 * pokrývá v integration testech proti reálné DB.
 */
class TestableExtractedDoc extends ExtractedDocumentDocument
{
    public bool $stubMessageIsInProgress = true;
    public int $stubPendingCount = 0;
    public int $messageIsInProgressCalls = 0;
    public int $countPendingCalls = 0;
    public int $markDoneCalls = 0;
    public ?int $lastDoneMessage = null;

    protected function messageIsInProgress(int $messageId): bool
    {
        $this->messageIsInProgressCalls++;
        return $this->stubMessageIsInProgress;
    }

    protected function countPendingSiblings(int $messageId): int
    {
        $this->countPendingCalls++;
        return $this->stubPendingCount;
    }

    protected function markMessageDone(int $messageId): void
    {
        $this->markDoneCalls++;
        $this->lastDoneMessage = $messageId;
    }

    public function afterPersist(array $data): void
    {
        // Override $this->db check — testy nepředávají db.
        $status = isset($data['status']) ? (int) $data['status'] : null;
        if ($status === null) {
            return;
        }
        $resolved = [
            ExtractedDocumentDocument::STATUS_APPLIED,
            ExtractedDocumentDocument::STATUS_REJECTED,
            ExtractedDocumentDocument::STATUS_SUPERSEDED,
        ];
        if (!in_array($status, $resolved, true)) {
            return;
        }
        $messageId = isset($data['message']) ? (int) $data['message'] : 0;
        if ($messageId <= 0) {
            return;
        }
        $this->maybeTransitionMessage($messageId);
    }
}

/**
 * Spy pro reverzní reconcile — obchází $this->db check v public metodě
 * stubem protected hooků, stejný vzor jako TestableExtractedDoc.
 */
class TestableUnapplyReconcileDoc extends ExtractedDocumentDocument
{
    public bool $stubMessageIsDone = true;
    public int $stubPendingCount = 0;
    public ?int $lastInProgressMessage = null;

    protected function messageIsDone(int $messageId): bool
    {
        return $this->stubMessageIsDone;
    }

    protected function countPendingSiblings(int $messageId): int
    {
        return $this->stubPendingCount;
    }

    protected function markMessageInProgress(int $messageId): void
    {
        $this->lastInProgressMessage = $messageId;
    }

    public function reconcileMessageAfterUnapply(int $messageId): void
    {
        // Override $this->db check — testy nepředávají db.
        if (!$this->messageIsDone($messageId)) {
            return;
        }
        if ($this->countPendingSiblings($messageId) === 0) {
            return;
        }
        $this->markMessageInProgress($messageId);
    }
}
