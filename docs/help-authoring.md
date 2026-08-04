# Shipard — Jak se píše uživatelská dokumentace

Pravidla a šablona pro obsah adresáře [`help/`](../help/README.md). Tento
dokument je pro vývojáře (a pro Claude Code) — sám do `help/` nepatří.

Uživatelská dokumentace odpovídá na otázku **„jak to udělám"**. Technické
specifikace v [`docs/`](README.md) odpovídají na otázku **„jak je to
udělané"**. Tyto dva žánry se nesmí míchat.

---

## 1. Kde to žije a proč

Uživatelská dokumentace je **samostatný root `help/`**, ne podadresář
`docs/`. Důvody:

1. **Hranice žánru je cesta, ne konvence.** Pravidlo „žádné názvy tříd,
   žádné implementační detaily" platí pro celý adresář a nikdo si ho
   nemusí pamatovat.
2. **Vnitřní AI asistent potřebuje čistý indexovací root.** `help/**/*.md`
   je celá znalostní báze — bez allowlistu a bez výjimek. `docs/` obsahuje
   soubory po desítkách kilobajtů (`edit-forms.md` má 91 kB); jeden omyl
   v pravidlech filtrování by znamenal natažení specifikace formulářového
   enginu do kontextu modelu.
3. **Viditelnost.** Vedle `README.md`, `TESTERS.md` a `DEVELOPERS.md`
   vzniká úplný trojúhelník publik: uživatel, tester, vývojář.

Uživatelská dokumentace úloh **nepatří k modulům**. „Z došlé pošty udělat
doklad" se dotýká `core.mail`, dokladového systému i `economy.accounting` —
uživatelské úlohy přesahují hranice modulu vždy.

---

## 2. Dva čtenáři, jeden text

Stránky čte člověk na GitHubu **i** vnitřní AI asistent (přes čtecí nástroj,
zatím neimplementovaný — viz §7). Nepíšou se dvě verze; rozpor mezi nimi by
byl horší než chybějící dokumentace. Rozdílné potřeby se řeší strukturou:

| Potřeba | Jak se řeší |
|---|---|
| Model potřebuje explicitnost, člověk krátkost | Postup zůstane krátký; výjimky jdou do sekce *Na co narazíš* |
| Model nevidí obrazovku | Prvek se pojmenuje popiskem, jak je v rozhraní. Poloha („vpravo nahoře") jen jako doplněk, nikdy jako jediná identifikace |
| Screenshot je pro model prázdný | Žádná informace nesmí existovat jen v obrázku |
| Výsledek nástroje má být celá stránka | Jedna stránka = jedna úloha, cíl do ~150 řádků (nad 200 řádků generátor varuje) |

---

## 3. Hlavička stránky (front matter)

Každá stránka kromě `help/README.md` začíná YAML hlavičkou. Klíče jsou
anglicky (jde o strojová metadata, stejně jako identifikátory v kódu),
hodnoty česky.

```yaml
---
title: Kontrola vytěženého dokladu
summary: Jak porovnat návrh dokladu s originálem faktury a co kontrolovat první.
keywords: [vytěžení, analýza, jistota, kontrola, AI přečetla špatně]
related: [posta/prijem-posty.md, faktury-prijate/z-posty-na-doklad.md]
---
```

| Klíč | Povinný | Účel |
|---|---|---|
| `title` | ano | Nadpis stránky. Musí se shodovat s H1 v těle |
| `summary` | ano | Jedna věta do rozcestníku |
| `keywords` | ano | Výrazy, kterými uživatel úlohu pojmenuje — **včetně** hovorových a nesprávných variant. Slouží vyhledávání, ne SEO |
| `related` | ne | Cesty relativně ke `help/`. Generátor ověřuje, že cíle existují |

GitHub hlavičku vykreslí jako tabulku — to nevadí.

---

## 4. Šablona těla

```markdown
# Kontrola vytěženého dokladu

Jedna až dvě věty, o čem stránka je.

## Kdy to potřebuješ

Situace, ve které se uživatel nachází. Model si podle toho pozná,
že je stránka odpovědí na dotaz.

## Postup

1. Numerovaně, jeden krok = jedna akce.
2. Prvky jménem, jak jsou v rozhraní: sekce **Došlá pošta**, stav
   **Analyzovaná**, tlačítko **Přidat firmu z registru**.

## Na co narazíš

Známé zádrhely, výjimky, matoucí chování — a co znamenají.

## Souvisí

- [Název stránky](cesta.md)
```

Sekce *Kdy to potřebuješ*, *Postup* a *Souvisí* jsou povinné; *Na co
narazíš* se vynechá, když není co napsat.

---

## 5. Pravidla psaní

- **Jazyk:** čeština, tykání, oslovovaný v **mužském rodě** — shodně
  s [`TESTERS.md`](../TESTERS.md) („co jsi dělal, co jsi čekal“). Nemíchat
  rody v rámci stránky ani mezi stránkami.
- **Slovník rozhraní:** názvy sekcí, tlačítek a stavů musí souhlasit
  s popisky v aplikaci — server-driven labely v `module.jsonc`
  (`name:cs`) a frontendové v `frontend/src/i18n/cs.js`. Když popisek
  neznáš, **ověř ho ve zdroji**, neodhaduj. Dokumentace, která posílá
  uživatele klikat na neexistující tlačítko, je horší než žádná.
- **Žádné implementační detaily:** ani názvy tabulek, tříd, endpointů,
  ani hodnoty `docState`. Uživatel pracuje se stavem **V pořádku**, ne
  s `docState=40`.
- **Poctivost o alfě:** co nefunguje, patří do
  [`help/co-dnes-nejde.md`](../help/co-dnes-nejde.md), ne do mlčení.
  Bez toho si asistent chybějící funkci vymyslí.
- **Žádná citlivá data:** platí pravidlo veřejného repozitáře —
  žádné skutečné názvy firem, čísla dokladů, částky ani identifikátory
  datových zdrojů. Vynucuje `scripts/check-sensitive.py`.
- **Screenshoty:** zpočátku žádné. Zastarají první a modelu neřeknou nic.
- **Aktuálnost:** uživatelská stránka se aktualizuje **ve stejném commitu**
  jako změna funkce, kterou popisuje — stejné pravidlo jako u technické
  dokumentace.

---

## 6. Rozcestník a kontrola

`help/README.md` obsahuje generovaný blok mezi značkami `OBSAH:BEGIN` /
`OBSAH:END`. Zdroj pravdy jsou hlavičky stránek.

```
python3 scripts/help-index.py           # přepíše blok v help/README.md
python3 scripts/help-index.py --check   # jen ověří, exit 1 když nesedí
```

V režimu `--check` (volá pre-commit hook) skript zastaví commit, když:

- rozcestník nesedí s hlavičkami stránek,
- stránce chybí `title`, `summary` nebo `keywords`,
- `title` se rozchází s H1,
- odkaz v `related` míří na neexistující soubor,
- v `help/` je podadresář neznámý v `SECTIONS` (nová oblast se přidává
  do skriptu vědomě, ne omylem).

Nová stránka: vytvoř soubor s hlavičkou → spusť generátor →
`git add help/README.md`.

---

## 7. Vztah k vnitřnímu asistentovi

Asistent se dnes k dokumentaci **nemá jak dostat** — má systémový prompt
z cfgItem `core.chat.settings` a čtecí MCP nástroje, nic víc (viz
[`chat.md`](chat.md) §5). Plánovaný krok je čtecí nástroj (`help_search` +
`help_get_page`) nad `help/`; stránky leží na serveru vedle aplikace, takže
pro v1 stačí index a fulltext nad několika desítkami krátkých souborů —
žádné embeddingy, žádná další závislost.

Formát podle §3 a §4 je na to navržený: `keywords` pro vyhledání, celá
stránka jako výsledek nástroje bez chunkování, `related` pro dohledání
navazující úlohy.

---

## 8. Související dokumenty

- [`help/README.md`](../help/README.md) — rozcestník uživatelské dokumentace
- [`documentation.md`](documentation.md) — dokumentace modulů a tabulek (technická, u kódu)
- [`chat.md`](chat.md) — vnitřní AI asistent
- [`TESTERS.md`](../TESTERS.md) — pozvánka pro testery

---

[← docs/README.md](README.md)
