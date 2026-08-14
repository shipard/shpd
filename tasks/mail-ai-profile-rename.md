# Modul `mail` — Přejmenování AI profilu `czech_invoices` → `czech_general`

**Stav:** k implementaci
**Repo:** `nov_shipard` (jediné dotčené — `ai_analyzer` profile_id jen loguje,
`old_shipard` se profilů nedotýká)

**Cíl:** Profil „České faktury (default)" (`czech_invoices`) už dávno není
fakturový — analyzuje došlou poštu obecně včetně ne-dokladových dokumentů
(spisovna). Identifikátor i název se přejmenují tak, aby odpovídaly realitě:

| | staré | nové |
|---|---|---|
| `profile_id` | `czech_invoices` | `czech_general` |
| `name` | České faktury (default) | Obecná analýza pošty (česky) |
| šablona | `profiles/default_czech_invoices.jsonc` | `profiles/czech_general.jsonc` |

## Rozhodnutí (potvrzena, D1–D4)

1. **D1 — id `czech_general`.** Id říká, co profil dělá (obecná analýza
   české pošty), ne jakou má roli — `is_default` je samostatný přepínatelný
   flag a do identifikátoru nepatří. Šablona se přejmenuje podle id.
2. **D2 — Idempotentní rename krok v `AIAnalyzerProvisioner`** (běží
   v `ds-upgrade`), **před** sync promptu: existující profil se starým id
   se přejmenuje (id + name). Alfa DS dostanou nové id čerstvě po
   `ds-reset`; krok pokrývá dev DS a cokoliv, co se neresetuje. Po
   přejmenování navždy matchne 0 řádků — neškodný (vzor
   `fixQueuedArchivedMessages`).
3. **D3 — Úklid hardcodů:** výpisy `DsUpgradeCommand` berou kód profilu
   z výsledku provisioneru/šablony místo literálu; help text
   `AiProfileReloadCommand` aktualizovat.
4. **D4 — Dokumentace** (seznam níže); historická poznámka v exchange
   README zůstává (popisuje minulý stav v2.0.0).

**Beze změny:** obsah promptu a `prompt_version` (žádný bump — mění se jen
identita profilu), `output_schema`, numerické vazby (`profile_override`
zpráv, profil na řádcích analýz — FK přes číselné `id`), `ai_analyzer`
daemon, `old_shipard`.

## Scope

### 1. Šablona

- `git mv modules/core/mail/profiles/default_czech_invoices.jsonc
  modules/core/mail/profiles/czech_general.jsonc`
- Uvnitř: `"profile_id": "czech_general"`, `"name"` → „Obecná analýza
  pošty (česky)". Nic jiného se v šabloně nemění.

### 2. `modules/core/mail/src/AIAnalyzerProvisioner.php`

- `DEFAULT_PROFILE_ID = 'czech_general'`,
  `DEFAULT_PROFILE_TEMPLATE` → nová cesta.
- Nový krok `renameLegacyProfile()` volaný na začátku provisioning fáze
  profilu (před lookup/create i před sync promptu):

```php
UPDATE core_mail_ai_profiles
   SET profile_id = 'czech_general',
       name = 'Obecná analýza pošty (česky)',
       modified = NOW()
 WHERE profile_id = 'czech_invoices'
```

  Vrací počet přejmenovaných řádků do výsledku provisioneru (pro výpis
  `[RENAME] profile 'czech_invoices' → 'czech_general'` v ds-upgrade;
  0 řádků → žádný výpis). Pozn.: `name` se přepíše jen v rámci tohoto
  jednorázového rename kroku — jindy provisioner uživatelské úpravy
  názvu nepřepisuje (stávající chování sync zůstává).

### 3. CLI kosmetika (D3)

- `src/Command/DataSource/DsUpgradeCommand.php` — tři výpisy
  `profile 'czech_invoices'` → použít `$profile['profile_id']`
  (resp. kód z výsledku provisioneru) místo literálu; doplnit výpis
  RENAME větve.
- `src/Command/DataSource/AiProfileReloadCommand.php` — help texty
  (`typically "czech_invoices"` → `"czech_general"`, default cesta
  šablony). Funkčně beze změny — kód se odvozuje ze šablony už dnes.

### 4. Dokumentace (D4)

- `modules/core/mail/docs/ai-prompts.md` — nadpis, odkazy na šablonu,
  příklad provisioning výpisu, tabulka polí.
- `modules/core/mail/docs/ai-analysis.md` — §provisioning (profile_id,
  cesta šablony).
- `modules/core/mail/tables/core_mail_ai_profiles.md` — příklady id,
  odstavec o auto-provisioningu.
- `modules/core/mail/README.md` — odkaz na šablonu + text.
- `docs/mail/api-contract.md` — příklady `profile_id` v query/response.
- `docs/cli.md` — cesta šablony u ai-profile-reload.
- `modules/core/exchange/README.md` — beze změny (historická poznámka).

## Testy

- Test provisioneru (`--filter "AIAnalyzerProvisioner"`): rename kroku —
  DS se starým id → přejmenováno (id + name), druhý běh → 0 řádků;
  DS bez profilu → create s novým id; DS s novým id → sync beze změny.
- Ověřit, že stávající testy nereferencují `czech_invoices` literálem
  (grep v `tests/`), případně aktualizovat.

## Nasazení

- Součást běžného `ds-upgrade` — na alfě zapadne do už plánované
  sekvence před `ds-reset` (na resetovaných DS se rename neuplatní,
  profil vznikne rovnou s novým id; na neresetovaných proběhne rename).
- `ai-profile-reload --force` po nasazení funguje proti novému id
  (šablona je zdroj pravdy).

## Commit strategie

1. šablona + provisioner (rename krok) + testy,
2. CLI kosmetika,
3. dokumentace.

## Hotovo když

- [ ] Šablona `profiles/czech_general.jsonc` s novým id a názvem;
      stará šablona neexistuje.
- [ ] `ds-upgrade` na DS se starým profilem vypíše RENAME a profil má
      nové id + název; opakovaný běh nic nemění.
- [ ] `ds-upgrade` na čistém DS založí profil `czech_general`.
- [ ] `grep -rn czech_invoices` v repu nachází jen historickou poznámku
      v `modules/core/exchange/README.md` (+ tento task).
- [ ] PHPUnit zelené.
