<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Mail\IncomingMessagesForm;

class IncomingMessagesFormHeaderInfoTest extends TestCase
{
    private function createForm(): IncomingMessagesForm
    {
        // Bez DB / config — všechna data potřebná pro header jsou přímo
        // v $data row, žádný DB lookup / cfgItem resolve.
        return new IncomingMessagesForm('core_mail_incoming_messages');
    }

    public function testEmptySubjectReturnsNull(): void
    {
        $form = $this->createForm();

        $this->assertNull($form->buildHeaderInfo([
            'sender_email' => 'jan@example.com',
            'received_at'  => '2024-05-28T14:30:00',
        ]));
    }

    public function testWhitespaceOnlySubjectReturnsNull(): void
    {
        $form = $this->createForm();

        $this->assertNull($form->buildHeaderInfo([
            'subject' => '   ',
        ]));
    }

    public function testMinimalRecordOnlySubject(): void
    {
        $form = $this->createForm();

        $info = $form->buildHeaderInfo([
            'subject' => 'Faktura č. 2024-0001',
        ]);

        $this->assertNotNull($info);
        $this->assertSame('Faktura č. 2024-0001', $info->title);
        $this->assertSame([], $info->info);
        $this->assertSame('mail', $info->icon);
        $this->assertSame([], $info->summary);
    }

    public function testSenderNamePreferred(): void
    {
        $form = $this->createForm();

        // Když je vyplněn sender_name, použije se on (přívětivější než e-mail).
        $info = $form->buildHeaderInfo([
            'subject'      => 'Faktura č. 2024-0001',
            'sender_name'  => 'Jan Novák',
            'sender_email' => 'jan@example.com',
        ]);

        $this->assertNotNull($info);
        $this->assertSame(
            [['label' => 'Od', 'value' => 'Jan Novák']],
            $info->info,
        );
    }

    public function testSenderEmailFallback(): void
    {
        $form = $this->createForm();

        // Bez sender_name (typicky robotí maily) padá fallback na e-mail.
        $info = $form->buildHeaderInfo([
            'subject'      => 'AUTO: Bank statement',
            'sender_email' => 'noreply@kb.cz',
        ]);

        $this->assertNotNull($info);
        $this->assertSame(
            [['label' => 'Od', 'value' => 'noreply@kb.cz']],
            $info->info,
        );
    }

    public function testSenderEmptyNameFallsBackToEmail(): void
    {
        $form = $this->createForm();

        // Whitespace-only sender_name se nepočítá — fallback na e-mail.
        $info = $form->buildHeaderInfo([
            'subject'      => 'Test',
            'sender_name'  => '   ',
            'sender_email' => 'test@example.com',
        ]);

        $this->assertNotNull($info);
        $this->assertSame(
            [['label' => 'Od', 'value' => 'test@example.com']],
            $info->info,
        );
    }

    public function testSenderBothEmptyOmitted(): void
    {
        $form = $this->createForm();

        $info = $form->buildHeaderInfo([
            'subject'      => 'Test',
            'sender_name'  => '',
            'sender_email' => '',
        ]);

        $this->assertNotNull($info);
        $this->assertSame([], $info->info);
    }

    public function testReceivedAtFormatted(): void
    {
        $form = $this->createForm();

        // DATETIME normalized to ISO 8601-like string by DataSourceConnection.
        $info = $form->buildHeaderInfo([
            'subject'     => 'Test',
            'received_at' => '2024-05-28T14:30:00',
        ]);

        $this->assertNotNull($info);
        $this->assertSame(
            [['label' => 'Doručeno', 'value' => '28.05.2024 14:30']],
            $info->info,
        );
    }

    public function testReceivedAtAsDateTimeObject(): void
    {
        $form = $this->createForm();

        // Defenzivně — kdyby přišel raw Dibi DateTime (mimo DataSourceConnection
        // normalizaci), pořád to formátujeme správně.
        $info = $form->buildHeaderInfo([
            'subject'     => 'Test',
            'received_at' => new \DateTimeImmutable('2024-05-28 14:30:00'),
        ]);

        $this->assertNotNull($info);
        $this->assertSame(
            [['label' => 'Doručeno', 'value' => '28.05.2024 14:30']],
            $info->info,
        );
    }

    public function testReceivedAtEmptyOmitted(): void
    {
        $form = $this->createForm();

        $info = $form->buildHeaderInfo([
            'subject'     => 'Test',
            'received_at' => '',
        ]);

        $this->assertNotNull($info);
        $this->assertSame([], $info->info);
    }

    public function testReceivedAtNullOmitted(): void
    {
        $form = $this->createForm();

        $info = $form->buildHeaderInfo([
            'subject'     => 'Test',
            'received_at' => null,
        ]);

        $this->assertNotNull($info);
        $this->assertSame([], $info->info);
    }

    public function testReceivedAtMalformedSkipped(): void
    {
        $form = $this->createForm();

        $info = $form->buildHeaderInfo([
            'subject'     => 'Test',
            'received_at' => 'not-a-date-at-all',
        ]);

        $this->assertNotNull($info);
        $this->assertSame([], $info->info);
    }

    public function testFullExampleOrdering(): void
    {
        $form = $this->createForm();

        $info = $form->buildHeaderInfo([
            'subject'      => 'Faktura č. 2024-0001',
            'sender_name'  => 'Jan Novák',
            'sender_email' => 'jan@example.com',
            'received_at'  => '2024-05-28T14:30:00',
        ]);

        $this->assertNotNull($info);
        $this->assertSame('Faktura č. 2024-0001', $info->title);
        $this->assertSame(
            [
                ['label' => 'Od',       'value' => 'Jan Novák'],
                ['label' => 'Doručeno', 'value' => '28.05.2024 14:30'],
            ],
            $info->info,
        );
        $this->assertSame('mail', $info->icon);
        $this->assertSame([], $info->summary);
    }
}
