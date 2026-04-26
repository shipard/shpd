# Tabulka: Rezervace analýz (core_mail_analysis_claims)

Lease mechanismus pro pull-based AI analyzer. Když externí analyzer claimne
zprávu k analýze, vznikne záznam s `expires_at`. Reaper cron (`mail-analysis-reap`)
najde expirované rezervace a vrátí zprávu zpět do queue — to je recovery,
když analyzer mezi `claim` a `result` spadne.

## Struktura

### Identifikace (identity)

| Sloupec | Typ | Popis |
|---|---|---|
| `message` | int → `core_mail_incoming_messages`, NOT NULL | Zpráva, kterou analyzer drží |
| `analyzer_id` | varchar(64), NOT NULL | UUID instance analyzeru — self-reported v request body |
| `claim_token` | varchar(64), NOT NULL, UNIQUE | Server-generated tajný token. Analyzer ho posílá v hlavičce `X-Claim-Token` u všech navazujících requestů. |

### Rezervace (lease)

| Sloupec | Typ | Popis |
|---|---|---|
| `claimed_at` | datetime, NOT NULL | Čas vytvoření claim |
| `expires_at` | datetime, NOT NULL | Čas, po kterém je claim neplatná |

### Uvolnění (release)

| Sloupec | Typ | Popis |
|---|---|---|
| `released` | boolean, default false | True po `result`, `failed` nebo expiraci |
| `released_at` | datetime | Čas uvolnění |
| `release_reason` | varchar(30) | `result`, `failed`, `expired` |

## Indexy

| Index | Typ | Sloupce | Poznámka |
|---|---|---|---|
| `unq_claim_token` | unique | `claim_token` | Token musí být globálně unikátní |
| `idx_message_active` | index | `message`, `released` | Rychlé "má zpráva aktivní claim?" |
| `idx_expires_at` | index | `expires_at` | Reaper najde expirované |

## Invariant: max jedna aktivní claim per zpráva

Specifikace zmiňuje "partial unique `(message) WHERE released = false`".
MariaDB partial unique nepodporuje, takže invariant vynucujeme aplikačně —
`AnalysisController::claim()` před INSERT v rámci transakce ověří, že
ke zprávě neexistuje řádek se `released = false` (s expirací v budoucnu),
jinak vrátí 409 ALREADY_CLAIMED. Při expiraci reaper rezervaci uvolní a
další claim může proběhnout.

## Životní cyklus

1. **Claim**: `POST /_mail/analysis/{ndx}/claim` v transakci ověří, že zpráva
   je `docState=10` a nemá živou claim → vytvoří záznam, vrátí token,
   přepne zprávu na `docState=20`.
2. **Použití**: analyzer s tokenem volá `payload`, `attachments/{ndx}/content`.
3. **Result/Failed**: analyzer pošle výsledek → `released=true`,
   `release_reason='result'` nebo `'failed'`.
4. **Expirace**: reaper nastaví `released=true`, `release_reason='expired'`,
   přepne zprávu zpět na `docState=10`.

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [core_mail_incoming_messages](core_mail_incoming_messages.md) | `claims.message` → `incoming_messages.id` | Zpráva, kterou claim drží |

## Mazání

CASCADE delete při smazání zdrojové zprávy — řeší
`IncomingMessageDocument::beforeDelete`.
