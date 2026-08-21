# Dashboard — sekce podle pásem + asymetrická vizuální váha (Issue #32/2)

**Stav:** naplánováno

## Status

Navrženo a schváleno Annou (2026-08-21) — vizuální návrh a rozhodnutí
v diskusi nad Issue #32, celek 2.

## Cíl

Feed dashboardu vizuálně rozdělit do **sekcí podle pásem** (`kind`) s tím,
že vizuální váha karty odpovídá potřebné pozornosti: urgentní karty velké
a plné, review karty střední, **ready pásmo defaultně sbalené do jednoho
souhrnného pruhu** a info pásmo degradované na tlumené řádky. Uživatel na
první pohled vidí, co po něm feed chce — zelené karty, které vyžadují
nejméně čtení, přestanou zabírat většinu plochy.

Náčrt (ready sbaleno):

```
🔴 Vyžaduje pozornost (1)
▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔  ← plná karta, full-width
 ⚠ Chyba analýzy e-mailu …

🟡 Ke kontrole (4)
[plná karta] [plná karta]   ← dnešní grid 2 sloupce
[plná karta] [plná karta]

🟢 Připraveno (8)
┌────────────────────────────────────────────────┐
│ 8 faktur připravených k vystavení              │
│ Celkem 96 420,00 CZK · jistota 91–98 %         │
│                     [Projít] [Zobrazit ▾]      │
└────────────────────────────────────────────────┘

ℹ️ Ostatní (1)
  Není faktura — … · „…"          Koš · Archiv    ← kompaktní řádek
```

Rozbalené ready pásmo = **kompaktní jednořádkové položky** (donut, partner,
typ · datum, částka, [Použít], ikona oka → review modal), ne plné karty.

## Schválená rozhodnutí

1. **D1 — Sekce podle `kind`**: urgent / review / ready / info s hlavičkami
   (ikona + název + počet). Čistě prezentační vrstva ve `Feed.svelte` —
   serverové řazení `sortAndCap` se nemění, sekce = seskupení už seřazených
   karet. Prázdná sekce se nerenderuje (ani hlavička).
2. **D2 — Asymetrická váha**: urgent karty full-width (1 sloupec), review
   karty dnešní grid (auto-fill 2 sloupce), ready sbalený pruh / kompaktní
   řádky, info kompaktní řádky (title/subtitle + akce vpravo, bez plné karty).
3. **D3 — Ready pruh (sbalený default)**: počet, souhrn „Celkem {částky
   per měna} · jistota {min}–{max} %", akce **Projít** a **Zobrazit ▾**.
   Souhrn počítá server (D8). Bez akce „Použít vše" — celek 3 Issue #32
   zůstává samostatný, v této iteraci se nedělá.
4. **D4 — Rozbalené ready**: kompaktní řádky v jednom orámovaném bloku
   (ne grid karet): donut jistoty, partner tučně, typ · datum doručení,
   částka, tlačítko Použít (jednoklik apply, stávající `applyFlow`),
   ikona oka → review modal (`previewNdx`). Patička bloku: „Projít frontu".
5. **D5 — Chip filtr kategorií zůstává** beze změny; sekce se počítají nad
   filtrovanou množinou (`filteredCards`). Kategorie (invoices/registry/
   other) a pásma (`kind`) jsou ortogonální — např. warning alert je
   kategorie Ostatní, ale sekce Ke kontrole; to je záměr, ne chyba.
6. **D6 — Stav rozbalení ready lokální** (`$state` v komponentě), default
   sbaleno, nepersistuje (vzor: expander detailu karty).
7. **D7 — Žádný přepínač** starý/nový layout. Návrat = git revert.
8. **D8 — Souhrn ready počítá server**: nové volitelné pole odpovědi
   `GET /_ui/dashboard` → `data.readySummary = {count, amounts:
   [{currency, total}], confidenceMin, confidenceMax}` (jen když je aspoň
   1 ready karta). Agregace per měna — nikdy nesčítat napříč měnami.
   Doporučená cesta: karty návrhů interně ponesou numerická pole
   `amount` + `currency` (vedle `headline.amountText`), controller po
   `sortAndCap` spočítá souhrn a interní pole z karet před odesláním
   odstraní — kartový kontrakt se nemění. Frontend formátuje částky
   lokálně (`number_format` vzor viz `formatAmount()` zdroje), min/max
   jistoty z `confidencePct` může ověřit, ale zdroj pravdy je server.
9. **D9 — „Projít" v ready pruhu** = sériový průchod (§6.6) omezený na
   **ready pásmo**: snapshot = `queueableCards` navíc filtrované
   `kind === 'ready'`, řazení a chování jinak identické (chronologicky,
   counts, souhrnný toast, předkrok Nová kategorie se uplatní jen pokud
   se týká — content_tag karty jsou review, takže se předkrok u ready
   průchodu typicky neobjeví; logiku předkroku nevypínat, jen jí projde
   prázdný seznam). Tlačítko „Projít frontu (N)" u filtru zůstává beze
   změny (ready + review).
10. **D10 — Hlavičky sekcí lokalizované** (cs/en), i18n klíče
    `dashboard.feed.section.{urgent,review,ready,info}` + texty pruhu
    `dashboard.feed.readyStrip.*`.

## Před implementací přečti

- `docs/dashboard.md` — §3 (architektura), §4 (kartový kontrakt), §4.1
  (kind a řazení), §6.6 (sériový průchod), §7 (API kontrakt), §8
  (komponenty).
- `tasks/dashboard-queue-walkthrough.md` — rozhodnutí D1–D9 průchodu;
  snapshot fronty, batch větve `finishApply`/`submitRejectFlow`.
- `tasks/dashboard-feed-redesign.md` — dnešní podoba karet a gridu.
- `frontend/src/components/dashboard/Feed.svelte` — dnešní grid + empty
  stavy; sem přijde seskupení do sekcí.
- `frontend/src/components/dashboard/Dashboard.svelte` — `queueableCards`
  (ř. ~86), `startQueue`/snapshot (ř. ~438), `applyFlow`, `previewNdx`.
- `modules/core/mail/src/Feed/MailSuggestionsSource.php` —
  `formatAmount()` (ř. ~808) a stavba headline (ř. ~240) pro D8.
- `src/Api/Controller/DashboardController.php` — `sortAndCap`, stavba
  odpovědi; sem přijde `readySummary`.

## Scope

### Backend

- `MailSuggestionsSource`: návrhové karty (docs target) navíc ponesou
  numerická pole `amount` (float z `totals.totalAmount`) a `currency`
  (string) — jen když existují; registry a chybové karty ne.
- `DashboardController`: po `sortAndCap` spočítat `readySummary` z karet
  `kind==='ready'` s numerickými poli (karty bez částky se do `amounts`
  nezapočítají, do `count` ano); interní pole `amount`/`currency` z karet
  odstranit; `readySummary` vynechat, když ready karet není.
- `docs/dashboard.md`: §7 API kontrakt (`readySummary`), §8 komponenty,
  nová podsekce o sekcích v §1/§2.

### Frontend

- `Feed.svelte`: seskupení `cards` podle `kind` do sekcí s hlavičkami;
  urgent full-width, review dnešní grid, ready → nová komponenta, info →
  kompaktní řádky. Empty stavy beze změny (globální/per-záložka).
- Nová `FeedReadySection.svelte` (pruh + rozbalený seznam kompaktních
  řádků, lokální stav rozbalení, props: karty, readySummary, onApply,
  onReview, onWalkthrough, busyCardId).
- Nová `FeedRowCompact.svelte` (kompaktní řádek — použije ready i info
  sekce; u info bez donatu/částky, akce z `card.actions`).
- `Dashboard.svelte`: prop `readySummary` dolů, `startQueue` rozšířit
  o volitelný filtr pásma (D9).
- i18n `cs.js`/`en.js`: klíče z D10.

## Testy a ověření

1. PHPUnit: `readySummary` — agregace per měna, min/max jistoty, karty
   bez částky, žádné ready karty → pole chybí, interní pole odstraněna
   z payloadu.
2. `cd frontend && timeout 90 npm run build` zelený.
3. E2E ručně na alfě (DS s poštou): sekce se renderují jen neprázdné;
   ready pruh ukazuje správný počet/součty/rozsah; Zobrazit rozbalí
   kompaktní řádky; Použít z řádku = jednoklik apply vč. 422 fall-through
   do modalu; oko otevře review modal; „Projít" z pruhu projde jen ready
   zprávy se správným počítadlem; „Projít frontu" u filtru beze změny
   (ready + review); chip filtr — přepnutí záložky přepočítá sekce;
   dark mode čitelný.
4. Mobil (úzké okno): hlavičky sekcí, pruh i kompaktní řádky se vejdou,
   nic nepřetéká.

## Pasti

- **Měny nesčítat napříč** — `amounts` je pole per měna; frontend je
  vypisuje spojené „ + " (např. „96 420,00 CZK + 120,00 EUR").
- **`totals.totalAmount` může chybět** (karta bez částky) — do součtu ne,
  do počtu ano; `confidencePct` u ready karet existuje vždy (pásmo se
  bez jistoty nespočítá), ale kód ať je defenzivní.
- **Strop MAX_CARDS**: karta „…a další" je `kind=info` bez `category` —
  v sekci Ostatní se objeví jen na záložce Vše; `readySummary.count` je
  počet ready karet **po** stropu (souhrn = to, co uživatel vidí).
- **Ortogonalita kategorie × pásmo** (D5) — nezkoušet je slučovat;
  počty v chipech (`feedCounts`) se nemění.
- **Busy stav**: kompaktní řádek musí respektovat `busyCardId` (disable
  Použít i oka) — jednoklik apply běží i mimo plnou kartu.
- **Info řádky mají heterogenní akce** (Koš/Archiv, open_viewer,
  open_form, undo_auto_archive) — `FeedRowCompact` akce jen deleguje
  přes `onAction(card, action)`, žádná vlastní logika.
- **Předkrok Nová kategorie u ready průchodu** (D9): content_tag karty
  do ready snapshotu neprojdou (jsou review), ale `startQueue` logiku
  předkroku neobcházet — jen dostane prázdný seznam.
- **Svelte 5**: sekce = `$derived` seskupení, žádné mutace `data.cards`;
  optimistické `dropCardById` musí sekce přepočítat samo.
- **Diakritika v i18n** — editace `cs.js` bezpečným python vzorem
  (`assert s.count(old) == 1`), ne `patch_file`.
