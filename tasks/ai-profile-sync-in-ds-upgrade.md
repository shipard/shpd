# Task: Automatický sync AI profilu v `ds-upgrade`

**Status:** k implementaci (Claude Code)
**Schválená rozhodnutí:** D1–D5 (viz níže)

## Cíl

Při `ds-upgrade` automaticky aktualizovat AI profil z JSONC šablony v repu,
pokud šablona obsahuje novější `prompt_version` než DB. Odpadá nutnost
manuálního `ai-profile-reload` po každém deployi při ladění promptů na
reálných datech. Stávající analýzy zůstávají netknuté (každý záznam analýzy
si nese vlastní `prompt_version`); nové zprávy se analyzují novou verzí.

## Návaznost

- `tasks/ai-profile-reload.md` — původní manuální CLI příkaz; jeho update
  logika se tímto taskem extrahuje do provisioneru.
- `modules/core/mail/src/AIAnalyzerProvisioner.php` — dnes profil pouze
  vytváří (`ensureDefaultProfile`), existující přeskakuje.
- `src/Command/DataSource/DsUpgradeCommand.php` — `provisionAiAnalyzer()`
  volá provisioner uvnitř `else` větve `skipProvisioning` gatu.

## Schválená rozhodnutí

- **D1** — Update logika (version compare + update obsahových polí) se
  přesune z `AiProfileReloadCommand` do `AIAnalyzerProvisioner` jako
  `syncProfileFromTemplate()`. Command zůstane tenký wrapper.
- **D2** — `ds-upgrade` volá sync po `ensureDefaultProfile`. Šablona novější
  → update + `[UPDATE]` log. Verze shodná → no-op. DB novější → `[WARN]`,
  žádný downgrade (implicitní `--force` z `ds-upgrade` nikdy).
- **D3** — Admin-controlled pole (`name`, `is_default`, `is_active`,
  `backend`) se nikdy nepřepisují. Repo šablona je source of truth pro
  obsahová pole.
- **D4** — Sync zůstává uvnitř `skipProvisioning` gatu (ve
  `provisionAiAnalyzer`), na rozdíl od clearing infrastruktury. Není to
  enginový kontrakt.
- **D5** — Scope pouze default šablona
  (`modules/core/mail/profiles/default_czech_invoices.jsonc`). Discovery
  více profilů odloženo; signatura metody ale bere cestu k šabloně jako
  volitelný parametr.

## Scope

### 1. `modules/core/mail/src/AIAnalyzerProvisioner.php`

Nová veřejná metoda:

```php
/**
 * Synchronizuje obsahová pole existujícího profilu z JSONC šablony,
 * pokud je šablona novější (SemVer na prompt_version). Nikdy nesahá
 * na admin pole (name, is_default, is_active, backend) a nikdy
 * nedowngraduje.
 *
 * @return array{
 *     status: 'updated'|'up_to_date'|'db_newer'|'not_found',
 *     profile_id: string,
 *     id?: int,
 *     old_version?: string,
 *     new_version?: string
 * }
 */
public function syncProfileFromTemplate(?string $templatePath = null): array
```

Chování:

1. Načte šablonu přes `self::loadProfileTemplate($templatePath)`
   (RuntimeException ze šablony propagovat — volající rozhodne).
2. `SELECT id, prompt_version FROM core_mail_ai_profiles WHERE profile_id = %s`
   podle `profile_id` ze šablony.
   - Řádek neexistuje → `status: 'not_found'` (nevytváří — od toho je
     `ensureDefaultProfile`).
3. Version compare (přesunout `compareVersions()` z
   `AiProfileReloadCommand` sem jako `private static`, včetně stripování
   `v`/`V` prefixu):
   - šablona == DB → `status: 'up_to_date'`, žádný zápis.
   - šablona < DB → `status: 'db_newer'`, žádný zápis.
   - šablona > DB → UPDATE a `status: 'updated'`.
4. UPDATE přesně stejných polí jako dnešní `AiProfileReloadCommand`:
   `prompt_template`, `prompt_version`, `language`,
   `supported_doc_types` (json_encode s `JSON_UNESCAPED_UNICODE |
   JSON_UNESCAPED_SLASHES`), `output_schema` (dtto),
   `confidence_thresholds` (dtto), `modified = now`.

Volitelně druhá metoda pro force cestu, aby command nemusel duplikovat
UPDATE:

```php
public function forceWriteProfileFromTemplate(?string $templatePath = null): array
```

(stejný UPDATE bez version guardu — použije ji command při `--force`).

### 2. `src/Command/DataSource/DsUpgradeCommand.php`

Ve `provisionAiAnalyzer()` po zpracování výsledku `provision()`:

```php
$sync = $provisioner->syncProfileFromTemplate();
match ($sync['status']) {
    'updated' => $output->writeln(
        "  [UPDATE] profile '{$sync['profile_id']}': {$sync['old_version']} → {$sync['new_version']}"),
    'up_to_date' => $output->writeln(
        "  [OK]     profile '{$sync['profile_id']}' at {$sync['old_version']}",
        OutputInterface::VERBOSITY_VERBOSE),
    'db_newer' => $output->writeln(
        "  <comment>[WARN]   profile '{$sync['profile_id']}': DB version {$sync['old_version']} is newer than template {$sync['new_version']} — not downgrading. Use 'ai-profile-reload --force' if intended.</comment>"),
    'not_found' => null, // ensureDefaultProfile ho právě vytvořil nebo skipnul kvůli jinému defaultu
};
```

Pozn.: pokud `ensureDefaultProfile` profil právě vytvořil, sync vrátí
`up_to_date` (verze shodné) — žádný duplicitní log ve výchozí verbosity.

Chybu šablony (`RuntimeException` z `loadProfileTemplate`) zachytit a
vypsat jako `<comment>[WARN]</comment>` — rozbitá šablona nesmí shodit
celý `ds-upgrade`.

### 3. `src/Command/DataSource/AiProfileReloadCommand.php`

Refaktor na wrapper:

- Version compare a UPDATE delegovat na provisioner
  (`syncProfileFromTemplate` / `forceWriteProfileFromTemplate`).
- Zachovat beze změny chování: `--profile` mismatch check, `--template-path`,
  `--dry-run` (dry-run zůstává v commandu — provisioner nic dry-run
  nepotřebuje, command si jen přečte verze a délky a vypíše),
  `--force` (downgrade i same-version overwrite), exit kódy a texty hlášek.
- Smazat privátní `compareVersions()` (přesunuta do provisioneru).

### 4. Dokumentace

- `docs/cli.md` — u `ds-upgrade` doplnit zmínku, že součástí provisioning
  fáze je i sync AI profilu ze šablony (upgrade-only, nikdy downgrade);
  u `ai-profile-reload` doplnit, že běžný upgrade probíhá automaticky
  v `ds-upgrade` a manuální příkaz slouží pro `--force`/`--dry-run`/
  `--template-path` scénáře.
- `modules/core/mail/docs/ai-prompts.md` (sekce „Iterativní ladění
  promptu") — zjednodušit workflow: úprava šablony + bump `prompt_version`
  + deploy + `ds-upgrade` stačí.
- `docs/ai.md` — pokud popisuje lifecycle profilů, doplnit odstavec o
  automatickém syncu.

## Testy

### `tests/Unit/Module/Core/Mail/AIAnalyzerProvisionerTest.php`

Doplnit pro `syncProfileFromTemplate()`:

1. šablona novější → UPDATE proběhl, správná pole, `status: 'updated'`.
2. verze shodné → žádný UPDATE, `status: 'up_to_date'`.
3. DB novější → žádný UPDATE, `status: 'db_newer'`.
4. profil neexistuje → `status: 'not_found'`, žádný INSERT.
5. `v` prefix tolerance (`v1.2.0` vs `1.1.0` → updated).
6. admin pole (`name`, `is_default`, `is_active`, `backend`) nejsou
   v UPDATE payloadu.

### `tests/Unit/Command/DataSource/AiProfileReloadCommandTest.php`

Upravit podle refaktoru — chování commandu se nemění, testy by měly
projít s minimálními úpravami (mock provisioneru místo přímých DB
očekávání tam, kde to dává smysl).

### DsUpgrade

Pokud existuje test pokrývající `provisionAiAnalyzer`, doplnit case na
`[UPDATE]`/`[WARN]` výstup; jinak nechat na integrační ověření.

## Commit strategie

1. `refactor(mail): extract AI profile sync logic into AIAnalyzerProvisioner`
   — provisioner + refaktor commandu + testy provisioneru.
2. `feat(ds-upgrade): auto-sync AI profile from template during upgrade`
   — DsUpgradeCommand + testy.
3. `docs(cli,ai): document automatic AI profile sync in ds-upgrade`

Po každém patchi: `php -l`, `git diff`, pak
`vendor/bin/phpunit --filter AIAnalyzerProvisionerTest --testsuite Unit`
a `--filter AiProfileReloadCommandTest`.

## Hotovo když

- [ ] `AIAnalyzerProvisioner::syncProfileFromTemplate()` existuje, pokrytá
      testy (6 casů výše), `compareVersions` přesunuta z commandu.
- [ ] `ds-upgrade` na DS se starší verzí profilu vypíše `[UPDATE] ...` a
      DB obsahuje nová obsahová pole; admin pole netknutá.
- [ ] `ds-upgrade` na DS se shodnou verzí je no-op (výchozí verbosity bez
      řádku o profilu).
- [ ] `ds-upgrade` na DS s novější verzí než šablona vypíše `[WARN]` a
      nic nepřepíše.
- [ ] Rozbitá/chybějící šablona neshodí `ds-upgrade` (jen `[WARN]`).
- [ ] `ai-profile-reload` se chová stejně jako dosud (vč. `--force`
      downgradu a `--dry-run`), duplicitní logika odstraněna.
- [ ] Dokumentace aktualizována (`docs/cli.md`, `ai-prompts.md`).
- [ ] Všechny dotčené unit testy zelené.
