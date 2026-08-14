# Modul `mail` — Zákaz AI analýzy per schránka

**Stav:** hotovo — 2026-08-14; zbývá ruční proklik checkboxu v prohlížeči a `ds-upgrade` ostatních DS (4l3j hotový)
**Repo:** `nov_shipard` (samostatná změna importní pipeline viz
`old_shipard: modules/imports/newShipard/tasks/26-mail-import-no-analysis.md` —
nasazuje se nezávisle, žádná vzájemná závislost).

**Cíl:** Umožnit vypnout AI analýzu pro celou schránku. Zprávy přicházející
do schránky s vypnutou analýzou se nefrontují a fronta je nevydává —
s výjimkou explicitního message-level override.

**Motivace:** Na zprávě existuje `ai_analysis_enabled` (tri-state override:
NULL = děděno, true/false = explicitně) a dokumentace slibuje „NULL = zděděno
z DS-default" — žádný default na úrovni DS/schránky ale v kódu neexistuje,
NULL dnes fakticky znamená „povoleno". Tento task dědění zhmotňuje na úrovni
schránky. Typický případ: schránky plněné importem/zrcadlením, jejichž obsah
nemá pro AI analýzu smysl.

## Rozhodnutí (potvrzena)

1. **D2 — Sloupec `core_mail_mailboxes.ai_analysis_disabled`** boolean
   NOT NULL default 0 + checkbox ve formuláři schránky. Sémantika příznaku
   výjimky: nezaškrtnuto = normální provoz (default), zaškrtnuto = analýza
   pro schránku zakázána. Default 0 je zároveň korektní pro existující řádky
   při `ds-upgrade` — žádný datový krok.
   Pozn. k názvosloví: message-level sloupec zůstává `ai_analysis_enabled`
   (tri-state, „enabled" sedí); na schránce jde o příznak výjimky, proto
   `ai_analysis_disabled` — vědomá drobná nekonzistence názvů.
2. **D3 — Vynucení při vzniku zprávy:**
   `IncomingMessageDocument::resolveInitialAnalysisState()` konzultuje flag
   schránky; vypnutá schránka → `analysis_state = 0`. Explicitní message-level
   `ai_analysis_enabled` (non-NULL v `$data`) má nadále přednost.
3. **D4 — Vynucení ve frontě:** `/queue` (výdej i COUNT) nevydá zprávu
   z vypnuté schránky, ledaže má zpráva `ai_analysis_enabled = 1`.
   Precedence: zpráva (explicitní) > schránka > default povoleno.
   Vypnutí schránky tak působí okamžitě i na už nafrontované zprávy.
4. **D5 — Ruční reanalýza:** „Znova analyzovat" na zprávě z vypnuté schránky
   nastaví message-level `ai_analysis_enabled = 1` (explicitní záměr uživatele
   přebíjí default schránky) — jinak by fronta zprávu tiše nikdy nevydala.
5. **O1 (uzavřeno):** guard reanalyze zůstává na `analysis_state ∈ {30, 70}`;
   zprávy importované s `analysis_state = 0` ručně analyzovat nejdou.
   Případné rozšíření = samostatný budoucí task.
6. **O2 (uzavřeno):** import zakládá schránky s defaultem (analýza povolena);
   vypnutí je ruční per schránka.

## Scope

### 1. Schéma + formulář

**`modules/core/mail/tables/core_mail_mailboxes.jsonc`** — nový sloupec
do skupiny `config`, za `default_primary_type`:

```jsonc
{
    "id": "ai_analysis_disabled",
    "name": "Disable AI analysis",
    "name:cs": "Zakázat AI analýzu příchozích zpráv",
    "name:en": "Disable AI analysis of incoming messages",
    "type": "boolean",
    "default": 0,
    "group": "config"
}
```

**`modules/core/mail/forms/core_mail_mailboxes.jsonc`** — checkbox do sekce
Konfigurace, za `default_primary_type`:

```jsonc
{"type": "input", "column": "ai_analysis_disabled"}
```

**`modules/core/mail/src/MailboxDocument.php`** — `beforeSave()`: normalizace
na 0/1 (stejný vzor jako `is_default`).

### 2. Intake — `IncomingMessageDocument`

`resolveInitialAnalysisState()`: po stávajících guardech (docState,
message-level `ai_analysis_enabled`, `$this->db === null`) a **před** dotazem
na aktivní profil doplnit dotaz na schránku:

```php
$mailboxId = isset($data['mailbox']) ? (int) $data['mailbox'] : 0;
if ($mailboxId > 0) {
    $mb = $this->db->fetch(
        'SELECT %n FROM %n WHERE %n = %i',
        'ai_analysis_disabled', 'core_mail_mailboxes', 'id', $mailboxId,
    );
    if ($mb !== null && !empty(((array) $mb)['ai_analysis_disabled'])) {
        return self::ANALYSIS_NONE;
    }
}
```

Pořadí je podstatné: explicitní message-level hodnota v `$data` (existující
guard výše v metodě) musí mít přednost před flagem schránky.

### 3. Fronta — `AnalysisController`

`/queue` výdej i COUNT (`totalAvailable`): JOIN na schránku a nová podmínka.
Stávající podmínka `(m.ai_analysis_enabled IS NULL OR m.ai_analysis_enabled = 1)`
zůstává; přibývá:

```sql
JOIN core_mail_mailboxes mb ON mb.id = m.mailbox
...
AND (mb.ai_analysis_disabled = 0 OR m.ai_analysis_enabled = 1)
```

Kombinace obou podmínek realizuje precedenci přesně:
`m.ai_analysis_enabled = 0` → nikdy; `= 1` → vždy (schránka nerozhoduje);
`IS NULL` → rozhoduje schránka. INNER JOIN je korektní — `mailbox` je
na zprávě povinný (resolveMailbox selže dřív, než zpráva vznikne).

### 4. Reanalyze — `AnalysisController::reanalyze()`

Po průchodu stávajícími guardy (stav 30/70, mimo Archiv/Koš, bez živého
applied targetu, validní profil): pokud je schránka zprávy vypnutá
a message-level `ai_analysis_enabled` není 1, přidat do requeue UPDATE
`ai_analysis_enabled = 1`. Dotaz na počáteční SELECT zprávy rozšířit
o `mailbox` + `ai_analysis_enabled` (JOIN nebo druhý fetch flagu schránky).
Žádná změna response ani chybových stavů.

### 5. Dokumentace

- `modules/core/mail/docs/ai-analysis.md` — odstavec o defaultu
  `analysis_state` (řádek „NULL = zděděno z DS-default" nahradit reálnou
  sémantikou: zpráva > schránka > povoleno) + podmínky `/queue` + chování
  reanalyze.
- `modules/core/mail/tables/core_mail_mailboxes.md` — nový sloupec.
- `modules/core/mail/tables/core_mail_incoming_messages.md` — řádek
  `ai_analysis_enabled`: „NULL = zděděno ze schránky
  (`mailboxes.ai_analysis_disabled`)".

Frontend se nemění (formulář schránky je generický z jsonc definic);
`npm run check:i18n` beze změny, ale spustit pro jistotu, pokud se sáhne
do frontendu.

## Testy

PHPUnit (narrow `--filter`!):

- `tests/Unit/Module/Core/Mail/IncomingMessageDocumentTest.php`:
  - schránka disabled + message-level NULL → `analysis_state = 0`,
  - schránka disabled + message-level `true` v `$data` → `10`,
  - schránka enabled (default) → stávající chování (10 při aktivním profilu).
- `tests/Unit/Module/Core/Mail/MailboxDocumentTest.php`: normalizace
  `ai_analysis_disabled` na 0/1 v `beforeSave`.
- `tests/Unit/Api/Controller/AnalysisControllerTest.php`:
  - `/queue` nevydá zprávu z vypnuté schránky (a `totalAvailable` ji nepočítá),
  - vydá ji při message-level `ai_analysis_enabled = 1`,
  - reanalyze na zprávě z vypnuté schránky nastaví
    `ai_analysis_enabled = 1` a zpráva se objeví ve frontě.

## Nasazení

- `ds-upgrade` všech DS (jen schéma, žádný datový krok — default 0 sedí).
- Bez závislosti na `ai_analyzer` daemonu (fronta se jen zužuje) i na
  importním tasku 26 ve `old_shipard`.

## Commit strategie

1. schéma + form + MailboxDocument + testy,
2. intake gate (IncomingMessageDocument) + testy,
3. queue + reanalyze (AnalysisController) + testy,
4. dokumentace.

## Hotovo když

- [x] `ds-upgrade` přidá sloupec; existující schránky mají 0. (ověřeno na 4l3j)
- [ ] Checkbox ve formuláři schránky funguje (uložení, znovunačtení). (ruční smoke v prohlížeči)
- [x] Nová zpráva do vypnuté schránky vzniká s `analysis_state = 0`.
- [x] `/queue` nevydává zprávy z vypnuté schránky; message-level
      `ai_analysis_enabled = 1` je výjimka.
- [x] Reanalyze zprávy z vypnuté schránky nastaví override a zpráva
      se analyzuje.
- [x] PHPUnit zelené, dokumentace aktualizovaná.
