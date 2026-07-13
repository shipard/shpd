<?php

declare(strict_types=1);

namespace Shipard\Core\Mail;

use Shipard\Core\Mail\Exception\MailComposeException;
use Shipard\Module\Core\Attachments\AttachmentService;
use Symfony\Component\Mime\Email;

/**
 * Sestaví Symfony MIME Email z řádku core_mail_outbox. Text + HTML tělo
 * (obě → multipart/alternative), přílohy resolve přes core.attachments.
 * Chybějící příloha = výjimka (fail pokusu), nikdy tiché vynechání.
 */
class MailComposer
{
    public function __construct(
        private readonly AttachmentService $attachments,
    ) {
    }

    /** @param array $row řádek core_mail_outbox */
    public function compose(array $row): Email
    {
        $id = (int) ($row['id'] ?? 0);

        $email = new Email()
            ->from((string) $row['email_from'])
            ->to((string) $row['email_to'])
            ->subject((string) $row['subject']);

        $bodyText = (string) ($row['body_text'] ?? '');
        $bodyHtml = (string) ($row['body_html'] ?? '');
        if ($bodyText === '' && $bodyHtml === '') {
            throw new MailComposeException("Outbox #{$id}: message has no body");
        }
        if ($bodyText !== '') {
            $email->text($bodyText);
        }
        if ($bodyHtml !== '') {
            $email->html($bodyHtml);
        }

        foreach ($this->decodeAttachmentIds($id, $row['attachments'] ?? null) as $attachmentId) {
            $attachment = $this->attachments->getAttachment($attachmentId);
            if ($attachment === null || (int) ($attachment['is_deleted'] ?? 0) === 1) {
                throw new MailComposeException("Outbox #{$id}: attachment {$attachmentId} not found");
            }

            $path = $this->attachments->getFilePath($attachment);
            if (!is_file($path)) {
                throw new MailComposeException(
                    "Outbox #{$id}: attachment {$attachmentId} file missing on disk",
                );
            }

            $email->attachFromPath(
                $path,
                (string) ($attachment['name'] ?? $attachment['file_name']),
                isset($attachment['mime_type']) ? (string) $attachment['mime_type'] : null,
            );
        }

        return $email;
    }

    /** @return int[] */
    private function decodeAttachmentIds(int $id, ?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new MailComposeException("Outbox #{$id}: invalid attachments JSON");
        }

        return array_map(static fn ($v) => (int) $v, array_values($decoded));
    }
}
