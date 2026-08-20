<?php

declare(strict_types=1);

namespace Shipard\Core\Document;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Settings\SettingsStore;

abstract class Document
{
    protected array $data = [];
    protected array $originalData = [];
    protected ?\Dibi\Connection $db = null;
    protected ?ConfigRuntime $config = null;
    protected ?DataSourceConfig $dsConfig = null;
    protected ?SettingsStore $settings = null;

    /**
     * Přechod docState detekovaný v beforeSave (DocDocument::trackStateChange).
     * Null = stav se neměnil. Pro nový záznam vzniklý rovnou mimo Koncept
     * (import) je old = 0. Čte TableGateway po commitu pro dispatch
     * documentEventHandlers — gateway sám nic nedopočítává.
     *
     * @var array{old: int, new: int}|null
     */
    protected ?array $stateTransition = null;

    /**
     * True = save běží uvnitř transakce vlastněné volajícím (exchange
     * Applier přes TransactionlessTableGateway). Dokumentové hooky pak
     * NESMÍ otevírat vlastní transakci — MariaDB nemá nested START
     * TRANSACTION, druhý begin() by vnější transakci implicitně commitnul
     * (viz doc-comment TransactionlessTableGateway). Nastavuje gateway
     * v injectDocServices().
     */
    protected bool $externalTransaction = false;

    public function setDb(\Dibi\Connection $db): void
    {
        $this->db = $db;
    }

    public function setExternalTransaction(bool $external): void
    {
        $this->externalTransaction = $external;
    }

    public function setConfig(ConfigRuntime $config): void
    {
        $this->config = $config;
    }

    public function setDsConfig(DataSourceConfig $dsConfig): void
    {
        $this->dsConfig = $dsConfig;
    }

    /**
     * Sdílená instance per gateway/dávka (cache SettingsStore je per instance)
     * — dokument si NIKDY nekonstruuje vlastní store, při dávkovém zpracování
     * by to byl jeden dotaz na doklad.
     */
    public function setSettings(SettingsStore $settings): void
    {
        $this->settings = $settings;
    }

    public function validate(array &$data): ValidationResult
    {
        return new ValidationResult();
    }

    /**
     * Pre-save hook. Receives the original DB row as $originalData on update;
     * null on insert. Subclasses use originalData to detect what changed
     * (e.g. partner change → rebuild snapshot, docState transition → assign
     * number).
     */
    public function beforeSave(array &$data, ?array $originalData = null): void
    {
    }

    /**
     * Hook běžící uvnitř save transakce, PO INSERT/UPDATE hlavičky i child rows,
     * ale PŘED commitem. Použít, když má vedlejší efekt (např. UPDATE jiné
     * tabulky odvozené z nově uloženého stavu) zůstat atomický s persistem —
     * pokud zde dojde k výjimce, TableGateway transakci roluje zpět.
     *
     * Stav DB v tomto okamžiku obsahuje právě uložené řádky, takže lze
     * spolehlivě dotazovat sourozence vč. tohoto.
     */
    public function afterPersist(array $data): void
    {
    }

    /**
     * Hook běžící PO commitu. Vhodné pro idempotentní vedlejší efekty, které
     * nemají závislost na atomicitě (logování, posílání notifikací, atd.).
     */
    public function afterSave(array $data): void
    {
    }

    public function beforeDelete(array $data): void
    {
    }

    public function afterDelete(array $data): void
    {
    }

    public function onLoad(array &$data): void
    {
    }

    /**
     * @return array{old: int, new: int}|null
     */
    public function getStateTransition(): ?array
    {
        return $this->stateTransition;
    }
}
