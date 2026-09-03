---
title: Živé výstupy DPH
summary: Jak si přečíst živé přiznání k DPH, kontrolní hlášení a souhrnné hlášení za zvolené období a co znamenají upozornění pod tabulkou.
keywords: [DPH, přiznání k DPH, kontrolní hlášení, souhrnné hlášení, DPHDP3, DPHKH1, DPHSHV, daňová povinnost, vlastní daň, nadměrný odpočet, období DPH, daňové tvrzení, daňová tvrzení, registrace DPH, kolik zaplatím DPH, sekce A4, sekce B2, kód plnění, souhlasí s deníkem, přesunout doklad do jiného měsíce, koncept tvrzení]
related: [uctarna/kdyz-se-doklad-nezauctuje.md, faktury-prijate/dokonceni-dokladu.md, co-dnes-nejde.md]
---

# Živé výstupy DPH

Ve skupině **Reporty** jsou tři živé výstupy DPH: **Přiznání k DPH — živě**,
**Kontrolní hlášení — živě** a **Souhrnné hlášení — živě**. Počítají se vždy
znovu z dokladů ve stavu **V pořádku** — nic se nikam neukládá, takže ukazují
aktuální stav i rozpracovaného období.

## Kdy to potřebuješ

- Chceš vědět, kolik DPH za období zaplatíš nebo dostaneš zpět — ještě
  před koncem období.
- Před podáním si chceš zkontrolovat, co do přiznání, kontrolního hlášení
  a souhrnného hlášení vstoupí.
- Kontroluješ, jestli čísla DPH souhlasí s účetním deníkem.

## Postup

1. V levém menu otevři skupinu **Reporty** a vyber jeden z výstupů —
   **Přiznání k DPH — živě**, **Kontrolní hlášení — živě** nebo
   **Souhrnné hlášení — živě**.
2. Nahoře zvol **Období** — nabízejí se **daňová tvrzení** tvé registrace
   pro daný výstup: u přiznání období přiznání (u čtvrtletního plátce
   čtvrtletí), u kontrolního a souhrnného hlášení jejich vlastní období
   (u právnické osoby měsíce). Roletkou **rok** se přepneš na starší
   tvrzení. Máš-li registrací víc, nejdřív vyber **Registraci DPH**.
3. Přečti si výsledek:
   - **Přiznání** ukazuje řádky formuláře se základem a daní; dole jsou
     dopočtené řádky včetně **Vlastní daň** (kolik zaplatíš) nebo
     **Nadměrný odpočet** (kolik dostaneš zpět) — stejná čísla shrnuje
     i řádek pod tabulkou.
   - **Kontrolní hlášení** je rozdělené do sekcí (A1–B3). Doklady nad
     10 000 Kč včetně daně jsou vypsané jednotlivě s ev. číslem a DIČ,
     menší doklady jsou sečtené v souhrnných sekcích A5 a B3.
   - **Souhrnné hlášení** sčítá dodání zboží a služeb do EU po odběratelích
     (podle DIČ) s počtem plnění a hodnotou.

## Odkud se berou období

Období výstupů jsou záznamy v seznamu **Daňová tvrzení** v sekci
**Účtárna** — pro každou registraci a každý výstup (přiznání, kontrolní
hlášení, souhrnné hlášení) zvlášť, s vlastním rozsahem dat. Shipard je
zakládá sám pro běžný měsíc; když uložíš doklad s datem, pro které tvrzení
ještě neexistuje, založí ho jako **Koncept** a upozorní na to na
Dashboardu — zkontroluj rozsah a potvrď ho (**V pořádku**).

Doklad se do tvrzení zařadí při uložení: do přiznání podle data
uskutečnění plnění, do kontrolního a souhrnného hlášení podle data
povinnosti přiznat daň (oříznutého do období přiznání). Kam doklad spadl,
vidíš na jeho hlavičce v sekci **DPH** (pole **Přiznání k DPH**,
**Kontrolní hlášení**, **Souhrnné hlášení**) a můžeš ho tam ručně přesunout
— třeba do jiného měsíce kontrolního hlášení. Při dalším uložení dokladu
se ale zařazení znovu spočítá podle dat.

## Na co narazíš

- **Tvrzení nejde smazat**, dokud na něj míří nějaký doklad nebo je
  uzamčené. Nejdřív založ správné tvrzení a doklady se do něj přeřadí
  (při změně rozsahu tvrzení se přeřadí samy).
- **Dvě tvrzení stejného typu se nesmí překrývat**; mezera mezi nimi je
  jen upozornění — doklady s datem v mezeře ale do žádného výstupu
  nespadnou.
- **„Součty daně souhlasí s účetním deníkem"** pod přiznáním znamená, že
  DPH z dokladů sedí na účetnictví. Když místo toho vidíš upozornění
  o rozdílu na účtu, bývá příčinou doklad s chybou účtování — viz
  [Když se doklad nezaúčtuje](kdyz-se-doklad-nezauctuje.md).
- **Chybějící DIČ nebo číslo dokladu dodavatele** hlásí kontrolní hlášení
  jako upozornění a řádek podbarví. Doplň údaj na dokladu (u přijaté
  faktury je číslo dodavatele v poli **Číslo dokladu partnera**).
- Do výstupů vstupují **jen doklady ve stavu V pořádku** — koncept ani
  doklad v opravě v číslech nejsou. Tvrzení ve stavu Koncept je ale
  čitelné normálně.
- Výstupy jsou zatím jen ke čtení: **podání, XML pro daňový portál ani
  uzamčení období z nich zatím neuděláš** — viz
  [Co Shipard dnes neumí](../co-dnes-nejde.md).

## Souvisí

- [Když se doklad nezaúčtuje](kdyz-se-doklad-nezauctuje.md)
- [Dokončení dokladu](../faktury-prijate/dokonceni-dokladu.md)
- [Co Shipard dnes neumí](../co-dnes-nejde.md)
