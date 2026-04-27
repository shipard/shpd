# Task: `ai-profile-reload` CLI příkaz

**Motivace:** Při ladění promptu pro AI extraction (Fáze 3a/3b) potřebujeme
opakovaně updateovat `prompt_template`, `prompt_version` a `output_schema`
existujícího profilu v DB ze JSONC šablony v repu. Workflow:

1. Vývojář upraví `modules/core/mail/profiles/default_czech_invoices.jsonc`
2. Commit do gitu, deploy
3. Na produkci spustí `bin/shpd-ds ai-profile-reload`
4. Re-queue failed zprávy přes SQL nebo "Znova analyzovat" v UI

Aktuálně existuje provizorní skript `scripts/ai-profile-reload.php` —
plnohodnotný CLI příkaz nahradí.

---

## Co je potřeba udělat

### 1. Nový command `AiProfileReloadCommand`

**Soubor:** `src/Command/DataSource/AiProfileReloadCommand.php`

Pattern podle existujících `AiAnalyzer*Command` tříd. Konkrétně:

```php
class AiProfileReloadCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('ai-profile-reload')
             ->setDescription('Reload AI profile from JSONC template into the DB')
             ->addOption('profile', null, InputOption::VALUE_REQUIRED,
                 'Profile code (default: "czech_invoices")', 'czech_invoices')
             ->addOption('force', null, InputOption::VALUE_NONE,
                 'Overwrite even if DB version >= template version')
             ->addOption('dry-run', null, InputOption::VALUE_NONE,
                 'Show what would change without writing');
    }
}
```

### 2. Logika

Reuse `AIAnalyzerProvisioner::loadProfileTemplate()` (extract jako public
method, aktuálně je protected).

Postup:

1. Načti JSONC šablonu
2. Najdi profil v DB podle `profile_id`
3. Pokud chybí → fail s instrukcí "Run ai-analyzer-bootstrap first"
4. Compare `prompt_version` v DB a v šabloně:
   - Stejné → vypíše "already at version X" a skončí (bez `--force`)
   - DB má vyšší než šablona → varuje (možná downgrade) a skončí
     (bez `--force`)
   - Šablona je novější → pokračuje
5. Update polí `prompt_template`, `prompt_version`, `output_schema`,
   `supported_doc_types`, `confidence_thresholds`, `language`, `modified`
6. **Nepřepisuje:** `name`, `is_default`, `is_active`, `backend` —
   admin si je může lokálně upravit
7. Při `--dry-run`: zobrazí diff (alespoň `prompt_version` před/po,
   délka prompt_template před/po), neprovede update

### 3. Registrace

V `bin/shpd-ds` přidat:

```php
$app->add(new \Shipard\Command\DataSource\AiProfileReloadCommand());
```

### 4. Ošetření okrajových případů

- **DS bez profilu** (před bootstrap) → instruktivní chyba
- **JSONC syntax error** → propagovat z `JsoncParser` s file path
- **Šablona neexistuje** → command přijme `--template-path` jako volitelný
  override, default cesta `modules/core/mail/profiles/default_czech_invoices.jsonc`
- **Multiple profiles s různými profile_id** → command zpracuje jen ten
  z `--profile` option (default `czech_invoices`)

### 5. Smazání provizorního skriptu

Po dokončení smazat `scripts/ai-profile-reload.php`.

### 6. Dokumentace

- Update `modules/core/mail/docs/ai-analysis.md` (nebo `ai-prompts.md`,
  pokud existuje) — sekce "Iterativní ladění promptu":
  ```
  1. Uprav modules/core/mail/profiles/default_czech_invoices.jsonc
  2. Bump prompt_version (semver)
  3. Commit
  4. Na produkci: bin/shpd-ds ai-profile-reload
  5. Re-queue zprávy v UI ("Znova analyzovat") nebo SQL
  ```

### 7. Testy

`tests/Unit/Command/AiProfileReloadCommandTest.php`:

- Profile chybí → exit code 1
- Profile aktuální → "no update needed", žádný DB change
- Šablona novější → update probíhá, kontrola změněných sloupců
- `--dry-run` → žádný DB change
- `--force` → update i když verze stejná

---

## Akceptační kritéria

- `bin/shpd-ds ai-profile-reload` funguje z DS adresáře
- `bin/shpd-ds help ai-profile-reload` zobrazí help
- `prompt_version` blokuje akcidentální downgrade bez `--force`
- Žádný side effect na `name`, `is_default`, `is_active`, `backend`,
  `id`, `created`
- Dokumentace popisuje workflow iterativního ladění promptu
- Provizorní skript v `scripts/` smazán

---

## Mimo rozsah

- **Hot reload v běžícím analyzeru** — analyzer při každém claim získá
  čerstvý prompt z DB, takže reload neovlivní běžící zpracování (jen
  nové claim-y po reload). To je správné chování, není potřeba zvláštní
  řešení.
- **Profile diff UI** — nice-to-have, ale teď stačí CLI + git diff na
  JSONC. Samostatný UX úkol pokud bude motivace.
- **Per-environment profil overrides** — vzniká při skutečné potřebě
  (dev má jinak naladěný prompt než prod). Pro MVP žádné, default profil
  je univerzální.
