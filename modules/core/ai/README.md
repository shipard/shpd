# Modul core/ai

Sdílená AI infrastruktura. Drží **LLM backendy** (`core_ai_backends`) —
provider, model, zašifrovaný API klíč (`encrypted_text`, viz
`docs/operations/secrets.md`). Backendy reuse-uje analýza pošty (`core/mail`),
budoucí chat/orchestrátor i další AI funkce; proto bydlí v samostatném modulu,
na kterém mail i chat stojí jako sourozenci (žádná obrácená závislost
chat → mail).

## Třídy

- `AIBackendDocument` — validace, invariant „max. jeden výchozí backend per DS",
  šifrování `api_key` v `beforeSave()`, `decryptApiKey()`.
- `AIBackendsViewer` — výpis/detail (klíč nikdy jako hodnota, jen `has_api_key`).
- `AIBackendLookup` — lookup pro FK `backend` (profily, analýzy).

Profily (`core_mail_ai_profiles`) jsou mail-specifické (extrakční prompty,
`output_schema`) a **zůstávají v `core/mail`**; jejich FK `backend` míří
cross-module na `core_ai_backends`.

## Migrace z core/mail (jednorázový operační krok per DS)

Tabulka se přejmenovala z `core_mail_ai_backends` na `core_ai_backends`.
`ds-upgrade` umí jen create/ALTER (žádný rename ani drop), proto se data
přenesou ručně **PŘED** upgradem:

```sql
RENAME TABLE core_mail_ai_backends TO core_ai_backends;
```

`RENAME` zachová řádky, zašifrované klíče i **`id`** — FK z
`core_mail_ai_profiles.backend` a `core_mail_message_analyses.backend`
(app-level int id) zůstanou platné. Po renameu `ds-upgrade` tabulku rozpozná
pod správným jménem a jen případně ALTER-uje. Nové DS založí `ds-create`
rovnou `core_ai_backends` a provisioner do něj naseje default backend.

## tableId

Backends si ponechávají `tableId 307` (RENAME zachovává identitu tabulky;
přečíslování by bylo zbytečná churn). Pro budoucí `core/ai` tabulky se
vyhradí nový rozsah.
