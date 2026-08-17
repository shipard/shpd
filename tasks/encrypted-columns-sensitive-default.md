# encrypted_text sloupce — sensitive by default (oprava 500 při editaci AI backendu)

**Stav:** hotovo
**Repo:** nov_shipard

## Cíl

Editace AI backendu přes UI (form `core_ai_backends`, např. změna
`max_tokens`) končí `Vnitřní chyba serveru`. Řetězec:

1. `core_ai_backends.api_key` (typ `encrypted_text`) NEMÁ flag
   `"sensitive": true` — jako jediný encrypted_text sloupec v systému
   (flag se ztratil při extrakci core.ai z core.mail; předloha
   `core_mail_senders.smtp_password` ho má).
2. `FormController::meta` proto ciphertext NEodstřihne
   (`stripSensitive` pracuje jen se sensitive sloupci) → ciphertext
   odchází do prohlížeče.
3. FormEditor drží celý záznam (`formData = { ...defaults, ...res.data.data }`,
   FormEditor.svelte:131) a při save ho celý posílá zpět.
4. `rejectSensitiveInput` ho nechytí (není sensitive),
   `filterWritableFields` ho pustí (není system) →
   `AIBackendDocument::beforeSave` vidí neprázdný `api_key` bez
   injektovaného cipheru → `RuntimeException` → 500.

Výjimka v `beforeSave` paradoxně zafungovala jako poslední bariéra: kdyby
tam nebyla (nebo kdyby FormController cipher injektoval), save by vrácený
ciphertext ZNOVU zašifroval a uložený API klíč tiše zničil.

Bariérová infrastruktura (`stripSensitive` na čtení,
`rejectSensitiveInput` na zápis, placeholder mechanismus v `TableForm`
pro opt-in editaci) existuje a je správně zapojená — chybí jen flag.
Oprava = doplnit flag + systémová pojistka, aby `encrypted_text` bez
`sensitive` nemohl nikdy znovu vzniknout.

## Návaznost

- `src/Core/Database/ColumnDefinition.php` — `fromArray()`, flag
  `sensitive` (řádek ~106).
- `modules/core/ai/tables/core_ai_backends.jsonc` — sloupec `api_key`
  (řádek ~127).
- `src/Api/TableAccessGuard.php` — `stripSensitive`,
  `rejectSensitiveInput` (beze změny, jen reference).
- `src/Core/Database/SchemaIntrospector.php` —
  `findEncryptedColumns()` (hodí se pro test).
- Inventura encrypted_text sloupců (všechny ostatní sensitive mají):
  `core_mail_senders`, `hosting_core_ai_tokens`,
  `hosting_core_data_sources` (2×), `core_ai_backends.api_key` (JEDINÝ bez).

## Scope

### 1. `src/Core/Database/ColumnDefinition.php` — D8: typ implikuje flag

Ve `fromArray()`:

```php
sensitive: (bool) ($data['sensitive'] ?? false)
    || ($data['type'] ?? null) === SchemaIntrospector::ENCRYPTED_COLUMN_TYPE,
```

(resp. ekvivalentní čitelný zápis). `encrypted_text` sloupec je sensitive
vždy, bez ohledu na jsonc; explicitní `"sensitive": false` u
encrypted_text je nesmysl a derivace ho záměrně přebije.

### 2. `modules/core/ai/tables/core_ai_backends.jsonc`

Doplnit `"sensitive": true` k `api_key` — po D8 redundantní, ale
explicitní zápis drží konzistenci se všemi ostatními encrypted_text
sloupci a čitelnost definice.

### 3. Testy

- Unit `ColumnDefinition`: `type=encrypted_text` bez flagu →
  `sensitive === true`; s explicitním flagem → true; ne-encrypted typ
  bez flagu → false.
- Regresní scénář form flow (úroveň stávajících testů FormControlleru,
  pokud existují; jinak unit na guard): záznam s encrypted_text sloupcem
  → `stripSensitive` ho z form-load dat odstraní; save payload
  obsahující `api_key` → `rejectSensitiveInput` vrací 400
  `SENSITIVE_COLUMN`.
- PHPUnit úzký `--filter`, `timeout_sec: 120`.

### 4. Dokumentace

`docs/operations/secrets.md` (referencovaná z AIBackendDocument):
doplnit větu, že `encrypted_text` implikuje `sensitive` automaticky a
flag v jsonc je od teď volitelná explicitnost.

## Chování po opravě

- Form-load `core_ai_backends` nevrací `api_key` → FormEditor ho nemá
  a neposílá → editace `max_tokens` (a čehokoli dalšího) projde.
- Klient, který by `api_key` poslal, dostane 400 `SENSITIVE_COLUMN`
  (existující chování guardu), ne 500.
- Nastavení klíče zůstává výhradně na dedikovaných cestách s cipherem
  (`AiAnalyzerSetKeyCommand`, hosting commandy).
- Žádná DB migrace; `sensitive` je runtime vlastnost definice.

## Nasazení

Rebuild compiled cfg po jsonc změně (standardně); `ds-upgrade` není
třeba (žádná schema změna).

## Hotovo když

- [x] `ColumnDefinition` derivuje sensitive z typu encrypted_text
- [x] `core_ai_backends.jsonc` má u `api_key` explicitní flag
- [x] Testy dle scope zelené
- [x] Manuální ověření: oprava `max_tokens` na AI backendu přes UI
      projde a uložený `api_key` v DB zůstal beze změny (ciphertext
      identický před/po save) — ověřeno 17. 8. 2026
- [x] `secrets.md` aktualizované

## Commit strategie

1. `fix(core): encrypted_text columns are sensitive by default — oprava 500 při editaci AI backendu` (vč. testů a docs)

## Potvrzená rozhodnutí

- **D8** — `encrypted_text` implikuje `sensitive` automaticky
  v `ColumnDefinition::fromArray`; u `core_ai_backends.api_key` doplnit
  i explicitní flag. (potvrzeno 16. 8. 2026)
- **D9** — původně navržená read/write bariéra pro encrypted_text se
  NEIMPLEMENTUJE: `stripSensitive` + `rejectSensitiveInput` +
  placeholder mechanismus `TableForm` už přesně tohle dělají; problém
  byl výhradně chybějící flag. (uzavřeno 16. 8. 2026)
