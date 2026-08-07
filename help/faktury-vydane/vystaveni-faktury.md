---
title: Vystavení faktury
summary: Jak vystavit fakturu odběrateli — od Přidat po V pořádku — a proč ji z Shipardu zatím nedostaneš na papír.
keywords: [vystavit fakturu, vystavení faktury, vystavuji fakturu, vydaná faktura, vydané faktury, faktura odběrateli, faktura zákazníkovi, fakturovat, nová vydaná faktura, prodej služeb, prodej zboží, poslat fakturu odběrateli, odeslat fakturu e-mailem, vytisknout fakturu, tisk faktury, PDF faktury, náš bankovní účet, variabilní symbol na faktuře]
related: [osoby/zalozeni-osoby.md, polozky/zalozeni-polozky.md, faktury-prijate/oprava-dokladu.md, co-dnes-nejde.md]
---

# Vystavení faktury

Faktura vydaná je doklad pro odběratele. Zakládá se vždy ručně — z došlé pošty,
na rozdíl od přijatých faktur, nevzniká. Najdeš ji v **Prodej → Faktury
vydané**.

**Hotovou fakturu z Shipardu zatím nedostaneš.** Tisk, PDF ani odeslání
odběrateli e-mailem neexistují — viz [Co Shipard dnes neumí](../co-dnes-nejde.md).
Vystavit, zaúčtovat a hlídat ji v saldokontu jde celou; doklad, který drží
v ruce odběratel, musíš zatím vyrobit jinde.

## Kdy to potřebuješ

Fakturuješ odběrateli a chceš mít fakturu v evidenci, v účetnictví a v přehledu
toho, kdo ti kolik dluží. Nebo zkoušíš, co Shipard u vydané faktury umí a co ne.

## Postup

1. **Otevři Prodej → Faktury vydané a dej Přidat.**

2. **Vyplň hlavičku.** Vlevo je partner a datumy, vpravo DPH, měna,
   zaokrouhlení a platba:

   - **Partner** je odběratel. Hledá se psaním; když ho v evidenci ještě nemáš,
     založíš ho přímo odsud — viz [Založení osoby](../osoby/zalozeni-osoby.md).
   - **Adresa partnera** a **Bankovní účet partnera** se dají vybrat teprve
     po zvolení partnera.
   - **Datum vystavení** vyplň sám — předplněné není a je povinné. Jak ho
     zadáš, doplní se z něj **Účetní datum** a **DUZP**.
   - **Datum splatnosti** se doplní po volbě partnera podle splatnosti
     sjednané u něj; když zůstane prázdné, dopočítá se při uložení
     (bez sjednané splatnosti 14 dní od vystavení). Přepsat jde všechna.
   - **Registrace DPH** vyplň vždy, když fakturuješ s daní. Bez ní nepůjde
     na řádku zvolit **Kód DPH**.
   - **Náš bankovní účet** je u vydané faktury povinný — je to účet, na který
     má odběratel zaplatit.
   - **Text dokladu** je krátký popis, pod kterým fakturu poznáš v seznamu.

3. **Dej Uložit.** Řádky se dají zadávat až u uloženého dokladu; do té doby
   na tabu **Řádky** stojí, že je potřeba záznam nejprve uložit.

4. **Zadej řádky.** Tab **Řádky** → **Přidat**. Na řádku vyplň:

   - **Pohyb** — *Prodej služeb* (předvolený) nebo *Prodej zboží*. Určuje,
     jak se řádek zaúčtuje.
   - **Položka** z katalogu — vytáhne popis, jednotku a prodejní cenu.
     Chybějící položku založíš přímo z řádku, viz
     [Založení položky](../polozky/zalozeni-polozky.md).
   - **Množství** a **Cena/jednotka**; **Cena celkem** se dopočítá. Slevu
     zadej v sekci *Sleva* procentem, nebo částkou — ne obojím.
   - **Kód DPH** — po jeho výběru se doplní **DPH %** podle DUZP.

   Potřebuješ-li na faktuře mezititulek nebo komentář, přepni **Typ řádku**
   na *Textový řádek*. Do součtů nevstupuje.

5. **Zkontroluj tab Rekapitulace DPH.** Je to rozpis základu a daně po sazbách
   a celkový součet. Nesouhlasí-li s tím, co čekáš, oprav řádky teď — ne až po
   potvrzení.

6. **Dej Potvrdit.** Doklad dostane **číslo** z číselné řady, jako
   **Variabilní symbol** se předplní jeho pořadové číslo a do dokladu se
   zmrazí **Fakturační údaje** — tvoje i odběratelovy údaje v podobě, v jaké
   platí teď. Formulář zůstává otevřený a doklad se dál dá upravovat.

7. **Dej V pořádku.** Doklad se uzamkne, **zaúčtuje** a **objeví se
   v saldokontu** jako nezaplacená pohledávka, kterou pak spáruješ s platbou
   z banky. Formulář se zavře.

## Na co narazíš

**Než vystavíš první fakturu, potřebuješ nastavené tři věci.** Vlastní firmu
v **Osobách** — bez ní Shipard při Potvrzení napíše, že vlastní firma není
nastavená a doklad nepotvrdí. Dál **Registraci DPH** a **Bankovní spojení**,
obojí v **Nastavení → Účetnictví**. Číselnou řadu pro vydané faktury Shipard
zakládá sám, o tu se starat nemusíš.

**Nabídka Kód DPH je prázdná.** Řádek si bere údaje o DPH z *uloženého*
dokladu, takže musí být vybraná **Registrace DPH** a hlavička uložená. Když
jsi registraci právě doplnil, dej **Uložit** a řádek otevři znovu.

**Číselnou řadu ve formuláři nenajdeš.** U vydaných faktur se na ni Shipard
neptá — doklad se založí do řady, která je právě vybraná v seznamu. Máš-li řad
víc, přepínají se v liště dole pod seznamem faktur a seznam přitom ukazuje jen
doklady vybrané řady. S jedinou řadou, což je běžný stav, lišta není vidět
vůbec.

**Variabilní symbol není číslo faktury.** Předplní se pořadovým číslem v řadě,
ne celým číslem dokladu. Když potřebuješ jiný, přepiš ho — přepis se
nepřepisuje zpátky.

**Do Konceptu se vrací jen poslední faktura v řadě.** Jinak by v číslování
zůstala díra. Když potřebuješ opravit starší fakturu, použij **Storno** a
vystav novou.

**Opravy a storno fungují stejně jako u přijatých faktur.** Stavy jsou
společné všem dokladům: hotovou fakturu převedeš na **V opravě**, opravíš
a vrátíš na **V pořádku**; neplatnou fakturu **Stornuješ**, nemažeš. Přechody
a jejich pravidla popisuje [Oprava dokladu](../faktury-prijate/oprava-dokladu.md)
— je psaná pro přijaté faktury, ale přechody platí i tady. Co u vydaných
faktur platí metodicky jinak, popsané zatím není.

**Že fakturu nikdo neuvidí, není chyba nastavení.** Chybějící tisk, PDF
a odeslání e-mailem je známé omezení alfy, ne něco, co by se dalo někde
zapnout.

## Souvisí

- [Založení osoby](../osoby/zalozeni-osoby.md) — jak dostat odběratele do
  evidence
- [Založení položky](../polozky/zalozeni-polozky.md) — co se dá dát na řádek
- [Oprava dokladu](../faktury-prijate/oprava-dokladu.md) — přechody stavů
  (psáno pro přijaté faktury)
- [Co Shipard dnes neumí](../co-dnes-nejde.md) — chybějící výstupy
- [Slovníček](../slovnicek.md) — co znamenají stavy a názvy v rozhraní
