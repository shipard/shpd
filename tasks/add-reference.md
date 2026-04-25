# Task: Přidání `reference` a `displayPattern`

Přečti `docs/table-definitions.md` — konkrétně:
- Sekci 4 (Metadata tabulky) — nové pole `displayPattern`
- Sekci 5 (Definice sloupců) — nové pole `reference`
- Sekci 12 (Kompletní příklady) — aktualizované příklady

## Co přidat

### 1. `TableDefinition` — nové pole `displayPattern`

Přidej nepovinné pole `displayPattern` (string nebo null):
- Šablona s placeholdery `{column_id}` pro zobrazení záznamu při referenci z jiné tabulky
- Načítá se z JSONC definice
- Nepovinné — výchozí null

### 2. `ColumnDefinition` — nové pole `reference`

Přidej nepovinné pole `reference` (string nebo null):
- ID cílové tabulky (např. `"base_persons_contacts"`)
- Čistě metadata pro UI, nemá vliv na generování SQL (žádná FOREIGN KEY)
- Nepovinné — výchozí null

### 3. Aktualizuj testy

**`TableDefinitionTest`:**
- Test načtení tabulky s `displayPattern`
- Test načtení tabulky bez `displayPattern` (null)

**`ColumnDefinitionTest`:**
- Test sloupce s `reference`
- Test sloupce bez `reference` (null)

### 4. Aktualizuj `modules/core/system/tables/core_system_users.jsonc`

Přidej `"displayPattern": "{full_name} ({login})"` do definice tabulky.

### 5. Ověření

- `vendor/bin/phpunit` — všechny testy musí projít
- `reference` se NESMÍ projevit v `SqlGenerator` (žádný FOREIGN KEY v SQL)
- `displayPattern` se NESMÍ projevit v `SqlGenerator` (čistě UI metadata)
