---
title: Prodejka
summary: Jak zapsat prodej za hotové nebo kartou na pokladně bez faktury a jak udělat vratku.
keywords: [prodejka, prodej za hotové, prodej na pokladně, paragon, účtenka, platba kartou, tržba, vratka, vrácení zboží, storno prodeje, prodejky]
related: [pokladna/pokladni-doklad.md, polozky/zalozeni-polozky.md, uctarna/dph-zive-vystupy.md, co-dnes-nejde.md]
---

# Prodejka

Prodejka je doklad o prodeji za hotové nebo kartou na pokladně — to, co
zákazník dostane místo faktury. Najdeš ji v **Prodej → Prodejky**. Stejně jako
pokladní doklad patří vždy jedné pokladně a má její číselnou řadu.

## Kdy to potřebuješ

Prodáváš zboží nebo služby na místě, zákazník platí hned a fakturu nechce.
Když zákazník platí hotově *fakturu*, použij [Pokladní doklad](pokladni-doklad.md)
s pohybem *Úhrada pohledávky*, nebo na faktuře zvol platbu *Hotovost* rovnou.

## Postup

1. **Měj založenou pokladnu** v **Nastavení → Účetnictví → Pokladny** ve
   stavu **V pořádku** — řadu prodejek jí Shipard založí sám.

2. **Otevři Prodej → Prodejky**, vyber dole záložku pokladny (máš-li jich
   víc) a dej **Přidat**.

3. **Vyplň hlavičku.** **Způsob platby** *Hotovost* nebo *Kartou*.
   **Partner** je nepovinný — vyplň ho, když zákazník chce mít prodej
   na své firmě (s DIČ pak prodej nad 10 000 Kč jde do kontrolního hlášení
   jednotlivě). **Datum vystavení** a **Účetní datum** jsou povinné.
   **Měna dokladu** je měna pokladny. **Režim DPH**, **Místo plnění**,
   **Registrace DPH** a zaokrouhlení najdeš na záložce **Nastavení** úplně
   vpravo — běžně je nemusíš měnit, doplní se samy.

4. **Dej Uložit** a přidej řádky: **Pohyb** *Prodej zboží* nebo *Prodej
   služeb*, **Položka**, **Množství**, cena a **Kód DPH** — stejně jako na
   vydané faktuře.

5. **Dej Potvrdit a V pořádku.** Prodejka dostane číslo (například
   `14HP12600001`), zaúčtuje se — hotovost na pokladnu, karta na peníze na
   cestě — a DPH z ní jde do přiznání za období podle DUZP.

## Na co narazíš

**Vratka je záporný řádek.** Vrací-li zákazník zboží, založ novou prodejku
a dej na řádek záporné množství (nebo zápornou cenu). Zvláštní druh dokladu
ani směr prodejka nemá; celková částka pak vyjde záporná a zaúčtuje se
opačně.

**Prodejka bez partnera jde do kontrolního hlášení souhrnně.** Do řádků
A4 (nad 10 000 Kč jednotlivě) se dostane jen prodej, u kterého je vyplněný
partner s českým DIČ — viz [Živé výstupy DPH](../uctarna/dph-zive-vystupy.md).

**Účtenku pro zákazníka z Shipardu nedostaneš.** Tisk prodejky zatím
neexistuje, stejně jako u vydaných faktur — viz
[Co Shipard dnes neumí](../co-dnes-nejde.md).

## Souvisí

- [Pokladní doklad](pokladni-doklad.md) — příjem a výdej hotovosti, úhrady faktur
- [Založení položky](../polozky/zalozeni-polozky.md) — co se dá dát na řádek
- [Živé výstupy DPH](../uctarna/dph-zive-vystupy.md) — kam prodejka spadne
  v přiznání a kontrolním hlášení
- [Co Shipard dnes neumí](../co-dnes-nejde.md) — tisk účtenky, pokladní kniha
