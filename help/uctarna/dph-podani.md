---
title: Podání DPH
summary: Jak z živého výpočtu udělat podání — trvalý záznam toho, co jsi za období odevzdal, včetně opravného, dodatečného a následného podání.
keywords: [podání DPH, podat přiznání, odevzdat přiznání, řádné podání, opravné podání, dodatečné přiznání, následné hlášení, dodatečné podání, změna daňové povinnosti, řádek 66, zaokrouhlení na koruny, sestavit podání, přepočítat podání, co jsem podal, historie podání, datum podání, datum zjištění důvodů, rozdíly proti předchozímu podání, podané hodnoty, XML pro daňový portál]
related: [uctarna/dph-zive-vystupy.md, co-dnes-nejde.md]
---

# Podání DPH

Živé výstupy DPH se počítají vždy znovu, takže se mění pokaždé, když
opravíš doklad. **Podání** je opak: uloží obsah období tak, jak vypadal
v okamžiku sestavení, a už se nemění. Díky tomu máš i po roce jasno, co
jsi finančnímu úřadu skutečně odevzdal — a když se doklady později změní,
Shipard ti ukáže přesně které.

Seznam podání je v sekci **Účtárna** → **Podání DPH**.

## Kdy to potřebuješ

- Odevzdal jsi přiznání nebo hlášení a chceš mít v Shipardu záznam, co
  v něm bylo.
- Po odevzdání se něco změnilo a potřebuješ vědět, jaký je rozdíl proti
  tomu, co už je podané.
- Podáváš opravné, dodatečné nebo následné podání a potřebuješ do něj
  správná čísla.

## Postup

1. V **Účtárna → Daňová tvrzení** vyber tvrzení, za které podáváš
   (období přiznání, kontrolního nebo souhrnného hlášení).
2. V pravém panelu klikni na **Sestavit podání**.
3. Zkontroluj **Druh podání** — nabízejí se jen druhy, které pro daný
   výstup existují (viz níže). U prvního podání za období je to **Řádné**.
4. Ulož. Shipard rovnou sestaví obsah podání z dokladů ve stavu
   **V pořádku** a spočítá **podané hodnoty** (zaokrouhlené).
5. Zkontroluj podání v **Účtárna → Podání DPH**: záložka **Výstupní
   řádky** ukazuje vedle sebe podané a přesné hodnoty.
6. Až výstup skutečně odevzdáš, otevři podání a zvol **Podat**. Doplní se
   **datum podání** (dnešní, můžeš ho před podáním přepsat).

Podání ve stavu **Sestaveno** je koncept: pořád ho můžeš přepsat, změnit
druh nebo zrušit. Když se mezitím opravily doklady, akce **Přepočítat**
v detailu podání sestaví obsah znovu z aktuálních dat.

## Druhy podání

Které druhy jde použít, plyne ze zákona a liší se podle výstupu:

| Výstup | Druhy podání |
|---|---|
| Přiznání k DPH | Řádné · Opravné · **Dodatečné** |
| Kontrolní hlášení | Řádné · Opravné · **Následné** |
| Souhrnné hlášení | Řádné · **Následné** |

- **Řádné** je první podání za období.
- **Opravné** podáváš ještě ve lhůtě — celý obsah nahradí to předchozí.
- **Dodatečné** (jen přiznání) podáváš po lhůtě a vykazuje se v něm
  **jen rozdíl** proti tomu, co už je podané. Vlastní daň ani nadměrný
  odpočet se v něm nevyplňují — místo nich je na řádku 66 **změna daňové
  povinnosti**. Shipard rozdíl spočítá sám.
- **Následné** (hlášení) podáváš po lhůtě a obsahuje celý obsah znovu.
  Vyžaduje **datum zjištění důvodů** — den, kdy jsi zjistil, že je potřeba
  podat znovu.

Řádné podání je za období jen jedno: dokud za tvrzení nic podaného není,
nabízí se řádné, potom už jen navazující druhy. Rozpracované podání může
být za jedno tvrzení nejvýš jedno — nejdřív ho podej, nebo zruš.

## Podané a přesné hodnoty

Přiznání se odevzdává v celých korunách, a to po řádcích: **každý řádek
se zaokrouhlí zvlášť** a dopočtené řádky (odpočet celkem, daň na výstupu,
vlastní daň) se pak počítají už ze zaokrouhlených čísel. Proto se podaná
vlastní daň může od živého výpočtu lišit o jednotky korun — je to správně,
takhle to čeká i finanční úřad.

Podání si drží obě čísla: **podané** (co šlo na úřad) i **přesné** (na
haléře). Kontrolní hlášení se podává na haléře, souhrnné hlášení
zaokrouhluje hodnoty nahoru na celé koruny.

## Rozdíly proti předchozímu podání

U opravného, dodatečného i následného podání má detail záložku
**Rozdíly**: vypíše doklady, které se proti předchozímu podání
**přidaly, odebraly nebo změnily** — s rozdílem základu a daně. Když se
nezměnilo nic, řekne to. Tohle je nejrychlejší způsob, jak zjistit, proč
dodatečné přiznání vychází právě takhle.

## Na co si dát pozor

- **Podané podání už nezměníš ani nesmažeš.** Doplnit k němu můžeš jen
  poznámku. Oprava se nedělá editací, ale novým podáním jiného druhu —
  přesně jako u úřadu.
- **Tvrzení s podáním nejde zrušit** a tvrzení s už **podaným** podáním
  nejde ani **změnit rozsah období**: podaný obsah odpovídá období, za
  které se sestavil. Když je rozsah špatně, řeší se to novým podáním, ne
  přepsáním období.
- **Prázdné podání je legitimní.** Kontrolní hlášení se podává i za měsíc
  bez dokladů; Shipard takové podání označí jako prázdné.
- **Podání sestavíš jen z dokladů ve stavu V pořádku.** Koncept ani doklad
  v opravě v něm nebudou.
- **Když má doklad kód DPH, který Shipard neumí zařadit do výstupu**,
  sestavení se zastaví s chybou. Je to schválně: z podání nesmí nic tiše
  vypadnout. Oprav kód na dokladu a zkus to znovu.
- **Období se podáním nezamkne.** Nic tě dnes nezastaví, když do už
  podaného období dopíšeš doklad — uvidíš to pak v Rozdílech dalšího
  podání. Zámek přijde později, viz
  [Co Shipard dnes neumí](../co-dnes-nejde.md).
- **XML pro daňový portál Shipard zatím nevytvoří.** Podání je dnes
  evidence: víš, co a kdy jsi podal, ale soubor pro portál sestavuje tvůj
  dnešní program nebo účetní.

## Souvisí

- [Živé výstupy DPH](dph-zive-vystupy.md)
- [Co Shipard dnes neumí](../co-dnes-nejde.md)
