<?php

declare(strict_types=1);

namespace Shipard\Core\Document;

abstract class Document
{
    protected array $data = [];
    protected array $originalData = [];
    protected ?\Dibi\Connection $db = null;

    public function setDb(\Dibi\Connection $db): void
    {
        $this->db = $db;
    }

    public function validate(array &$data): ValidationResult
    {
        return new ValidationResult();
    }

    public function beforeSave(array &$data): void
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
}
