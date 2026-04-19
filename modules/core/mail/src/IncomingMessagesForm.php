<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\TableForm;

/**
 * Formulář pro ruční pořízení / úpravu došlé zprávy.
 *
 * Layout odpovídá spec §6.1 — dva taby:
 *   1) „Zpráva" — všechny hlavičky + tělo
 *   2) „Přílohy" — standardní attachments panel (tableId = 303)
 *
 * `source_type` (zdroj) ani `message_id` (lidský kód) se v UI neupravují —
 * Form je nevystavuje, Document je nastaví v beforeSave.
 */
class IncomingMessagesForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $mailboxOptions = $this->resolveMailboxOptions();
        $primaryTypeOptions = $this->resolvePrimaryTypeOptions();

        // ── Tab: Zpráva ──────────────────────────────────────────────────────
        $basic = $this->tab('basic', 'Zpráva')
            ->addSelect('mailbox', cols: 2,
                options: $mailboxOptions,
                required: true,
                triggers: 'reload',
            )
            ->addInput('received_at', cols: 1,
                required: true,
                inputType: 'datetime',
            )
            ->addSelect('primary_type', cols: 1,
                options: $primaryTypeOptions,
                required: true,
            )

            ->addSeparator('Odesílatel')
            ->addInput('sender_email', cols: 2, required: true, inputType: 'email')
            ->addInput('sender_name', cols: 2)

            ->addSeparator('Obsah')
            ->addInput('subject', cols: 4, required: true)
            ->addInput('body_plain', cols: 4,
                inputType: 'textarea',
                hint: 'Prostý text zprávy. HTML varianta se v Fázi 1 neupravuje ručně — vzniká jen přes import.',
            )
            ->build();

        $tabs = [$basic, $this->attachmentsTab()];

        return new FormDefinition(
            table: $this->table,
            title: 'Došlá zpráva',
            titleNew: 'Nová došlá zpráva',
            tabs: $tabs,
            fullSize: true,
        );
    }

    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        // Pokud se změnila schránka a uživatel nemá vyplněný primary_type,
        // dopočítáme default podle vybrané schránky.
        if ($changedColumn === 'mailbox' && empty($data['primary_type']) && isset($data['mailbox'])) {
            $mailboxId = (int) $data['mailbox'];
            $data['primary_type'] = $this->lookupMailboxDefaultType($mailboxId);
        }

        $isNew = !isset($data['id']) || $data['id'] === null;
        return new RecalculateResult(
            $this->buildFormDefinition($data, $isNew),
            $data,
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Načte aktivní schránky (docState ∈ {10 Koncept, 40 V pořádku, 80 V opravě})
     * setříděné dle `name`. Archivní a smazané schránky v selectu nezobrazujeme.
     *
     * @return list<array{value: int, label: string}>
     */
    private function resolveMailboxOptions(): array
    {
        if ($this->db === null) {
            return [];
        }

        $rows = $this->db->fetchAll(
            'SELECT id, mailbox_id, name FROM core_mail_mailboxes'
            . ' WHERE docState IN (10, 40, 80)'
            . ' ORDER BY name ASC',
        );

        $options = [];
        foreach ($rows as $row) {
            $label = (string) ($row['name'] ?? $row['mailbox_id']);
            if (!empty($row['mailbox_id'])) {
                $label .= ' (' . $row['mailbox_id'] . ')';
            }
            $options[] = ['value' => (int) $row['id'], 'label' => $label];
        }
        return $options;
    }

    /**
     * Vrací pouze aktivní typy (enabled=true) seřazené dle `order`.
     *
     * @return list<array{value: string, label: string}>
     */
    private function resolvePrimaryTypeOptions(): array
    {
        if ($this->config === null) {
            return [
                ['value' => 'invoiceReceived', 'label' => 'Přijatá faktura'],
                ['value' => 'other', 'label' => 'Ostatní'],
            ];
        }

        $cfgData = $this->config->cfgItem('core.mail.primaryTypes');
        if (!is_array($cfgData)) {
            return [];
        }

        $entries = [];
        foreach ($cfgData as $key => $entry) {
            if (!is_array($entry) || !isset($entry['name'])) {
                continue;
            }
            if (($entry['enabled'] ?? true) === false) {
                continue;
            }
            $entries[] = [
                'order' => (int) ($entry['order'] ?? 999),
                'value' => (string) $key,
                'label' => (string) $entry['name'],
            ];
        }

        usort($entries, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_map(static fn(array $e): array => ['value' => $e['value'], 'label' => $e['label']], $entries);
    }

    private function lookupMailboxDefaultType(int $mailboxId): string
    {
        if ($this->db === null || $mailboxId <= 0) {
            return 'other';
        }

        $row = $this->db->fetchRow(
            'SELECT default_primary_type FROM core_mail_mailboxes WHERE id = %i',
            $mailboxId,
        );

        $default = (string) ($row['default_primary_type'] ?? '');
        return $default !== '' ? $default : 'other';
    }
}
