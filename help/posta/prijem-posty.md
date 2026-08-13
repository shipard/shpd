---
title: Příjem pošty
summary: Jak dostat fakturu do Shipardu, co se s ní pak děje a jak si poradit s poštou, která faktura není.
keywords: [příjem pošty, přeposlat fakturu, adresa pro poštu, kam poslat fakturu, nahrát soubor, nahrání z dashboardu, přetáhnout soubor, nedorazilo, reklama, newsletter, hromadná pošta, pravidlo odesílatele, ISDOC]
related: [posta/kontrola-vytezeni.md, slovnicek.md, co-dnes-nejde.md]
---

# Příjem pošty

Shipard nemá schránku, do které bys chodil číst e-maily. Faktury mu
přeposíláš na adresu, kterou jsi dostal, nebo je nahraješ přímo
z Dashboardu — a on je sám přečte a připraví z nich doklady.

## Kdy to potřebuješ

Přišla ti faktura od dodavatele — e-mailem nebo jako PDF na disku — a nechce
se ti ji přepisovat do systému ručně.

## Postup

1. **Najdi svou adresu pro příjem pošty.** Dostal jsi ji e-mailem spolu
   s přihlašovacími údaji. V aplikaci zatím vidět není; když se ti ztratila,
   napiš na **podpora@shipard.cz**.

2. **Přepošli fakturu na tuhle adresu.** Buď přepošli celý e-mail od
   dodavatele, nebo napiš nový a přilož PDF. Předmět ani text psát nemusíš —
   Shipardu jde o přílohu. **Máš-li fakturu jako soubor na disku**, nemusíš
   nic posílat: na **Dashboardu** klikni na **Nahrát**, nebo soubory
   přetáhni myší kamkoli na plochu Dashboardu.

3. **Podívej se do Došlé pošty.** Zpráva se objeví se stavem **Nová**
   a u ní badge, který ukazuje, jak daleko je strojové čtení: **Ve frontě**
   → **Analyzuje se** → **Analyzováno**.

4. **Vyzvedni si výsledek na Dashboardu.** Když AI ve zprávě našla fakturu,
   zpráva se sama přepne na **K řešení** a na Dashboardu se objeví karta —
   u jistého návrhu s tlačítkem **Použít**, jinak **Zkontrolovat**. Odtud
   pokračuj podle [Kontrola vytěženého dokladu](kontrola-vytezeni.md).

## Na co narazíš

**Nahrání z Dashboardu.** Tlačítko **Nahrát** (vedle **Obnovit**) otevře
okno, kam soubory přetáhneš nebo je vybereš tlačítkem **Vybrat soubory**;
totéž okno se otevře, když soubory přetáhneš rovnou na plochu Dashboardu.
U více souborů si vybereš, jestli vznikne **Jedna zpráva** se všemi
soubory, nebo **Každý soubor zvlášť** (výchozí — každá faktura je pak
samostatná zpráva s vlastní analýzou). Najednou lze nahrát nejvýše
20 souborů. Nahraná zpráva se tváří jako běžná pošta: najdeš ji v **Došlé
poště** (jako odesílatel jsi uveden ty), AI ji přečte a výsledek si
vyzvedneš na Dashboardu úplně stejně.

**Co posílat.** Ověřené je **PDF**. Nejlepší je **ISDOC** — strojově
čitelnou fakturu Shipard převezme přímo, bez AI, takže nemá co přečíst
špatně; když ho tvůj dodavatel umí, popros ho o něj. Fotku nebo sken
zkusit můžeš, ale nespoléhej na výsledek a zkontroluj ho o to pečlivěji.

**Víc dokumentů v jedné zprávě.** Z jednoho e-mailu vznikne **nejvýše
jeden návrh** — AI vybere hlavní dokument zprávy (typicky fakturu).
Když ve zprávě najde ještě něco dalšího (smlouvu vedle faktury, druhou
fakturu), ukáže to na kartě jako poznámku; dokument z toho automaticky
nevznikne a založíš ho ručně — viz
[Co Shipard dnes neumí](../co-dnes-nejde.md). Když posíláš víc faktur,
pošli každou samostatným e-mailem.

**Dokument do Spisovny.** Když AI pozná smlouvu, pojistku, nabídku,
revizi nebo úřední písemnost, nabídne na Dashboardu zařazení do
**Spisovny** místo dokladu. Vzniklý záznam dostane **všechny přílohy
zprávy** — jedno doručení = jeden záznam, jako v podacím deníku.

**Když to není faktura.** Reklamu, newsletter nebo upomínku AI pozná
a místo návrhu dokladu se na Dashboardu objeví karta s akcemi **Do koše**
a **Archivovat**. Rozdíl je jen v tom, kam zpráva zmizí; obojí ji odklidí
z cesty a přílohy zůstanou.

**Hromadnou poštu Shipard pozná, ale sám ji neodklidí.** Newslettery se
dají rozpoznat z hlaviček e-mailu (odhlašovací odkaz a podobné). Je to pro
Shipard jen příznak — nikdy podle něj nic automaticky nearchivuje.

**Pravidla odesílatelů se učí z toho, co děláš.** Když **třikrát** ručně
odklidíš poštu od stejného odesílatele do Archivu nebo Koše, Shipard
navrhne pravidlo a na Dashboardu ti ho nabídne k **Potvrzení**. Od potvrzení
dál se pošta od té adresy archivuje sama, bez analýzy.

- Navržené pravidlo je vždy na konkrétní adresu. Pravidlo na celou doménu
  si můžeš založit sám, ale Shipard ti ho nikdy nenavrhne — na domény je
  úmyslně opatrný.
- Do těch tří odklizení se počítají **jen tvoje ruční akce**. Co Shipard
  archivoval sám podle pravidla, se nezapočítá, takže se pravidla nemůžou
  nabalovat sama na sebe.
- Auto-archivované zprávy nezmizí bez zprávy: Dashboard ukáže denní kartu
  *„N zpráv automaticky archivováno"* s tlačítky **Zobrazit** a **Vrátit
  vše**. Vrácení platí pro celý den z té karty.

**Analýza selhala.** Badge **Analýza selhala** a na Dashboardu naléhavá
karta. Typicky nečitelné PDF (obrázek bez textové vrstvy, poškozený soubor).
Zkus **Znovu analyzovat**; když to selže znovu, doklad zadej ručně a pošli
nám vědět, co to bylo za fakturu.

**Nic nedorazilo.** Zkontroluj v tomhle pořadí: sedí adresa, na kterou jsi
posílal? Byla faktura opravdu jako příloha, ne jen odkaz ke stažení? Neuvízl
e-mail u tvého poskytovatele?

**Doručenou zprávu Shipard nemaže.** Ani po vytvoření dokladu, ani po
zamítnutí návrhu. Zpráva i s přílohami zůstává v **Došlé poště** jako důkaz,
odkud doklad vznikl.

## Souvisí

- [Kontrola vytěženého dokladu](kontrola-vytezeni.md) — co dělat s návrhem,
  který AI připravila
- [Slovníček](../slovnicek.md) — stavy zpráv a co znamená badge analýzy
- [Co Shipard dnes neumí](../co-dnes-nejde.md)
