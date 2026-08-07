---
title: Dokončení dokladu
summary: Co se děje po Použít — od Konceptu přes Potvrzeno k V pořádku a co se tím spustí.
keywords: [dokončení, koncept, potvrzeno, v pořádku, číslo faktury, číselná řada, zaúčtování, saldokonto, uzamčení dokladu]
related: [posta/kontrola-vytezeni.md, faktury-prijate/oprava-dokladu.md, slovnicek.md]
---

# Dokončení dokladu

Doklad z došlé pošty vzniká jako **Koncept** — rozpracovaný záznam, který
zatím nikam nepočítá. Aby se zaúčtoval a objevil v saldokontu, musí projít
dvěma přechody. Tahle stránka je o tom, co se při každém z nich stane.

## Kdy to potřebuješ

Potvrdil jsi vytěžený návrh, doklad se otevřel v editačním formuláři a ty
řešíš, co s ním dál. Nebo hledáš doklad, který jsi rozdělal a nedokončil.

## Postup

Kroky 1–3 se dějí v **editačním formuláři** — tom, který se ti otevřel po
**Použít**. Tlačítka dole ve formuláři jsou **Uložit**, **Potvrdit**
a **V pořádku**.

1. **Dokonči Koncept.** Tady je editovatelné všechno: řádky, částky, sazby,
   datumy, dodavatel. Zkontroluj především **Účetní datum**,
   **DUZP** a **Datum splatnosti** — od nich se odvíjí období a saldokonto.
   Doklad z pošty už má nastavenou **číselnou řadu**, takže na ni myslet
   nemusíš.

2. **Dej Potvrdit.** Tím doklad dostane **číslo** z číselné řady. Formulář
   zůstane otevřený a doklad se dál dá upravovat — Potvrzeno není
   uzamčené.

   Tenhle mezikrok má smysl u faktur, které chceš mít očíslované, ale
   nechceš je zaúčtovat: čekáš na chybějící informaci, ověřuješ dodávku,
   nebo se s dodavatelem o něčem dohaduješ.

3. **Dej V pořádku.** Teprve tím se doklad stává hotovým a **formulář se
   zavře** — práce s dokladem tím pro tebe končí. Na pozadí se stane tohle:

   - doklad se **uzamkne** — v tomto stavu se needituje,
   - **zaúčtuje se** — vznikne zápis v **Účetním deníku**,
   - **objeví se v saldokontu** jako nezaplacená položka, kterou pak
     spáruješ s platbou z banky.

4. **Když chceš zkontrolovat zaúčtování, jdi na doklad znovu.** Ve
   formuláři účetní zápis není. Otevři **Nákup → Faktury přijaté**, klikni
   na doklad a přepni na záložku **Zaúčtování**. Je tam odznak
   **Zaúčtováno**, **Neúčtováno** nebo **Chyba účtování**, pod ním
   samotný zápis a u chyby i její důvod — co která hláška znamená, je
   v [Když se doklad nezaúčtuje](../uctarna/kdyz-se-doklad-nezauctuje.md).
   Doklad zůstává uložený, jen není zaúčtovaný,
   takže se nic neztratilo.

   Rutinně to dělat nemusíš — když se účtování nepovede, Shipard tě na to
   upozorní v Dashboardu.

## Na co narazíš

**Z Konceptu se nedá skočit přímo na V pořádku.** Cesta je vždycky
Koncept → Potvrzeno → V pořádku. Není to obtěžování: mezi přidělením čísla
a zaúčtováním je krok, kdy má člověk poslední možnost si doklad přečíst.

**Vracet do Konceptu se dá jen od konce číselné řady.** Dokladů můžeš
vrátit i víc, ale vždy postupně od nejnovějšího: jak si číslo vezme zpátky
poslední doklad, stane se posledním ten před ním a jde vrátit také. Když
zkusíš vrátit doklad, po kterém už novější číslo existuje, Shipard to
odmítne a napíše, který doklad je poslední — jinak by v číslování zůstala
díra. Počítá se to zvlášť pro každou číselnou řadu a účetní rok. Když
potřebuješ opravit starší doklad, použij **Storno** a vystav nový.

**Vrácením do Konceptu se číslo uvolní — a příště to nemusí být to samé.**
Uvolněné číslo připadne tomu dokladu, který potvrdíš nejdřív. Vrátíš-li
do Konceptu dva doklady a potvrdíš je v jiném pořadí, vymění si čísla.
U dokladu, který už jsi někam nahlásil nebo poslal, proto číslo neměň.

**Chybu účtování spravíš bez rozebírání dokladu.** Když doplníš, co
podle hlášky chybělo (viz
[Když se doklad nezaúčtuje](../uctarna/kdyz-se-doklad-nezauctuje.md)),
vrať se na doklad a dej **Přeúčtovat**.
Doklad zůstane ve **V pořádku** a účetní zápis se vytvoří znovu; jakmile
projde, upozornění na Dashboardu zmizí samo.

**Dokončit to nemusíš hned.** Když formulář zavřeš v Konceptu nebo
v Potvrzeno, doklad nezmizí — najdeš ho v **Nákup → Faktury přijaté**
a tlačítkem **Otevřít** se vrátíš do stejného formuláře včetně tlačítek
pro přechody.

**Doklad ve V pořádku se needituje — a je to tak správně.** Když je v něm
chyba, nepřepisuje se: převedeš ho na **V opravě**, opravíš a vrátíš zpět.
Postup je v [Opravě dokladu](oprava-dokladu.md).

**Odchodem ze stavu V pořádku se doklad odúčtuje.** Zápis v Účetním deníku
i pohyby v saldokontu zmizí a po návratu na V pořádku se vytvoří znovu
z aktuálních dat. Nezůstávají tedy dvojí zápisy.

**Zaúčtování nezávisí na tom, jestli je faktura zaplacená.** Zaúčtuje se
podle stavu dokladu, platba se páruje samostatně přes bankovní transakce.
Nezaplacená faktura ve V pořádku je normální stav.

**Číslo dokladu není číslo od dodavatele.** Řada přiděluje tvoje interní
číslo; číslo, které faktuře dal dodavatel, je na dokladu vedené zvlášť
a podle něj se dohledávají duplicity.

## Souvisí

- [Kontrola vytěženého dokladu](../posta/kontrola-vytezeni.md) — co dělat
  před vznikem dokladu
- [Oprava dokladu](oprava-dokladu.md) — když je chyba v dokladu, který už
  je V pořádku
- [Slovníček](../slovnicek.md) — přehled stavů dokladu
