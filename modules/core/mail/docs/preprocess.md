# Technické předzpracování došlé pošty (preprocess)

Asynchronní stage mezi intake došlé zprávy a AI frontou (issue #33,
zadání `tasks/mail-preprocess.md`). Řídí ji pravidla
`core_mail_preprocess_rules`; výsledkem jsou **nové obsahové přílohy
zprávy** — vidí je uživatel, AI analyzer i Spisovna stejně jako přílohy
z e-mailu. Motivace: část pošty nenese doklad jako PDF přílohu (faktura za
odkazem v těle — Bolt; faktura přímo HTML tělem — Apple, Google Play).

Odlišení od sousedů:

- **Sender rules** (`core_mail_sender_rules`) jsou terminální triage —
  auto-archiv šumu. Preprocess je obohacení: matchnout může víc pravidel
  a všechna se vykonají. Zpráva archivovaná sender rule žádné
  předzpracování nedostává.
- **Renditions** (#34, render HTML *příloh* do PDF) sem nepatří —
  preprocess vyrábí jen nové přílohy dle pravidel, nic plošně (D4).

## Osa `preprocess_state`

Třetí ortogonální osa zprávy vedle `docState` (workflow) a
`analysis_state` (AI pipeline). Hodnoty `core.mail.preprocessStates`:

| Stav | Význam | Kdo nastavuje |
|---|---|---|
| 0 Netýká se | Žádné pravidlo při intake nematchlo — koncový stav, chování jako dřív | intake |
| 10 Čeká | Plán uložen v `preprocess_log`, runner ještě neběžel | intake, sweep (reset) |
| 20 Běží | Runner si zprávu claimnul a vykonává plán | runner |
| 30 Hotovo | Všechny akce proběhly | runner |
| 40 Hotovo s chybami | Některá akce selhala, nebo sweep vzdal po 3 pokusech | runner, sweep |

**Gate AI fronty:** `GET /_mail/analysis/queue` (i `total_available`)
a `/claim` vyřazují `preprocess_state IN (10, 20)`. Zpráva má normálně
`analysis_state = 10` už od intake, do fronty se ale dostane až po
doběhnutí runneru. **Selhání nikdy neblokuje tok** — zpráva doteče
se stavem 40 a analyzer ji dostane s tím, co k ní je (D9).

`preprocess_log` (JSON):

```json
{
  "plan": [{"ruleId": "bolt-invoice-link", "ruleNdx": 3, "actions": [{"action": "fetchLinkedDocument", "...": "..."}]}],
  "results": [{"ruleId": "bolt-invoice-link", "action": "fetchLinkedDocument", "ok": true, "note": "fetched https://… → attachment 812", "attachmentId": 812}],
  "attempts": 1,
  "isdoc": "none",
  "createdAt": "…", "startedAt": "…", "finishedAt": "…"
}
```

## Pravidla

Tabulka [core_mail_preprocess_rules](../tables/core_mail_preprocess_rules.md);
životní cyklus `core.system.docStatesArchive`, **matchují jen pravidla
ve stavu 40**. UI: Nastavení → Pošta → Pravidla předzpracování (viewer
`PreprocessRulesViewer`, JSONC form; akce se editují jako JSON, D13).

**Podmínky shody** (D11) — AND přes vyplněné, aspoň jedna povinná:
`sender_email` (přesná adresa), `sender_domain` (i subdomény),
`subject_regex`, `body_regex` (nad `body_html` i `body_plain`). Regexy jsou
PCRE bez oddělovačů, case-insensitive. Reálné vzorky jsou `Fwd:` forwardy
z interních adres → systémová pravidla kotvíme přes `body_regex`, ne přes
odesílatele. Nevalidní regex pravidlo tiše vyřadí (warning do logu), intake
nikdy nespadne.

**Akce** (`actions`, uspořádaný JSON seznam, klíče `core.mail.preprocessActions`):

| Akce | Fáze | Parametry |
|---|---|---|
| `fetchLinkedDocument` | 1 | `linkHrefRegex` (povinný), `allowedDomains` (povinný seznam), `renderIfHtml` (rezervováno) |
| `renderBodyToPdf` | 2 (#34) | — |
| `convertOfficeToPdf` | rezervováno | — |

`PreprocessRuleDocument` odmítne akce, které runner neumí
(`PreprocessRuleDocument::IMPLEMENTED_ACTIONS`), a hlídá povinné parametry.

### Systémový katalog

`config/systemPreprocessRules.jsonc` → `PreprocessRulesProvisioner` při
`ds-upgrade` (bezpodmínečně, i pod `skipProvisioning` — import-mode DS
přijímá poštu stejně). Per `rule_id`: chybí → INSERT (`origin = system`,
stav 40; pravidla s `phase: 2` vznikají rovnou archivovaná — 70);
existuje ve 40 → UPDATE obsahových polí; archivované/smazané/koncept →
beze změny (**nekřísí se**). `hit_count`, `last_hit_at`, `created` se
nikdy nepřepisují.

Důsledek: úpravu systémového pravidla ve stavu 40 další `ds-upgrade`
přepíše. Přizpůsobení = systémové pravidlo archivovat a založit
uživatelskou kopii.

Katalog v1: `bolt-invoice-link` (živé), `apple-invoice-body`,
`google-play-order` (Fáze 2, archivované do nasazení `renderBodyToPdf`).

## Tok

```
POST /_mail/incoming
  ├─ SenderRuleMatcher → archiv? → konec (žádné předzpracování)
  ├─ PreprocessRuleMatcher (pravidla 40, AND, regexy) → plán | null
  ├─ tx: INSERT zprávy (+ preprocess_state=10, preprocess_log.plan), hit_count++
  ├─ commit
  ├─ plán null → ISDOC import při intake (dnešní chování)
  └─ plán → PreprocessSpawner: setsid -f php bin/shpd-ds mail-preprocess --message <id>

shpd-ds mail-preprocess --message <id>          (PreprocessRunner)
  ├─ claim: UPDATE … SET preprocess_state=20 WHERE id=? AND preprocess_state=10
  │        (0 řádků = prohraný závod / už hotovo → tichý konec)
  ├─ vykoná uložený plán (ne re-match, D12) — výsledek per akce do results
  ├─ IsdocImportService::tryImport nad všemi obsahovými přílohami
  │  (původní i vygenerované — intake větev byla přeskočena, D10)
  └─ preprocess_state = 30 | 40, finishedAt
```

Plán je **snapshot** z intake: změna pravidla po přijetí zprávy plán
nemění. Ruční přepočet = `--force`.

## Akce `fetchLinkedDocument`

`Preprocess/Action/FetchLinkedDocumentAction`:

1. Kandidátní URL z `href` atributů `body_html` (HTML entity dekódované)
   a z holých URL v textu; kandidát musí matchnout `linkHrefRegex` přímo
   **nebo po URL-decode** (tracking wrappery typu awstrack nesou cíl
   zakódovaný v cestě). Max 5 kandidátů, první úspěšný vyhrává.
2. Redirecty se procházejí **ručně** (max 5 hopů), každý hop: jen
   `http`/`https`, host se přeloží a **každá** jeho adresa musí být
   veřejná (privátní, loopback, link-local, rezervované = blok celého
   hostu); ověřená IP se pinuje do requestu (`CURLOPT_RESOLVE`) — žádný
   druhý DNS lookup, žádný DNS rebinding.
3. Tracking wrapper smí být průchozí 3xx z cizí domény; **obsah** se
   přijme jen z finální URL, jejíž host je v `allowedDomains` (i
   subdomény) a která matchne `linkHrefRegex`.
4. Anonymní GET, timeout 20 s, strop 20 MB (přenos se přeruší), content
   type `application/pdf` nebo magic `%PDF`. HTML = selhání s poznámkou
   (`renderIfHtml` je Fáze 2).
5. Uložení přes `AttachmentService::upload` jako obsahová příloha zprávy,
   provenance do `core_attachments_files.metadata`:
   `{generatedBy: "preprocess", ruleId, action, sourceUrl, finalUrl, fetchedAt}` (D5).

**Idempotence:** nesmazaná příloha se shodným `(ruleId, action, sourceUrl)`
→ akce se přeskočí jako úspěšná. Opakované `mail-preprocess --message`
tedy nevyrábí duplikáty; `--force` generované přílohy nejdřív smaže
(soft delete) a přegeneruje.

Selhání (expirovaný odkaz, 404, timeout, cizí doména, size cap) je
**provozní stav**: zapíše se do `results`, zpráva skončí ve 40, žádná
výjimka ven (D6).

## Provoz

- **Spawn** (`PreprocessSpawner` → `Core\Process\DetachedProcess`):
  `setsid -f` odpojí runner od php-fpm workera, argv pole = žádný shell.
  stdout/stderr potomka jdou do `preprocess.log` vedle serverového logu
  (když je adresář zapisovatelný), runner loguje přes `ErrorLogger` do
  `shipard.log`. Selhání spawnu se jen zaloguje — zprávu zvedne sweep.
  Runner běží pod uživatelem web workeru; potřebuje zápis do `att/` DS
  (stejně jako intake příloh).
- **Sweep** (`mail-preprocess --sweep`, cron slot `minute` vedle
  `mail-analysis-reap`): stav 10 starší než 5 min (spawn selhal) a stav 20
  starší než 15 min (proces umřel) → `attempts++`, zpět na 10, spawn; po
  3 pokusech stav 40 s poznámkou. Sweep je jen záchrana, ne primární
  spouštěč (D8).
- **`--force`**: re-match dle aktuálních pravidel, smazání generovaných
  příloh, přegenerování. Funguje i na stavech 0/30/40 (ladění nového
  pravidla nad starou zprávou). Odmítne zprávu s aktivním AI claimem
  (`analysis_state = 20`) a zprávu ve stavu 20 (použij `--sweep`).
- **Detail zprávy** (viewer Došlá pošta): badge stavu předzpracování
  v hlavičce, blok „Předzpracování" v tabu Obsah (pravidla, pokusy,
  výsledek per akce, ISDOC, čas), generované přílohy nesou badge
  „Vygenerováno".

CLI reference: [docs/cli.md](../../../../docs/cli.md) § `mail-preprocess`.
API gate: [docs/mail/api-contract.md](../../../../docs/mail/api-contract.md) § 9.1.

## Fáze 2 (po #34)

Akce `renderBodyToPdf` (HTML tělo → PDF příloha přes rendering službu),
parametr `renderIfHtml` u fetch (stažené HTML → render místo selhání),
aktivace systémových pravidel Apple/Google (odarchivovat, nebo smazat
a nechat provisioner založit znovu — smazané se nekřísí, proto raději
přechod 70 → 40 v UI).
