# Pro testery — Nový Shipard

Vítej! Tenhle dokument je pro lidi, kteří si chtějí Shipard vyzkoušet na
vlastní agendě. Nepotřebuješ k tomu nic instalovat ani umět programovat —
aplikace běží na našem serveru a otevřeš ji v prohlížeči.

(Pokud jsi vývojář a chceš si Shipard rozjet u sebe, jdi na
[DEVELOPERS.md](DEVELOPERS.md).)

---

## 1. Co Shipard je a v jakém je stavu

Shipard je systém pro správu firemní agendy — vydané a přijaté faktury,
účetnictví, evidence dokumentů a zpracování došlé pošty. Jeho hlavní
myšlenka je, že se doklady nepřepisují ručně: faktura přijde e-mailem,
Shipard z ní pomocí AI vytáhne dodavatele, položky a částky, a ty už jen
zkontroluješ a potvrdíš.

**Stav: alfa.** Znamená to, že hlavní věci fungují, ale narazíš na chyby,
na nedodělané obrazovky a na to, že se něco mezi týdny promění. Přesně
proto tě tu chceme — abys nám řekl, kde to drhne.

**Tvoje data jsou trvalá.** Datový zdroj, který dostaneš, není hračka na
týden: nemažeme ho, a když se změní vnitřní struktura aplikace, data
převedeme. O rozdělanou práci nepřijdeš. Přesto ti u alfa verze
doporučujeme nemít Shipard jako jediné místo, kde tvoje účetnictví
existuje — daňové přiznání se z něj zatím podávat nedá
([co dalšího zatím nejde](help/co-dnes-nejde.md)).

---

## 2. Jak si říct o přístup

Přístupy zakládáme ručně. Napiš e-mail na **podpora@shipard.cz** a uveď:

- **jméno a kontaktní e-mail** — na ten e-mail ti přijdou přihlašovací údaje,
- **název firmy nebo organizace**, pro kterou to chceš zkoušet,
- **jsi plátce DPH?** (ano / ne),
- **chceš prázdný prostor, nebo s ukázkovými daty** na hraní,
- volitelně: čeho by sis chtěl všimnout nejvíc (došlá pošta, fakturace,
  účetnictví…), ať víme, na co se tě pak ptát.

Zpátky dostaneš odkaz na aplikaci, uživatelské jméno, heslo a adresu, na
kterou můžeš Shipardu přeposílat poštu ke zpracování. Obvykle to trvá
několik pracovních dnů. Nic dalšího instalovat nemusíš — stačí prohlížeč
(Chrome, Firefox, Edge, Safari; funguje i na mobilu).

---

## 3. První kroky

Po přihlášení jsi na **Dashboardu**. To je domovská obrazovka, která
ukazuje, co je právě potřeba udělat — nová pošta ke zpracování,
upozornění na nesrovnalosti, rozdělané doklady.

V levém panelu najdeš:

| Kde | Co tam je |
|---|---|
| **Došlá pošta** | E-maily, které do systému přišly, a co z nich AI vytáhla |
| **Nákup** | Faktury přijaté |
| **Prodej** | Faktury vydané |
| **Účtárna** | Účetní deník, saldokonta, bankovní transakce |
| **Základní** | Osoby (dodavatelé a odběratelé), položky, Spisovna |

Co doporučujeme zkusit, a v tomhle pořadí — zpracování přijaté pošty je
to, co nás teď zajímá nejvíc:

1. **Pošli do Shipardu přijatou fakturu.** Na adresu pro příjem pošty
   (dostaneš ji e-mailem spolu s přístupy) přepošli libovolnou fakturu
   v PDF a chvíli počkej. Objeví se v *Došlé poště* a Dashboard ti nabídne
   kartu „vytvořit doklad".
2. **Zkontroluj, co AI vytáhla.** Tohle je pro nás nejcennější část.
   Před uložením uvidíš náhled dokladu — porovnej ho s originálem
   a všimni si, co je špatně: sazby DPH, jednotky, počty, celková částka.
3. **Podívej se do Účetního deníku**, jak se doklad zaúčtoval. Účty
   nezadáváš, skládají se automaticky.
4. **Založ vydanou fakturu.** Ruční práce, ale uvidíš, jak se v Shipardu
   vyplňují doklady. Partnera si nech dotáhnout z registru
   (tlačítko *Z registru* — stačí IČO).
5. **Klikni na cokoli, co tě zaujme.** Nemůžeš nic rozbít tak, abychom to
   nespravili.

---

## 4. Co nás na zpětné vazbě zajímá nejvíc

- **Věcná správnost čísel.** Nesedící součet, špatně spočítaná DPH, jiná
  celková částka než na originálu. Tohle je pro nás priorita číslo jedna —
  hlaš i rozdíl o korunu.
- **Kvalita vytěžení faktur.** Které faktury AI zpracuje dobře a které jí
  nesednou. Klidně napiš i „tuhle přečetla úplně správně" — i to je
  informace.
- **Srozumitelnost.** Kde jsi nevěděl, co se od tebe chce, nebo kde jsi
  hledal funkci a nenašel ji.
- **Co ti chybí.** Když ti Shipard nedovolí udělat něco, co ve svém
  dnešním programu děláš běžně, chceme to vědět.

---

## 5. Jak nahlásit chybu

**Nepiš nám chyby e-mailem.** Zakládej je jako *issue* na GitHubu —
máme je tam všechny na jednom místě, vidíš stav svého hlášení a nezapadne to.

👉 **[Nahlásit chybu nebo napsat nápad](https://github.com/shipard/shpd/issues/new/choose)**

Budeš k tomu potřebovat účet na GitHubu. Když ho nemáš, zřídíš ho za dvě
minuty na [github.com/signup](https://github.com/signup) — je zdarma
a stačí e-mail.

Po kliknutí si vyber šablonu (*Hlášení chyby* nebo *Nápad na vylepšení*)
a vyplň formulář. Nejdůležitější jsou tři věci: **co jsi dělal, co jsi
čekal, co se stalo místo toho.** Screenshot pomůže vždycky.

### ⚠️ Pozor: hlášení jsou veřejná

Tenhle repozitář je veřejný, takže **cokoli do hlášení napíšeš, uvidí
kdokoli na internetu — a zůstane to tam.** Do hlášení proto nikdy nedávej:

- jména a adresy skutečných dodavatelů a odběratelů,
- čísla faktur, variabilní symboly, čísla účtů,
- konkrétní částky,
- screenshoty, na kterých je něco z výše uvedeného vidět.

**Jak to obejít:** napiš, *kdy* (datum a přibližný čas) a *co* jsi dělal,
a doplň třeba „faktura od dodavatele ze Slovenska, tři položky, dvě sazby
DPH". My si podle času najdeme konkrétní záznam v datech sami. Na
screenshotech citlivé údaje začerni — stačí obdélníky v malování.

Když si nejsi jistý, jestli to jde napsat veřejně, napiš nám na
**podpora@shipard.cz** a hlášení založíme za tebe.

---

## 6. Kde se ptát

Issues jsou na chyby a nápady. Když se jen chceš na něco **zeptat** — jak
se něco dělá, jestli je něco chyba nebo tvoje nepochopení, jak jsi na tom
s přístupem — napiš na **podpora@shipard.cz**.

Není hloupá otázka. Když něco nejde najít, je to většinou naše chyba,
ne tvoje.

---

## 7. Kde se to naučit

Návody „jak se co v Shipardu dělá“ jsou v
[uživatelské dokumentaci](help/README.md). Vzniká postupně — nejdřív
zpracování došlé pošty a přijaté faktury, tedy to, co tě jako testera
čeká nejdřív.

Dvě stránky se vyplatí přečíst hned:

- [Slovníček](help/slovnicek.md) — co který název znamená a jak se
  jmenuje v rozhraní.
- [Co Shipard dnes neumí](help/co-dnes-nejde.md) — než začneš hledat
  funkci, která zatím neexistuje.

Zeptat se můžeš i **Chatu** v aplikaci — vestavěný asistent odpovídá
z téže dokumentace a vidí přitom tvoje data.

---

Díky, že to s námi zkoušíš. Bez zpětné vazby od lidí, kteří v tom
opravdu pracují, bychom Shipard postavili špatně.
