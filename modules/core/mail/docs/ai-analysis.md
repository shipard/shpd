# Fáze 3a: AI analýza došlých zpráv

Tento dokument popisuje architekturu, datový tok a životní cyklus AI analýzy
v modulu `core.mail`. Spec: [tasks/mail-phase3a.md](../../../../tasks/mail-phase3a.md).

## Cíl

Z došlé zprávy v `core_mail_incoming_messages` strojově extrahovat business
dokumenty (přijatá faktura, dobropis, …) a nabídnout je uživateli k review/použití.
Vlastní extrakci dělá **externí analyzer daemon** (samostatný repozitář
`ai_analyzer`, vyvíjeno v paralelní Fázi 3b). Shipard je server-of-record:
spravuje frontu, claimy, ukládá výsledky, řídí review workflow.

## Pull-based protokol

Externí analyzer drží trvale token a periodicky volá:

```
GET  /api/v1/_mail/analysis/queue
POST /api/v1/_mail/analysis/{ndx}/claim
GET  /api/v1/_mail/analysis/{ndx}/payload
GET  /api/v1/_mail/analysis/{ndx}/attachments/{att_ndx}/content
POST /api/v1/_mail/analysis/{ndx}/result
POST /api/v1/_mail/analysis/{ndx}/failed
```

Auth: `Bearer shpd_ak_…` token systémového uživatele `_ai_analyzer`. Vygeneruje
ho `ai-analyzer-setup` CLI; zobrazí se jednou.

### Životní cyklus jednoho běhu

```
1. analyzer GET /queue                  ← seznam zpráv s docState=10
2. analyzer POST /{ndx}/claim           ← atomicky: docState 10→20, vznikne claim row,
                                            response obsahuje plaintext API klíč backendu
                                            (jen v paměti; Cache-Control: no-store)
3. analyzer GET /{ndx}/payload          ← subject, body, metadata příloh
4. analyzer GET /{ndx}/attachments/.../content   ← streamuje binární obsah
5. analyzer ➜ provider (Anthropic, ...) ← analyzer.provider extrahuje, vrátí JSON
6. analyzer POST /{ndx}/result          ← uloží message_analyses + extracted_documents,
                                            uvolní claim, docState 20→30 (nebo 20→40
                                            pokud žádné extracted_documents)
```

Při chybě:

```
2'. POST /{ndx}/failed { retryable: true }   ← docState 20→10 (vrátí do queue)
2'. POST /{ndx}/failed { retryable: false }  ← docState 20→70 ("Chyba AI", manuální zásah)
```

Pokud analyzer mezi `claim` a `result` spadne, `mail-analysis-reap` (cron 1×/min)
najde claim s `expires_at < now()`, označí ho `released=true` s reason `expired`
a vrátí zprávu zpět na `docState=10` (jen pokud je stále ve 20 — manuální
override má přednost).

### Atomicita a souběh

`POST /claim` běží v transakci s `SELECT … FOR UPDATE` na řádku zprávy →
serializuje souběžné claim() přes stejnou zprávu. MariaDB nepodporuje partial
unique index `(message) WHERE released=0`, invariant "max jedna aktivní claim
per zpráva" tedy vynucuje aplikační kód v claim controlleru.

`POST /result` v jedné transakci: `INSERT message_analyses` → `INSERT
extracted_documents` (po jednom, status podle confidence vs profile thresholds)
→ `UPDATE claims SET released=1` → `UPDATE messages SET docState=30/40`. Při
selhání se vše rollbackuje.

## Šifrování API klíčů backendů

`core_ai_backends.api_key` je sloupec typu `encrypted_text` (viz
[docs/operations/secrets.md](../../../../docs/operations/secrets.md)).
`AIBackendDocument::beforeSave()` šifruje hodnotu přes `DsSecretCipher` při
dirty change; `AnalysisController::claim()` ji decryptuje a vkládá plaintext do
JSON response s `Cache-Control: no-store, no-cache, must-revalidate`.

**Bezpečnostní invarianty (CLAUDE.md "Citlivá data" + spec §10 dec.2):**

- Plaintext nikdy neleží v DB.
- Plaintext nikdy nejde do logu — `claim()` catch maskuje výjimku fixní zprávou
  a detail loguje pouze server-side přes `error_log()`.
- Empty submit do form fieldu `api_key` → `AIBackendDocument::beforeSave` ho
  unsetne (UPDATE nepřepíše ciphertext).
- Bez injektovaného `DsSecretCipher` Document hodí výjimku → CLI / API nikdy
  silently nezapíše plaintext.

## Stavy zprávy

`core.mail.docStatesIncoming` v Fázi 3a doplňuje stav 70 "Chyba AI". Plný
automat:

```
10 (Nová) ──claim──▶ 20 (V analýze) ──result──▶ 30 (Analyzovaná)
                          │                           │
                          ├──failed retryable──▶ 10   ├──user resolves all docs──▶ 40 (Zpracovaná)
                          │                           │
                          └──failed permanent──▶ 70   └──reanalyze──▶ 10

70 (Chyba AI) ──reanalyze──▶ 10  nebo manuálně ──▶ 40 / 80 / 90
30 / 40 / 70 / 80 / 90  (manual transitions per docStatesIncoming.jsonc)
```

## Stavy extrahovaných dokumentů

`core.mail.extractedDocStates`:

| Kód | ID | Význam |
|---|---|---|
| 10 | `ready_to_apply` | confidence ≥ 0.9 — UI nabízí jen "Použít" |
| 20 | `pending_review` | 0.6 ≤ confidence < 0.9 — default po extrakci |
| 30 | `low_confidence` | confidence < 0.6 — vyžaduje pečlivý review |
| 40 | `applied` | uživatel potvrdil (entita vznikne v Fázi 3c) |
| 50 | `rejected` | uživatel zamítl jako false positive |
| 60 | `superseded` | nahrazen novou analýzou (po reanalyze) |
| 70 | `ai_failed` | AI nemohla extrahovat (nečitelné PDF apod.) |

Mapping confidence → status řídí pole `confidence_thresholds` v profilu:
`{"ready": 0.9, "review": 0.6}`.

## Auto-transition 30 → 40

Když uživatel přes UI přepne všechny extracted documents do `applied/rejected/
superseded` (a žádný nezůstane v `ready/pending/low`), zpráva sama přejde
z docState=30 (Analyzovaná) na 40 (Zpracovaná). Trigger: explicit hook
`ExtractedDocumentDocument::afterPersist()` — běží uvnitř save transakce, takže
přechod je atomický. Stav `ai_failed` přechodu nebrání (admin se může rozhodnout
zprávu zavřít i s neúspěšnou extrakcí).

## Reanalýza

`POST /api/v1/_mail/messages/{ndx}/reanalyze` — UI akce viditelná v toolbaru
detail panelu, jen když docState ∈ {30, 70}. Volitelný `profile_override_ndx`
v body. Logika:

1. Validuj stav (30 nebo 70).
2. Validuj profile_override (pokud zadán) — musí existovat a být `is_active=1`.
3. UPDATE existing extracted_documents WHERE status IN (10, 20, 30) → `60` (superseded).
   Statusy 40 (applied) a 50 (rejected) **zůstávají** beze změny.
4. UPDATE message: `needs_reanalysis=1`, `profile_override`, `docState→10`.

Analyzer při dalším GET /queue zprávu uvidí včetně override profilu.

## UI detail panelu

`IncomingMessagesViewer` generuje 5 tabů:

1. **Obsah** — subject, sender, body
2. **Přílohy** — content attachments (bez raw .eml)
3. **Analýzy** — list běhů z `core_mail_message_analyses` (čas, model, prompt,
   confidence, počet extracted docs, cost, duration)
4. **Extrahované dokumenty** (Fáze 3a) — karty s typem, status badge, confidence,
   summary; akce per dokument: "Zobrazit detail" (modal s raw JSON),
   "Použít" (POST `/_mail/extracted-documents/{id}/apply`), "Zamítnout" (modal
   s povinným důvodem, POST `/_mail/extracted-documents/{id}/reject`).
   Tyto endpointy procházejí přes `ExtractedDocumentDocument::afterPersist`,
   takže auto-transition zprávy 30→40 funguje atomicky.
5. **Originál** — raw `.eml` pokud existuje

Toolbar v detailu obsahuje "Otevřít" (form edit) a "Znova analyzovat"
(podmíněně dle docState).

## Auto-provisioning

Při každém `ds-upgrade` se zavolá `AIAnalyzerProvisioner::provision()`:

1. Systémový uživatel `_ai_analyzer` (idempotentně).
2. Default backend (`backend_id=default`, `provider=anthropic`,
   `model=claude-sonnet-4-5`, `api_key=NULL`, `is_active=0`) — admin doplní
   klíč přes `ai-analyzer-set-key`, čímž `is_active=1`.
3. Default profil (`profile_id=czech_invoices`) ze šablony
   `profiles/default_czech_invoices.jsonc`.

Bootstrap je idempotentní — když existuje jiný profil/backend s `is_default=1`,
default *se nepřepíše*; admin zachová svůj override.

## Reference

- [tasks/mail-phase3a.md](../../../../tasks/mail-phase3a.md) — kompletní spec
- [docs/operations/secrets.md](../../../../docs/operations/secrets.md) — DsSecretCipher
- [docs/mail/api-contract.md](../../../../docs/mail/api-contract.md) — API kontrakty
- [ai-prompts.md](ai-prompts.md) — default prompt + customization guidelines
