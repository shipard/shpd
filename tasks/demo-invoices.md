# Demo: generátor fiktivních přijatých faktur (PDF)

**Stav:** naplánováno

## Kontext / Cíl

Hero video (scénář S1, `shpd-web/docs/demo-scenare.md`) potřebuje došlou
poštu s PDF fakturami. Dle D8 (`shpd-web/docs/demo-vyroba.md`) vzniká vše
od začátku z fiktivních dat — žádné reálné právnické osoby na
fabrikovaných dokladech. Sada z #40 (`demo/datasets/`) tato PDF později
převezme jako přílohy `mail/NNNN-*.files/`.

Cíl: `demo/invoices/` — datové soubory + HTML šablony + `build.py`,
který spočítá DPH a součty a přes Gotenberg (Chromium, `docs/render.md`)
vyrenderuje PDF. Generátor, ne ručně vyrobená PDF: čísla musí sedět
na haléř, jinak walkthrough ukáže validatátorové warningy.

Rozhodnuto v chatu 2026-09-01:

- fiktivní dodavatelé i odběratel; identity níže jsou závazná data
  (IČO s validním kontrolním součtem, k 2026-09-01 ověřena jako
  neexistující v ARES; názvy 0 zásahů v ARES)
- PDF přes Gotenberg render službu (ne WeasyPrint) — běží a je dostupná
  z vývojových strojů na `http://10.199.6.210:3000`
- 3 vizuální styly šablon, 4 faktury
- QR platba (SPD string) odložena do v2

## Identity (závazná data)

**Vlastní firma (odběratel na všech PDF; později `setup/` demo sady):**

Truhlářství Hoblinka s.r.o., Pilinová 214, 760 01 Zlín,
IČO 52654150, DIČ CZ52654150.

POZOR: doména `hoblinka.cz` je registrovaná cizí subjektem — e-mail
ani web odběratele se na PDF neuvádí nikde.

**Dodavatelé:**

| # | Název | Adresa | IČO | DIČ | Účet | IBAN | E-mail na PDF | Šablona |
|---|---|---|---|---|---|---|---|---|
| 1 | Softaro s.r.o. | Vývojová 1128/6, 616 00 Brno | 54965691 | CZ54965691 | 2308417557/2010 | CZ8520100000002308417557 | fakturace@softaro.cz | a |
| 2 | Dubretta s.r.o. | Katrová 87, 753 01 Hranice | 31970184 | CZ31970184 | 1156072849/0100 | CZ5901000000001156072849 | odbyt@dubretta.cz | b |
| 3 | Papyria s.r.o. | Knihařská 452/3, 110 00 Praha 1 | 66901707 | CZ66901707 | 1982653311/5500 | CZ1855000000001982653311 | objednavky@papyria.cz | a |
| 4 | Energima a.s. | Rozvodná 2201/14, 702 00 Ostrava | 54463416 | CZ54463416 | 2744190969/0300 | CZ7203000000002744190969 | zakaznici@energima.cz | c |

- Rezervní volná IČO pro rozšiřování sady: 98818678, 42167108.
- Čísla účtů mají validní mod-11 kontrolní součet, IBANy sedí
  (`build.py` obojmu znovu ověřuje assertem).
- Domény dodavatelů byly k 2026-09-01 bez A záznamu.

## Faktury (obsah)

1. **`0001-softaro-it-pausal`** — *hero* (poběží ve videu detailně).
   Číslo FA `2026-0912`, VS `20260912`, vystaveno 2026-09-01,
   DUZP 2026-08-31, splatnost 2026-09-15. Jeden řádek: „Správa IT
   infrastruktury — paušál 08/2026“, 1 měs × 8 500,00 Kč, 21 %.
   Bez zaokrouhlení. Čistý a čitelný layout.
2. **`0002-dubretta-material`** — zboží 21 %, 4 řádky (spárovka smrk,
   deska dub, hrana ABS v bm, doprava) s množstvím, jednotkami (m2, ks,
   bm) a desetinnými cenami — prověří extrakci řádků. Číslo FA
   alfanumerické `FV-2026-0457`, VS `20260457` — záměrně se liší
   docNumber a VS (testovací případ pro extrakci). Konkrétní částky
   zvolí implementace.
3. **`0003-papyria-kancelar`** — dvě sazby: odborná periodika 12 %
   (1–2 řádky) + kancelářské potřeby 21 % (2 řádky) — prověří
   rekapitulaci po sazbách. Pozor: knížkám se vyhýbáme (od 2024 mají
   0 % DPH), proto časopisy.
4. **`0004-energima-elektrina`** — elektřina (silová elektřina +
   distribuce, 21 %), celková částka zaokrouhlená na celé Kč
   (`totalRounding` != 0) — prověří derivaci `total_rounding_mode`.

Datumy jsou fixní (srpen/září 2026) — `dateMode: relative` zatím
neexistuje; před natáčením případně posunout a přegenerovat.

## Před implementací přečti

- `docs/render.md` — Gotenberg: routa `forms/chromium/convert/html`,
  hlavní HTML v multipartu se MUSÍ jmenovat `index.html`, assety ploché
  relativní, JS globálně vypnutý (šablony = čisté HTML+CSS),
  `printBackground`, papír rozměrově.
- `docs/exchange-format.md` §5–§7 — co extrakce vrací; PDF musí
  obsahovat vše: obě party (název, adresa, IČO, DIČ), účet + IBAN
  dodavatele, číslo FA, VS, formu úhrady, datumy (vystavení, DUZP,
  splatnost), řádky, rekapitulaci DPH po sazbách, součty
  vč. zaokrouhlení, měnu CZK.
- `modules/core/mail/docs/ai-analysis.md` — sekce zaokrouhlení
  (`total_rounding_mode`) a derivace `vat_mode`.
- `demo/datasets/README.md` — kam PDF později míří.
- `scripts/check-sensitive.py` — musí projít i nad `demo/invoices/`.

## Rozsah

### `demo/invoices/suppliers.jsonc`

Identity z tabulky výše + vlastní firma; komentáře: datum ověření
proti ARES, rezervní IČO, poznámka o `hoblinka.cz`.

### `demo/invoices/data/000N-<slug>.jsonc` (4 soubory)

Struktura blízko canonical (`shpd.docs.document.v1`), aby soubor mohl
později sloužit jako expected result pro AI eval (#40):
`supplier` (klíč do `suppliers.jsonc`), `docNumber`, `variableSymbol`,
`dates` (issue/tax/due), `currency`, `rows[]`
(`text`, `qty`, `unit`, `unitPrice`, `vatPct`), `rounding`
(`"czk"` | `"none"`), `template` (`"a"` | `"b"` | `"c"`),
`notes.onDocument`.

### `demo/invoices/templates/a.html`, `b.html`, `c.html`

- Placeholder syntaxe `{{key}}` (strtr styl, jako `MailTemplate`);
  blok řádků a rekapitulace skládá `build.py` v Pythonu a vkládá
  přes `{{rows}}` / `{{vatRecap}}` — žádné cykly v šabloně.
- Styly: **a** = moderní (sans, barevný akcentní pruh),
  **b** = strohý tabulkový, **c** = „starší ekonomický systém“
  (hustá tabulka, serif/mono). Faktury nesmí vypadat, že je vyrobil
  jeden nástroj.
- Česky, A4, jedna strana. Inline SVG povolené, ikonové fonty ne;
  Noto stack Gotenbergu pro češtinu stačí.

### `demo/invoices/build.py`

Pouze stdlib (multipart POST složit ručně přes `urllib`); kroky:

1. načti JSONC (strip `//` komentářů + trailing čárek),
2. spočítej per-řádek totály, rekapitulaci po sazbách,
   `totalBase`/`totalVat`/`totalAmount` (+ `totalRounding` při
   `rounding: czk`) — Decimal, ROUND_HALF_UP,
3. asserty: IČO checksum, mod-11 čísel účtů, IBAN check digits,
   Σ řádků == Σ rekapitulace == totals,
4. vyrenderuj HTML ze šablony, POST na Gotenberg
   (`GOTENBERG_URL`, default `http://10.199.6.210:3000`),
5. ulož `out/000N-<slug>.pdf`.

Formátování částek: úzká mezera pro tisíce, desetinná čárka.

### `demo/invoices/out/*.pdf`

Commitují se (artefakt pro video; binárky v gitu = vědomě, viz D13).

### `demo/invoices/README.md`

Jak přegenerovat, odkud identity, vazba na `demo/datasets/`.

## Ověření

1. `python3 demo/invoices/build.py` — exit 0, vzniknou 4 PDF,
   interní asserty projdou.
2. `pdftotext out/0001-softaro-it-pausal.pdf -` — obsahuje IČO obou
   stran, VS, částky 8 500,00 / 1 785,00 / 10 285,00.
3. Vizuální kontrola všech 4 PDF — A4, 1 strana, diakritika,
   styly se liší.
4. `python3 scripts/check-sensitive.py` — projde.
5. E2E (mimo rozsah tasku, až na demo DS): poslat 0001 do schránky,
   analyzer, walkthrough bez warningů `rows_recap_mismatch`,
   `vat_recap_inconsistent`, `vat_mode_suspect`.

## Pasti

- Čísla musí sedět na haléř — rekapitulaci a součty VŽDY počítat
  z řádků, nikdy neopisovat ručně.
- Gotenberg: hlavní soubor multipartu se musí jmenovat `index.html`,
  jinak 400; JS je vypnutý globálně — žádný JS v šablonách.
- Žádná reálná jména, částky ani identifikátory; e-mail/web
  odběratele neuvádět (`hoblinka.cz` patří cizímu subjektu).
- „Volnost“ IČO a domén je snapshot k 2026-09-01 — při budoucí
  kolizi identitu vyměnit a PDF přegenerovat (proto je to generátor).
- VS = jen číslice (max 10); u Dubretty se docNumber a VS záměrně
  liší.
- Knihy mají od 2024 sazbu 0 % — dvousazbový případ stavíme na
  periodikách (12 %), ne na knihách.
- Fixní datumy stárnou — před natáčením zkontrolovat, že splatnosti
  dávají na dashboardu smysl.
