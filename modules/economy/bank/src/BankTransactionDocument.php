<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Bank;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

/**
 * Document class bankovní transakce (economy_bank_transactions).
 *
 * Transakce je prvotřídní záznam — vzniká importem výpisu (Fáze 2) nebo
 * migrací (Fáze 4), ne ručním zakládáním. Ve Fázi 1 ji lze vložit přímo
 * (integrační test) a editovat jí `operation` / `partner` / `message`.
 *
 * Účtuje ji bankovní mikroengine (Fáze 3) při přechodu do stavu 40; ten zde
 * není. Fingerprint NEPOČÍTÁME — to je úkol ingestion vrstvy (Fáze 2).
 */
class BankTransactionDocument extends Document
{
    public function validate(array &$data): ValidationResult
    {
        $r = new ValidationResult();

        $direction = isset($data['direction']) ? (int) $data['direction'] : 0;
        if ($direction !== 1 && $direction !== 2) {
            $r->addError('direction', 'Směr musí být Příjem nebo Výdaj.', 'invalid');
        }

        if (empty($data['bank_account'])) {
            $r->addError('bank_account', 'Bankovní účet je povinný.', 'required');
        }

        if (empty($data['currency'])) {
            $r->addError('currency', 'Měna je povinná.', 'required');
        }

        if (empty($data['date_transaction'])) {
            $r->addError('date_transaction', 'Datum transakce je povinné.', 'required');
        }

        $amount = isset($data['amount']) ? (float) $data['amount'] : 0.0;
        if ($amount <= 0.0) {
            $r->addError('amount', 'Částka musí být kladná; směr drží pole Směr.', 'invalid');
        }

        // amount_dom je derivované (beforeSave ho dopočítá z amount × kurz),
        // takže zde odmítáme jen explicitně zápornou hodnotu.
        if (isset($data['amount_dom']) && $data['amount_dom'] !== '' && (float) $data['amount_dom'] < 0.0) {
            $r->addError('amount_dom', 'Částka v domácí měně nesmí být záporná.', 'invalid');
        }

        return $r;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        foreach (['counterparty_account', 'counterparty_name', 'symbol1', 'symbol2', 'symbol3', 'message', 'external_id'] as $col) {
            if (isset($data[$col]) && $data[$col] !== null) {
                $data[$col] = trim((string) $data[$col]);
            }
        }
        if (isset($data['currency'])) {
            $data['currency'] = strtolower(trim((string) $data['currency']));
        }

        // amount_dom = amount × exchange_rate (u domácí měny je kurz 1).
        // Dopočítáme jen když chybí — ingestion / UI ho může dodat přímo.
        $hasDom = isset($data['amount_dom']) && $data['amount_dom'] !== '' && $data['amount_dom'] !== null;
        if (!$hasDom && isset($data['amount']) && $data['amount'] !== '') {
            $rate = (isset($data['exchange_rate']) && $data['exchange_rate'] !== '' && $data['exchange_rate'] !== null)
                ? (float) $data['exchange_rate']
                : 1.0;
            $data['amount_dom'] = round((float) $data['amount'] * $rate, 2);
        }

        $this->trackStateChange($data, $originalData);
    }

    /**
     * Eviduje přechod docState pro TableGateway (dispatch
     * documentEventHandlers → účtování při vstupu do 40, úklid při odchodu).
     * Transakce nemá doc_state_changed_at, nastavuje se jen stateTransition.
     *
     * Nový záznam mimo stav Nová (import výpisu rovnou do 40) je taky přechod
     * (old = 0), ať se importované transakce zaúčtují.
     */
    private function trackStateChange(array $data, ?array $originalData): void
    {
        $this->stateTransition = null;

        if ($originalData === null) {
            $newState = (int) ($data['docState'] ?? 10);
            if ($newState !== 10) {
                $this->stateTransition = ['old' => 0, 'new' => $newState];
            }
            return;
        }

        $newState = (int) ($data['docState'] ?? $originalData['docState'] ?? 10);
        $oldState = (int) ($originalData['docState'] ?? 10);
        if ($newState !== $oldState) {
            $this->stateTransition = ['old' => $oldState, 'new' => $newState];
        }
    }
}
