---
title: Oprava dokladu
summary: Jak opravit nebo zrušit doklad, který už je ve stavu V pořádku, a čemu se přitom vyhnout.
keywords: [oprava, opravit doklad, v opravě, storno, stornovat, zrušit fakturu, smazat doklad, přeúčtovat, špatná částka na faktuře]
related: [faktury-prijate/dokonceni-dokladu.md, slovnicek.md, co-dnes-nejde.md]
---

# Oprava dokladu

Doklad ve stavu **V pořádku** je uzamčený — nedá se do něj psát. Není to
překážka, kterou je potřeba obejít: oprava má vlastní stav, ve kterém se
doklad odúčtuje, opraví a zaúčtuje znovu.

## Kdy to potřebuješ

V hotovém dokladu je chyba — špatná částka, sazba, datum nebo dodavatel.
Nebo faktura neplatí celá a potřebuješ ji zrušit.

## Postup

1. **Otevři doklad.** **Nákup → Faktury přijaté**, klikni na doklad.

2. **Rozhodni, o jaký případ jde.** Cesty jsou tři a nejsou zaměnitelné:

   | Situace | Co udělat |
   |---|---|
   | Špatná data na dokladu | **Opravit** (dál podle kroku 3) |
   | Data jsou správně, jen se doklad nezaúčtoval | **Přeúčtovat** |
   | Faktura neplatí celá | **Stornovat** |

3. **Dej Opravit.** Doklad přejde do stavu **V opravě**, je znovu
   editovatelný a **číslo si nechává** — opravou se nemění. Zároveň se
   odúčtuje: zápis v Účetním deníku i pohyby v saldokontu zmizí.

4. **Oprav a dej V pořádku.** Účetní zápis se vytvoří znovu z aktuálních
   dat, takže nevzniknou dvojí zápisy.

5. **Zkontroluj párování s platbou.** Pokud už byla faktura spárovaná
   s platbou z banky, podívej se po opravě do saldokonta — párování se
   nemusí obnovit samo a platba by pak visela zvlášť.

## Na co narazíš

**Když je chyba jen v účtování, doklad do opravy netahej.** Chybí-li
nastavení u položky nebo účtu, doplň ho a dej u dokladu **Přeúčtovat**.
Doklad zůstane ve **V pořádku** a — na rozdíl od opravy — se párování
s platbou nerozpojí.

**Z V opravě se nedá vrátit do Konceptu.** Z tohohle stavu vedou jen cesty
zpět na **V pořádku**, do **Storna**, nebo do koše. Je to tak schválně:
doklad už má číslo a Koncept by ho uvolnil.

**Storno doklad nemaže.** Zůstává v evidenci, ale neplatí a je odúčtovaný.
Ze Storna se dá jít jedině přes **Opravit**, takže ani storno není konečné.

**Doklad s číslem nemaž.** Smazat sice jde, ale v číselné řadě zůstane díra
a nebude dohledatelné, co se s dokladem stalo. Správná cesta je **Storno**.
Smazaný doklad se dá vrátit tlačítkem **Opravit**.

**Opravu po odevzdaném přiznání k DPH Shipard nezastaví.** Období se dnes
nedá uzamknout (viz [Co Shipard dnes neumí](../co-dnes-nejde.md)), takže si
klidně opravíš doklad v období, které už je nahlášené — a podklady se zpětně
změní. Jestli tě to potkalo, řeš to s účetní, ne kliknutím.

## Souvisí

- [Dokončení dokladu](dokonceni-dokladu.md) — jak doklad vzniká a co dělá
  přechod na V pořádku
- [Slovníček](../slovnicek.md) — přehled stavů dokladu
- [Co Shipard dnes neumí](../co-dnes-nejde.md)
