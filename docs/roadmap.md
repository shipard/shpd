# Roadmap

Kam Nový Shipard směřuje a v jakém pořadí. Tento dokument odpovídá na otázku
**„co teď"** — implementační zadání žijí v [`tasks/`](../tasks/README.md),
referenční specifikace subsystémů v [`docs/`](README.md).

Stav jednotlivých tasků: generovaný souhrn v
[`tasks/README.md`](../tasks/README.md).

---

## Pravidlo prioritizace

Milníky jsou definované **schopností uživatele**, ne modulem. Task se dělá,
když posouvá nejbližší otevřený milník. Ve sporu platí toto pořadí:

1. **Věcná správnost** — systém nesmí tvrdit nepravdu o penězích. Chyba ve
   výpočtu má přednost před jakoukoli funkcí.
2. **Blokátor použitelnosti** — bez čeho firma nemůže systém provozovat.
3. **Blokátor migrace** — bez čeho nelze přejít ze starého Shipardu.
4. **Vše ostatní** — pohodlí, vzhled, rozšíření.

Cokoli z kategorie 4 se nezačíná, dokud je otevřená položka z kategorie 1.

---

## M0 — Věcná správnost výpočtů ▸ **aktivní**

Doklad vzniklý z došlé faktury musí mít správné částky. Dnes ne vždy má.
Nejmenší milník na roadmapě a zároveň blokátor všeho ostatního: dokud běží,
každý tester generuje data, která se budou muset opravovat.

| Co | Zadání |
|---|---|
| Faktury s jednotkovými cenami včetně DPH → daň se počítá dvakrát | [`tasks/TODO.md`](../tasks/TODO.md) |
| Dokončení zaokrouhlení celkové částky (ověření + nasazení promptu) | `mail-invoice-rounding.md` |
| Reverse charge v rekapitulaci DPH | `docs-vat-totals-reverse-charge.md` |
| Opravy schématu AI analýzy (`schema_error`, nedeklarované hodnoty) | `mail-analysis-schema-fixes.md` |

**Hotovo když:** u kontrolní sady faktur z testovacího prostředí odpovídá
součet rekapitulace DPH a celková částka výsledného dokladu předloze, a to
i u faktur s koncovými cenami a s reverse charge.

---

## M1 — Výstupy pro DPH

Bez přiznání k DPH a kontrolního hlášení nemůže být Shipard jedinou evidencí
firmy — účetní ho nepřevezme a systém zůstane doplňkem. Evidence existuje
(období DPH, analytiky per kód DPH, `world.vat` s CZ sazbami), **výstup
neexistuje žádný**.

Nejrozsáhlejší položka roadmapy. Zadání zatím není napsané — začíná se
designovou diskuzí, ne implementací.

| Co | Zadání |
|---|---|
| Přiznání k DPH — sestavení z deníku a analytik, XML pro portál | — |
| Kontrolní hlášení — sekce A/B, limity, XML | — |
| Uzavření období DPH (zámek proti dodatečným změnám) | — |

**Hotovo když:** za uzavřené období lze vygenerovat přiznání i kontrolní
hlášení ve formátu přijatelném pro daňový portál a čísla souhlasí s deníkem.

---

## M2 — Uzavřený kruh na přijaté faktuře

Pošta → AI analýza → doklad → účetní deník → saldokonto → párování s bankovní
platbou, bez ručního zásahu mimo kontrolu. Tady je hotovo víc, než se zdá:
matcher, clearing účty i dávkový endpoint párování existují.

| Co | Zadání |
|---|---|
| Ověření celého toku na reálných datech testovacího prostředí | — |
| Zbytky po M0 (co se ukáže při ověřování) | — |

**Hotovo když:** přijatá faktura z e-mailu projde až do spárované úhrady
a uživatel do toho zasáhne jen potvrzením návrhu.

---

## M3 — Migrace ze starého Shipardu na ostro

Výměnný formát a applier jsou hotové pro doklady, osoby, položky, účetní
doklady i bankovní výpisy. Chybí důkaz, že import proběhne beze ztráty na
všech ostrých datech.

| Co | Zadání |
|---|---|
| Kompletní import ověřený na všech datových zdrojích testovacího serveru | — |
| Kontrolní součty proti starému systému (doklady, saldo, deník) | — |

**Hotovo když:** import lze zopakovat z čistého stavu (`ds-reset`) a výsledné
součty souhlasí se starým systémem.

---

## M4 — Provoz firmou, která není vývojář

Dnes systém provozuje ten, kdo ho vyvíjí. Aby ho mohl provozovat někdo jiný,
chybí bezpečnostní tvrdost a obnova.

| Co | Zadání |
|---|---|
| Rate limiting a evidence neúspěšných přihlášení | `auth-phase0a-hardening.md` |
| Záloha a obnova datového zdroje | — |
| Příkazy `ds-delete` a `ds-list` | — |
| Servisní výmaz nepotřebných tabulek a sloupců | — |

---

## M5 — Pohodlí a vzhled

Vše, co systém zpříjemňuje, ale neblokuje jeho použití. Otevřená položka
z M0 má vždy přednost.

| Co | Zadání |
|---|---|
| Agregace alertů do skupinových karet feedu | `dashboard-alert-grouping.md` |
| Detekce chybějících překladů (validační nástroj) | — |

---

## Vědomě odložené

Věci, o kterých se rozhodlo, že se **nedělají teď** — ať se k nim nevracíme
v každé diskuzi.

| Co | Proč |
|---|---|
| PostgreSQL driver | MariaDB stačí; abstrakce v `DatabaseManager` je připravená |
| Další LLM poskytovatelé kromě Anthropic | až bude důvod, backendy jsou abstrahované |
| Mobilní nativní aplikace | responzivní web pokrývá potřebu |

---

## Jak se roadmapa udržuje

- Revize **po dokončení každého milníku**, ne průběžně. Průběžné změny patří
  do tasků, ne sem.
- Milník se nepovažuje za hotový, dokud není splněné jeho „Hotovo když".
- Nové zjištění, které nemá zadání, se zapíše do
  [`tasks/TODO.md`](../tasks/TODO.md) a přiřadí k milníku až při revizi.
- Platí konvence z [`tasks/README.md`](../tasks/README.md): žádné citlivé
  údaje z reálných dat. Tento dokument je ve veřejném repozitáři.

---

[← docs/README.md](README.md) · [tasks/](../tasks/README.md)
