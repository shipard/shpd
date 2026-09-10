<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\IncomingMessagesViewer;

/**
 * Layout řádku Došlé pošty po zavedení partnera zprávy
 * (tasks/mail-message-title-partner.md D7): t2 = partner (Osoba ??
 * partner_name) s fallbackem na odesílatele, odesílatel pak v t3 za
 * schránkou. Bez ConfigRuntime → anglické fallback popisky.
 */
final class IncomingMessagesViewerTest extends TestCase
{
    private function viewer(): IncomingMessagesViewer
    {
        return new IncomingMessagesViewer(
            $this->createMock(DataSourceConnection::class),
            'core_mail_incoming_messages',
        );
    }

    /** @return array<string, mixed> */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id'                => 1,
            'subject'           => 'Message from scanner',
            'sender_email'      => 'scanner@example.test',
            'sender_name'       => 'Kancelářský skener',
            'primary_type'      => 'invoiceReceived',
            'received_at'       => '2026-09-01 10:00:00',
            'body_plain'        => "Dobrý den,\nv příloze zasíláme doklad.",
            'docState'          => 20,
            'docStateMain'      => 2,
            'analysis_state'    => 30,
            'is_bulk'           => 0,
            'mailbox'           => 1,
            'mailbox_name'      => 'Faktury',
            'mailbox_code'      => 'inv',
            'partner_person'    => null,
            'partner_name'      => null,
            'partner_full_name' => null,
            'ai_title'          => null,
            'source_type'       => 2,
        ], $overrides);
    }

    // ── t1: titulek (D3) — bez configu jen prázdný předmět a ruční zprávy ──

    public function testT1KeepsSubjectForEmailWithAiTitle(): void
    {
        $rendered = $this->viewer()->renderRow($this->row(['ai_title' => 'Faktura 2026-0042 — Dodavatel s.r.o.']));
        $this->assertSame('Message from scanner', $rendered['t1']);
    }

    public function testT1UsesAiTitleForManualMessage(): void
    {
        $rendered = $this->viewer()->renderRow($this->row([
            'subject'     => 'faktura_final_v2.pdf',
            'source_type' => 1,
            'ai_title'    => 'Faktura 2026-0042 — Dodavatel s.r.o.',
        ]));
        $this->assertSame('Faktura 2026-0042 — Dodavatel s.r.o.', $rendered['t1']);
    }

    public function testT1UsesAiTitleForEmptySubject(): void
    {
        $rendered = $this->viewer()->renderRow($this->row(['subject' => '', 'ai_title' => 'Dopis od úřadu']));
        $this->assertSame('Dopis od úřadu', $rendered['t1']);

        $withoutTitle = $this->viewer()->renderRow($this->row(['subject' => '', 'ai_title' => null]));
        $this->assertSame('', $withoutTitle['t1']);
    }

    /** @return list<string> */
    private function t3Texts(array $rendered): array
    {
        return array_map(static fn(array $part): string => $part['text'], $rendered['t3'] ?? []);
    }

    public function testPersonNamePreferredOverCanonicalSnapshot(): void
    {
        $rendered = $this->viewer()->renderRow($this->row([
            'partner_person'    => 77,
            'partner_name'      => 'Dodavatel sro',
            'partner_full_name' => 'Dodavatel s.r.o.',
        ]));

        $this->assertSame('Message from scanner', $rendered['t1']);
        $this->assertSame('Dodavatel s.r.o.', $rendered['t2']);

        $t3 = $this->t3Texts($rendered);
        $this->assertSame('[Faktury]', $t3[0]);
        $this->assertStringEndsWith(': Kancelářský skener', $t3[1], 'odesílatel se přesouvá do t3 za schránku');
        $this->assertSame('Dobrý den,', $t3[2]);
    }

    public function testCanonicalSnapshotWhenPersonMissing(): void
    {
        // Smazaná / nespárovaná Osoba → LEFT JOIN vrátí null, t2 padá na partner_name (P9).
        $rendered = $this->viewer()->renderRow($this->row([
            'partner_person'    => 77,
            'partner_name'      => 'Dodavatel s.r.o.',
            'partner_full_name' => null,
        ]));

        $this->assertSame('Dodavatel s.r.o.', $rendered['t2']);
        $this->assertStringEndsWith(': Kancelářský skener', $this->t3Texts($rendered)[1]);
    }

    public function testSenderFallbackWithoutPartnerKeepsLegacyLayout(): void
    {
        $rendered = $this->viewer()->renderRow($this->row());

        $this->assertSame('Kancelářský skener', $rendered['t2']);
        // Bez partnera se odesílatel v t3 neopakuje.
        $this->assertSame(['[Faktury]', 'Dobrý den,'], $this->t3Texts($rendered));
    }

    public function testSenderEmailFallbackWhenNameMissing(): void
    {
        $rendered = $this->viewer()->renderRow($this->row(['sender_name' => null]));
        $this->assertSame('scanner@example.test', $rendered['t2']);

        $withPartner = $this->viewer()->renderRow($this->row([
            'sender_name'  => '',
            'partner_name' => 'Dodavatel s.r.o.',
        ]));
        $this->assertSame('Dodavatel s.r.o.', $withPartner['t2']);
        $this->assertStringEndsWith(': scanner@example.test', $this->t3Texts($withPartner)[1]);
    }

    public function testEmptySenderAndPartnerGiveNullT2(): void
    {
        $rendered = $this->viewer()->renderRow($this->row([
            'sender_name'  => null,
            'sender_email' => '',
            'body_plain'   => null,
        ]));

        $this->assertNull($rendered['t2']);
        $this->assertSame(['[Faktury]'], $this->t3Texts($rendered));
    }
}
