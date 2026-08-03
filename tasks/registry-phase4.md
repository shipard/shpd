# Spisovna — Fáze 4: aparát (expirace, fulltext, MCP)

**Stav:** hotovo

## Kontext

Poslední fáze MVP Spisovny dle `docs/registry-mvp.md` §9: **deterministický
aparát** nad zařazenými dokumenty. Tři pilíře: (1) hlídání expirací přes
`core.alerts` (žádné LLM — pravidla pro stav), (2) plnohodnotný fulltext
(`extracted_text` ve vyhledávání vieweru + plnění při uploadu a zpětně),
(3) MCP nástroj `registry_search`, kterým se Spisovna otevře internímu chatu.

Autoritativní design je `docs/registry-mvp.md` §9; tento PRD ho rozvádí.
Bonus zdarma: alerty tečou do dashboard feedu přes existující `AlertsSource`
— karta expirace nevyžaduje žádnou dashboard práci.

## Návaznost

- **Staví na:** Fázi 1 (tabulky, `ft_text` index, viewer), Fázi 2
  (`TextExtractor` v core.attachments, plnění textu při apply a ručním
  zařazení), subsystému `core.alerts` a MCP serveru.
- **Checklisty šanonů** („šanon má obsahovat…") jsou dle designu vědomě
  **mimo** — až po ověření expirací v praxi.
- Po dokončení této fáze je MVP Spisovny kompletní; zbývá samostatný task
  migrace `wkf.docs`.

## Před implementací přečti

- `docs/registry-mvp.md` §9 + §4.1 (`docKinds.expiration` — per druh
  `warnDaysBefore`, sémantika `valid_to` = „datum, po kterém dokument
  přestává být v pořádku bez zásahu").
- `docs/alerts.md` **celý** — check vs. alert, invariant stabilního
  `finding_key` (reconciler přes něj UPDATEuje místo INSERTu), lifecycle
  (nevrácený finding = uzavření alertu), severities, intervaly, CLI
  `alerts-run`.
- `src/Core/Alerts/AlertCheck.php` — konstruktor `(db, config, language)`,
  žádný DI; `src/Core/Alerts/AlertFinding.php` — pole vč.
  `subjectTableId`/`subjectRowId` a `actions [{id, label, kind, target,
  primary?}]`; **lokalizaci si check dělá sám v jazyce DS**.
- `src/Core/Mail/MailOutboxAlertCheck.php` + blok `alertChecks`
  v `modules/core/mail/module.jsonc` (id, name:cs/en, description, class,
  severity, interval, tags) — vzor jedna ku jedné.
- `docs/mcp-server.md` (tiery, auth) +
  `modules/docs/core/src/Mcp/DocumentsSearchTool.php` — vzor čtecího
  nástroje: `McpTool` interface, whitelistovaný WHERE builder, výstup
  `summary`/`items` s `ref`/`pagination`, `description()` vymezující
  nástroj vůči ostatním.
- `src/Api/Mcp/McpToolRegistry.php` — **tady se nástroje registrují**
  (v modulech žádné `new …Tool` není); přidej `RegistrySearchTool` stejným
  způsobem jako stávající.
- `modules/base/registry/src/RegistryDocumentsViewer.php` — fulltext
  (dnes `ft_head`), rozšíření o `ft_text`.
- `modules/core/attachments/src/TextExtractor.php` +
  `modules/base/registry/src/FileFromMessageService.php` — jak se text
  plní dnes (apply, ruční zařazení). Attachments modul **nemá**
  post-upload hook a kvůli jednomu konzumentovi ho nezavádíme — trigger
  je na straně registry (endpoint + frontend).

## Scope

**V rozsahu:**

- `RegistryExpirationAlertCheck` (base.registry) + registrace `alertChecks`
- viewer fulltext přes `extracted_text` (`ft_text`)
- endpoint regenerace textu dokumentu + frontend trigger po změně příloh
  + CLI backfill (`registry-extract-texts`)
- MCP nástroj `registry_search` (čtecí tier) + registrace
- dokumentační konsolidace: plnohodnotné
  `modules/base/registry/README.md`, aktualizace `docs/mcp-server.md`
  a hlavičky `docs/registry-mvp.md` (design record)

**Mimo rozsah:**

- checklisty šanonů / chybějící dokumenty (budoucí fáze, až se expirace
  ověří v praxi)
- jakékoli AI, dashboard změny (AlertsSource pokrývá), migrace
- vazby dokumentů, per-šanon oprávnění (trvale mimo MVP)
- `tasks/README.md` neaktualizuj

## Doporučené pořadí

### Krok 0 — prerekvizity

Změna `module.jsonc` (alertChecks) → rebuild kompilované konfigurace +
`ds-upgrade`. Ověř na test DS, že `alerts-run` check vidí (registr se
staví z compiled cfg).

### Krok 1 — `RegistryExpirationAlertCheck`

- `modules/base/registry/src/RegistryExpirationAlertCheck.php` extends
  `AlertCheck`:
  - **výběr:** dokumenty `docState IN (40, 80)` (Zařazeno + V opravě;
    Koncepty 10 nehlídáme — nejsou zařazené; 70/90 vyloučeny — Ukončení
    platnosti je legitimní způsob, jak alert umlčet), `valid_to IS NOT
    NULL`, `doc_kind` s neprázdnou `docKinds[kind]['expiration']`;
    horizont dotazu `valid_to <= today + max(warnDaysBefore přes druhy)`;
  - **severity:** `valid_to < today` → `error`; `<= today +
    min(warnDaysBefore druhu)` → `warning`; jinak (v horizontu
    `max(warnDaysBefore druhu)`) → `info`;
  - **finding_key = `doc_{id}`** — stabilní napříč běhy i změnou severity
    (reconciler alert aktualizuje, nezakládá nový); prodloužení
    `valid_to` nebo přechod do 70/90 → finding se nevrátí → reconciler
    alert uzavře;
  - **title:** `{docKinds label}: {title}`; **message:** datum + počet dní
    (po termínu: „platnost skončila před N dny"), partner pokud je —
    lokalizovat cs/en dle `$this->language` (vzor MailOutboxAlertCheck);
  - `subjectTableId = 428`, `subjectRowId = id`; akce `open_form` na
    dokument (tvar `[{id, label, kind, target, primary}]` — přesný kind
    okoukej z existujícího checku/alerts docs, ať UI akci umí);
  - `context`: `{valid_to, days, doc_kind}` — žádná citlivá data.
- Registrace v `modules/base/registry/module.jsonc` `alertChecks`:
  id `base.registry.expirations`, severity `warning` (default checku),
  interval `6h`, tags `["registry"]`, name/description cs+en.
- Unit testy: hranice severit (po termínu / 7 d / 30 d / mimo horizont),
  druh bez `expiration` se ignoruje, 10/70/90 vyloučeny, stabilita
  finding_key, lokalizace cs/en. Integračně: `runCheck` → alert vznikne;
  prodloužení platnosti → další běh alert uzavře.

### Krok 2 — fulltext naplno

- **Viewer:** fulltext dotaz rozšířit o `MATCH (extracted_text) AGAINST`
  (`ft_text`) OR přes stávající `ft_head` — dokument se najde podle
  obsahu PDF, ne jen podle titulku.
- **Endpoint** `POST /api/v1/_registry/documents/{id}/extract-text`
  (RegistryController): načte obsahové přílohy dokumentu (table_id 428,
  dle `att_order`), přes `TextExtractor` složí text (oddělovač prázdný
  řádek, cap 500 000 znaků — konzistentně s Fází 2), uloží
  `extracted_text` (Document flow), vrátí `{chars, attachments}`;
  idempotentní; 404 pro neexistující/koš.
- **Frontend:** v `RegistryDocumentsForm` po změně příloh (upload/smazání
  v `AttachmentPanel`) zavolat endpoint — `AttachmentPanel` má/dostane
  volitelný callback změny (ověř, zda existuje; případně malé generické
  rozšíření — žádná registry logika uvnitř panelu).
- **CLI backfill:** `shpd-ds registry-extract-texts [--missing-only]`
  (Symfony Console, vzor stávajících ds příkazů) — projde živé dokumenty,
  doplní text; `--missing-only` default true, `--all` přegeneruje.
  Použití: jednorázově na alfě po nasazení (dokumenty z Fáze 1 text
  nemají).
- Testy: endpoint (concat + cap + idempotence + 404), CLI (missing-only
  vs all), viewer search regrese + nález dle obsahu.

### Krok 3 — MCP `registry_search`

- `modules/base/registry/src/Mcp/RegistrySearchTool.php` implements
  `McpTool`, `isReadOnly(): true`, vzor `DocumentsSearchTool`
  (whitelistovaný WHERE builder, limit/offset s capem 50, `has_more`):
  - **parametry:** `query` (fulltext `ft_head` + `ft_text`; MATCH
    AGAINST), `doc_kind` (enum klíčů z `docKinds`), `binder_name`
    (string, case-insensitive match na živé šanony — LLM zná názvy, ne
    id; nenalezený šanon → prázdný výsledek se srozumitelným summary),
    `partner` (int, „ID osoby z persons_search"), `valid_to_before` /
    `valid_to_after` (YYYY-MM-DD), `expiring_within_days` (int —
    zkratka `valid_to <= today+N`, jen živé), `state`
    (`filed` = 40 default | `active` = vše mimo 90 | `all`), limit,
    offset.
  - **výstup:** `summary` (počet + kolik expiruje), `items[]`: `ref
    {type: 'registry_document', id}`, `full_name` („{title} — {partner}"),
    `doc_kind` + label z cfg, `binder` (name), `partner {id, full_name}`,
    `ref_number`, `valid_from`/`valid_to`, `expired` bool, `ai_summary`
    zkrácené na ~200 znaků, `state_label`; `pagination` vzorově.
  - **description():** česky; vymezit vůči `documents_search` (účetní
    doklady) a `persons_search` — „trvalé dokumenty firmy (smlouvy,
    pojistky, revize…) ve Spisovně, hledá i v textu příloh".
- Registrace v `src/Api/Mcp/McpToolRegistry.php` po vzoru stávajících
  nástrojů (čtecí tier).
- Testy: filtry jednotlivě i kombinace, fulltext nález dle obsahu
  přílohy, binder_name nenalezen, expiring_within_days, cap limitu,
  tvar `ref`/pagination. Ověř, že nástroj je vidět v tools/list přes
  existující MCP testy/vzor.

### Krok 4 — dokumentace (uzavření MVP)

- `modules/base/registry/README.md` — plnohodnotná modulová dokumentace:
  koncept (dispozice, dvě osy), docKinds + expirace, zdroje, AI cesta
  (odkaz na mail docs), endpointy, MCP nástroj, alert check, CLI.
- `docs/mcp-server.md` — přidat `registry_search` do přehledu nástrojů.
- `docs/registry-mvp.md` — hlavička: MVP dokončeno (fáze 1–4), dokument
  je design record; provozní pravda žije v README modulu. Obsah nemazat.
- `docs/alerts.md` — pokud udržuje výčet checků, doplnit.

## Testy

Souhrn dle kroků; PHPUnit úzkými `--filter` (RegistryExpiration,
RegistryExtractText, RegistrySearchTool…). Integrační běhy vyžadují
`SHIPARD_INTEGRATION_DS_PATH` (známé omezení test infry).

## Commit strategie

(1) alert check + registrace + testy, (2) fulltext (viewer + endpoint +
frontend + CLI), (3) MCP nástroj + registrace, (4) dokumentace. Každý
commit zelené testy a funkční `ds-upgrade`.

## Hotovo když

- [ ] dokument s `valid_to` v horizontu druhu vyrobí alert se správnou
      severitou; prodloužení platnosti nebo Ukončení platnosti (70) alert
      uzavře; karta se objeví ve feedu přes AlertsSource s akcí na
      formulář
- [ ] finding_key stabilní — změna severity alert aktualizuje, nezakládá
      duplicitní
- [ ] viewer najde dokument podle textu uvnitř PDF přílohy
- [ ] upload/smazání přílohy ve formuláři přegeneruje `extracted_text`;
      `shpd-ds registry-extract-texts` doplní chybějící texty zpětně
- [ ] `registry_search` v MCP tools/list (čtecí tier); hledá fulltextem
      vč. obsahu příloh, filtruje druh/šanon/partnera/platnost;
      `expiring_within_days` vrací blížící se expirace
- [ ] dokumentace zkonsolidovaná (README modulu, mcp-server.md, hlavička
      registry-mvp.md)
- [ ] testy zelené (filtry ze sekce Testy)

## Otevřené body

1. **Interval checku** — start na `6h`; pokud bude na alfě šum ze změn
   během dne, stačí denně.
2. **`expiring_within_days` default** — bez parametru se nefiltruje;
   chat si řekne explicitně (nástroj nemá hádat horizont).
3. **Checklisty šanonů** — návrh až po provozní zkušenosti s expiracemi
   (samostatné PRD, mimo MVP).
