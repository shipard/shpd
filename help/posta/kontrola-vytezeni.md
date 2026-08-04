---
title: Kontrola vytěženého dokladu
summary: Jak porovnat návrh dokladu s originálem faktury, co kontrolovat první a kdy návrh zamítnout.
keywords: [kontrola, vytěžení, analýza, náhled dokladu, jistota, použít, zamítnout, AI přečetla špatně, sedí částka, review]
related: [slovnicek.md, co-dnes-nejde.md]
---

# Kontrola vytěženého dokladu

AI přečte přijatou fakturu a nabídne hotový návrh dokladu. **Návrh není
doklad** — vzniká teprve tím, že ho potvrdíš. Tahle stránka je o těch pěti
minutách mezi jedním a druhým.

## Kdy to potřebuješ

Zpráva s fakturou dorazila a analýza skončila — v **Došlé poště** je ve
stavu **Analyzovaná**, na **Dashboardu** se objevila karta s procentem
**Jistoty** a tlačítkem **Zkontrolovat**.

## Postup

1. **Otevři náhled.** Z Dashboardu tlačítkem **Zkontrolovat**. Nebo
   v **Došlé poště** otevři zprávu a u vytěženého dokladu klikni na
   **Zobrazit detail**.

2. **Přepni na tab PDF a najdi originál.** Náhled dokladu má taby **PDF**
   a **Náhled** — mezi nimi budeš při kontrole přepínat.

3. **Zkontroluj v tomhle pořadí.** Nezačínej řádky; začni tím, co se
   nejhůř opravuje později:

   | Sekce náhledu | Co porovnat s originálem |
   |---|---|
   | **Součty** | **Celkem** především. Pak **Základ**, **DPH**, **Zaokrouhlení** |
   | **DPH rekapitulace** | Sedí rozpad po sazbách? Je tam sazba, která na faktuře není? |
   | **Dodavatel** | **IČO** a **DIČ**. Podle nich se dohledává partner |
   | **Datumy** | **DUZP** a **Splatnost** — ovlivní přiznání i saldokonto |
   | **Platba** | **Variabilní symbol** a **Bankovní účet** — podle nich se pak páruje platba |
   | **Řádky** | Množství, **Cena/j**, sazba **DPH**. Namátkově, pokud sedí Součty |

4. **Projdi sekci Upozornění.** Sem Shipard píše, co mu na návrhu nesedí.
   Když je prázdná, nic to nezaručuje — jen tam nic nenašel.

5. **Rozhodni nejasné reference.** U dodavatele, položky nebo bankovního
   účtu může být místo hodnoty výzva k rozhodnutí — **Vybrat
   existujícího…**, **Vytvořit nového**, u řádku **Vynechat řádek**.
   Dokud něco zůstává nerozhodnuté, tlačítko **Použít** je nedostupné
   s poznámkou *Některé reference vyžadují rozhodnutí*.

6. **Použij, nebo zamítni.**
   - **Použít** — vznikne **Faktura přijatá** ve stavu **Koncept**
     a zpráva přejde na **Zpracovaná**.
   - **Zamítnout** — když to faktura vůbec není (reklama, upomínka)
     nebo je vytěžení nepoužitelné. Důvod je povinný; piš ho tak, aby dal
     smysl i nám: *špatně rozpoznaný typ*, *není to faktura*.

7. **Dokonči doklad.** Koncept je pořád plně editovatelný — co jsi
   v náhledu jen zaregistroval, oprav teď. Zaúčtuje se teprve přechodem
   na stav **V pořádku**.

## Na co narazíš

**Jistota není správnost.** Procento říká, jak si byl model jistý sám
sebou. Badge u vytěženého dokladu se z něj odvozuje:

| Badge | Jistota | Co to znamená pro tebe |
|---|---|---|
| **K použití** | 90 % a víc | Zkontroluj Součty a Datumy. Zbytek namátkou |
| **Čeká na review** | 60–90 % | Projdi všechny sekce z kroku 3 |
| **Nízká jistota** | pod 60 % | Čti řádek po řádku, nebo zamítni a zadej ručně |
| **Chyba AI** | — | Extrakce se nepovedla, typicky nečitelné PDF. Zkus **Znovu analyzovat** |

**Doplněno z historie.** U některých polí najdeš poznámku, že hodnota
nepřišla z faktury, ale z tvých starších dokladů od stejného dodavatele —
*přesná shoda*, *podobný text* nebo *častá položka dodavatele*. První dvě
bývají spolehlivé; **častou položku ověřuj vždy** — znamená jen „tohle
u tohohle dodavatele býváš zvyklý", ne že to je na téhle faktuře.

**Faktury s cenami včetně DPH.** U dokladů, kde jsou jednotkové ceny
uvedené s daní (typicky drobný prodej, občerstvení), se daň může spočítat
dvakrát a **Celkem** pak vyjde vyšší než na faktuře. Je to známá chyba —
viz [Co Shipard dnes neumí](../co-dnes-nejde.md). U takových faktur
kontroluj celkovou částku vždy.

**Reverse charge.** U dokladů se samovyměřením (typicky zboží nebo služba
z EU) se rozpad daně v **DPH rekapitulaci** opravuje. Zkontroluj, že
**Celkem** odpovídá tomu, co máš zaplatit.

**Znovu analyzovat nic neztratí.** Opakovaná analýza označí staré návrhy
ve stavech *K použití*, *Čeká na review* a *Nízká jistota* jako
**Nahrazeno**. Co jsi už použil nebo zamítl, zůstane.

**Zamítnutí nemaže poštu.** Zpráva a přílohy zůstávají v **Došlé poště**.
Zamítá se návrh dokladu, ne e-mail.

**Vytěžení nespustíš na přání.** Analýza běží automaticky po doručení
zprávy. Ruční cesta je jen **Znovu analyzovat** u už doručené zprávy.

**Když dodavatel umí ISDOC, poproš ho o něj.** Přiloženou fakturu ve
formátu ISDOC Shipard převezme přímo, bez AI — a je to přesnější než
cokoli popsané na téhle stránce.

## Souvisí

- [Slovníček](../slovnicek.md) — co znamenají stavy a názvy sekcí
- [Co Shipard dnes neumí](../co-dnes-nejde.md) — kde ještě nemusí
  souhlasit čísla
- [Pro testery](../../TESTERS.md) — jak nahlásit rozdíl, který jsi našel
