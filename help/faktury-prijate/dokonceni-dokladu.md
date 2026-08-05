---
title: Dokončení dokladu
summary: Co se děje po Použít — od Konceptu přes Potvrzeno k V pořádku a co se tím spustí.
keywords: [dokončení, koncept, potvrzeno, v pořádku, číslo faktury, číselná řada, zaúčtování, saldokonto, uzamčení dokladu]
related: [posta/kontrola-vytezeni.md, slovnicek.md]
---

# Dokončení dokladu

Doklad z došlé pošty vzniká jako **Koncept** — rozpracovaný záznam, který
zatím nikam nepočítá. Aby se zaúčtoval a objevil v saldokontu, musí projít
dvěma přechody. Tahle stránka je o tom, co se při každém z nich stane.

## Kdy to potřebuješ

Potvrdil jsi vytěžený návrh, doklad se otevřel v editačním formuláři a ty
řešíš, co s ním dál. Nebo hledáš doklad, který jsi rozdělal a nedokončil.

## Postup

1. **Dokonči Koncept.** Tady je editovatelné všechno: řádky, částky, sazby,
   datumy, dodavatel. Zkontroluj především **Datum účetního případu**,
   **DUZP** a **Splatnost** — od nich se odvíjí období a saldokonto.
   Doklad z pošty už má nastavenou **číselnou řadu**, takže na ni myslet
   nemusíš.

2. **Přepni na Potvrzeno.** Tím doklad dostane **číslo** z číselné řady.
   Pořád ho můžeš upravovat — Potvrzeno není uzamčené.

   Tenhle mezikrok má smysl u faktur, které chceš mít očíslované, ale
   nechceš je zaúčtovat: čekáš na chybějící informaci, ověřuješ dodávku,
   nebo se s dodavatelem o něčem dohaduješ.

3. **Přepni na V pořádku.** Teprve tady se doklad stává hotovým:

   - **uzamkne se** — v tomto stavu se needituje,
   - **zaúčtuje se** — vznikne zápis v **Účetním deníku**,
   - **objeví se v saldokontu** jako nezaplacená položka, kterou pak
     spáruješ s platbou z banky.

4. **Zkontroluj, že účtování prošlo.** V detailu dokladu je záložka
   **Zaúčtování** s odznakem **Zaúčtováno**, **Neúčtováno** nebo **Chyba
   účtování** a pod ním samotný účetní zápis. U chyby se vypisuje i důvod
   — obvykle chybějící nastavení u položky nebo účtu. Doklad zůstává
   uložený, jen není zaúčtovaný, takže se nic neztratilo.

## Na co narazíš

**Z Konceptu se nedá skočit přímo na V pořádku.** Cesta je vždycky
Koncept → Potvrzeno → V pořádku. Není to obtěžování: mezi přidělením čísla
a zaúčtováním je krok, kdy má člověk poslední možnost si doklad přečíst.

**Vrácení z Potvrzeno do Konceptu jde jen u posledního dokladu v řadě.**
Když mezitím vzniklo číslo novější, Shipard vrácení odmítne a napíše, který
doklad je poslední — jinak by v číslování zůstala díra. U starších dokladů
použij **Storno** a vystav nový.

**Vrácením do Konceptu se číslo uvolní** a přidělí se příště znovu. Neber to
jako běžnou operaci; u dokladu, který už jsi někam nahlásil nebo poslal,
číslo neměň.

**Doklad ve V pořádku se needituje — a je to tak správně.** Když je v něm
chyba, nepřepisuje se: převedeš ho na **V opravě**, opravíš a vrátíš zpět.

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
- [Slovníček](../slovnicek.md) — přehled stavů dokladu
