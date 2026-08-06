---
title: Kontrola vytěženého dokladu
summary: Jak porovnat návrh dokladu s originálem faktury, co kontrolovat první a kdy návrh zamítnout.
keywords: [kontrola, vytěžení, analýza, náhled dokladu, jistota, použít, zamítnout, AI přečetla špatně, sedí částka, review]
related: [slovnicek.md, co-dnes-nejde.md]
---

# Kontrola vytěženého dokladu

AI přečte přijatou fakturu a nabídne hotový návrh dokladu. **Návrh není
doklad** — doklad vznikne teprve tím, že návrh potvrdíš. Než to uděláš,
projdi ho podle postupu níž.

## Kdy to potřebuješ

Zpráva s fakturou dorazila a analýza skončila — v **Došlé poště** má
badge **Analyzováno** a stav **K řešení**, na **Dashboardu** se objevila
karta s procentem **Jistoty** a tlačítkem **Zkontrolovat**.

## Postup

1. **Otevři náhled.** Na **Dashboardu** u karty s fakturou klikni na
   **Zkontrolovat**.

2. **Zorientuj se v náhledu.** Vlevo je **PDF** faktury, jak přišla,
   vpravo data, která z ní AI přečetla. Kontrola je porovnávání levé
   strany s pravou. Na telefonu a v úzkém okně se místo dvou sloupců
   objeví taby **PDF** a **Náhled**.

3. **Zkontroluj v tomhle pořadí.** Nezačínej řádky; začni tím, co se
   nejhůř opravuje později:

   | Sekce náhledu | Co porovnat s originálem |
   |---|---|
   | **Součty** | **Celkem** především. Pak **Základ**, **DPH**, **Zaokrouhlení** |
   | **DPH rekapitulace** | Sedí rozpad po sazbách? Je tam sazba, která na faktuře není? |
   | **Dodavatel** | **IČO** a **DIČ**. Podle nich se dohledává partner |
   | **Datumy** | **DUZP** a **Datum splatnosti** — ovlivní přiznání i saldokonto |
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
     a zpráva přejde na **Hotovo**.
   - **Zamítnout** — když to faktura vůbec není (reklama, upomínka)
     nebo je vytěžení nepoužitelné. Důvod je povinný a uloží se k návrhu:
     *špatně rozpoznaný typ*, *není to faktura*.

7. **Dokonči doklad.** Koncept se ti po **Použít** hned otevře
   v editačním formuláři a je plně editovatelný — co jsi v náhledu jen
   zaregistroval, oprav teď. Zaúčtuje se teprve přechodem
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

**Co udělá Zamítnout.** Návrh dostane stav **Zamítnuto**, důvod se uloží k němu a v **Došlé poště** ho u zprávy pak vidíš jako
*Důvod zamítnutí*. Karta z Dashboardu zmizí. Zpráva a přílohy zůstávají —
zamítá se návrh dokladu, ne e-mail. Zpráva přejde na **Hotovo**,
pokud u ní nezbývá žádný další návrh k vyřízení.

**Zamítnutí se z rozhraní nevrací.** Když jsi zamítl omylem, spusť
**Znovu analyzovat** — dostaneš nový návrh a ten zamítnutý zůstane
ležet vedle něj. Důvod zamítnutí nikam neodchází, zůstává jen v tvojí
agendě — když AI čte něco opakovaně špatně, nahlas to zvlášť.

**Ke stejnému náhledu se dostaneš i z Došlé pošty.** Otevři zprávu
a u vytěženého dokladu klikni na **Zobrazit detail**. Hodí se, když
karta na Dashboardu už není — třeba když se vracíš k něčemu staršímu.

**Vytěžení nespustíš na přání.** Analýza běží automaticky po doručení
zprávy. Ruční cesta je jen **Znovu analyzovat** u už doručené zprávy.

**Když dodavatel umí ISDOC, popros ho o něj.** Přiloženou fakturu ve
formátu ISDOC Shipard převezme přímo, bez AI — a je to přesnější než
cokoli popsané na téhle stránce.

## Souvisí

- [Když AI přečte fakturu špatně](kdyz-ai-cte-spatne.md) — kde se která
  chyba opravuje
- [Slovníček](../slovnicek.md) — co znamenají stavy a názvy sekcí
- [Co Shipard dnes neumí](../co-dnes-nejde.md) — kde ještě nemusí
  souhlasit čísla
- [Pro testery](../../TESTERS.md) — jak nahlásit rozdíl, který jsi našel
