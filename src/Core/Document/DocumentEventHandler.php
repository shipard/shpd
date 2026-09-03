<?php

declare(strict_types=1);

namespace Shipard\Core\Document;

/**
 * Hook na události dokumentů pro cizí moduly — modul se zahákne na změny
 * dokumentů tabulky, kterou nevlastní, aniž by vlastník tabulky závisel
 * na něm (např. economy.accounting účtuje docs_core_heads; později sklad,
 * saldo). Registrace v `module.jsonc` → `documentEventHandlers:
 * [{table, class, events}]`, dispatch zajišťuje TableGateway přes
 * DocumentEventDispatcher.
 *
 * Konkrétní handlery dědí z AbstractDocumentEventHandler (settery služeb).
 */
interface DocumentEventHandler
{
    /**
     * Uvnitř save transakce, po `Document::beforeSave()` a PŘED zápisem
     * hlavičky — handler smí `$data` mutovat (dopočítat sloupce hlavičky
     * vlastněné cizím modulem, např. extension sloupce). Child sety jsou
     * v `$data` ještě přítomné. Výjimka se propaguje — transakce se
     * rollbackne a uložení selže (handler je součást konzistence zápisu).
     *
     * @param array<string, mixed> $data Data dokumentu po beforeSave (mutable)
     * @param ?array<string, mixed> $originalData Původní řádek při update, null při insertu
     */
    public function onBeforeSave(string $tableId, array &$data, ?array $originalData): void;

    /**
     * Po commitu uložení, po `Document::afterSave()` — pro každé uložení,
     * bez ohledu na změnu docState. Výjimku dispatcher zaloguje a spolkne
     * (uložení už proběhlo). Vedlejší efekty musí být idempotentní.
     *
     * @param array<string, mixed> $data Uložená data dokumentu (head + child sety)
     * @param ?array<string, mixed> $originalData Původní řádek při update, null při insertu
     */
    public function onAfterSave(string $tableId, array $data, ?array $originalData): void;

    /**
     * Po commitu uložení, pokud se změnil docState (volá se po afterSave).
     * Handler si případné transakce řídí sám. Výjimku dispatcher zaloguje
     * a spolkne — uložení dokladu už proběhlo a nesmí selhat kvůli handleru.
     *
     * Pro nový záznam vzniklý rovnou v jiném stavu než 10 (import) je
     * $oldState = 0.
     *
     * @param array<string, mixed> $data Uložená data dokumentu (head + child sety)
     */
    public function onStateChanged(string $tableId, array $data, int $oldState, int $newState): void;

    /**
     * Uvnitř delete transakce, před mazáním child tabulek. Handler maže
     * závislá data (řádky deníku apod.). Výjimka se propaguje — transakce
     * se rollbackne a dokument zůstane netknutý.
     *
     * @param array<string, mixed> $data Data dokumentu před smazáním
     */
    public function onBeforeDelete(string $tableId, array $data): void;
}
