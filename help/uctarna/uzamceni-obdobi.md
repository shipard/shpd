---
title: Uzamčení období
summary: Jak po podání DPH uzamknout tvrzení nebo celý fiskální měsíc, co zámek zastaví, jak vypadá zamčený doklad a jak zámek zase sundat.
keywords: [uzamknout období, zamknout období, zámek období, uzamčené tvrzení, uzamčený měsíc, uzamčené období, zamčený doklad, doklad je uzamčený, nejde opravit doklad, nejde stornovat doklad, nejde smazat doklad, odemknout tvrzení, odemknout měsíc, uzávěrka měsíce, uzavřít měsíc, uzavřít období, po podání DPH, dodatečné přiznání odemknout, podané přiznání není uzamčené, nevypořádaná DPH, zůstatky DPH, účet 343, přeúčtovat zamčený doklad]
related: [uctarna/dph-podani.md, uctarna/dph-zive-vystupy.md, faktury-prijate/oprava-dokladu.md, co-dnes-nejde.md]
---

# Uzamčení období

Když odevzdáš přiznání nebo hlášení, obsah období se už nesmí měnit —
jinak by čísla na úřadu přestala odpovídat dokladům. Shipard na to má dva
zámky: **zámek tvrzení** (přiznání, kontrolní nebo souhrnné hlášení) a
**zámek fiskálního měsíce**. Zamčený doklad nejde uložit, opravit,
stornovat ani smazat a do zamčeného období nejde dopsat nový doklad.

## Kdy to potřebuješ

- Podal jsi přiznání k DPH nebo hlášení a chceš mít jistotu, že se
  doklady za to období už nikomu nezmění.
- Potřebuješ uzavřít měsíc jako celek — včetně dokladů bez DPH
  a konceptů.
- Naopak: chceš podat dodatečné přiznání a musíš období nejdřív
  **odemknout**.

## Postup

### Zamknout tvrzení po podání

1. V **Účtárna → Podání DPH** otevři podání, které jsi právě **Podal**.
2. V pravém panelu klikni na **Uzamknout tvrzení**. Tvrzení, ke kterému
   podání patří, se zamkne a tlačítko zmizí.

Totéž uděláš i bez podání: v **Účtárna → Daňová tvrzení** vyber tvrzení a
zvol **Uzamknout**. Hodí se pro období, která jsi podal ještě ve svém
dřívějším programu. V detailu tvrzení pak vidíš **Uzamčeno** s datem
a jménem; u zámku převzatého z dřívějšího programu je místo toho
„Uzamčeno (import)“.

Když na zámek zapomeneš, Shipard ti to za tři dny po podání připomene
upozorněním **Podané přiznání … není uzamčené** na Dashboardu.

### Zamknout fiskální měsíc

1. V **Nastavení → Účetnictví → Fiskální období** otevři rok a záložku
   **Měsíce**.
2. Otevři měsíc, zaškrtni **Uzamčeno** a ulož.

Zamknout jde jen běžný měsíc, ne Otevření a Uzavření roku — ta patří
k uzávěrce. V detailu roku má záložka **Měsíce** sloupec **Zámek**.

### Odemknout

- Tvrzení: v **Účtárna → Daňová tvrzení** vyber tvrzení a zvol
  **Odemknout**. Shipard se zeptá, jestli to myslíš vážně — odemknutí je
  krok před dodatečným nebo opravným podáním, ne běžná operace.
- Měsíc: ve formuláři měsíce zaškrtnutí **Uzamčeno** zruš.

Po dodatečném podání tvrzení zase zamkni.

## Co zámek zastaví

**Zámek tvrzení** platí pro doklady, které mají **DPH** a patří do
zamčeného tvrzení — podle přiznání, kontrolního nebo souhrnného hlášení,
stačí kterékoli z nich. Takový doklad nejde uložit, převést do stavu
**V opravě**, stornovat ani smazat, a nový doklad s DPH datovaný do
zamčeného období se neuloží. Doklad bez DPH (třeba převod mezi pokladnou
a bankou) zámek tvrzení nechává na pokoji — o ten se stará zámek měsíce.

**Zámek měsíce** platí pro **každý** doklad s účetním datem v měsíci, bez
ohledu na obsah a stav — koncepty i doklady bez DPH včetně.

Zamčený doklad poznáš podle žlutého pruhu **Záznam je uzamčený** nad
formulářem i v pravém panelu s důvodem, třeba „Kontrolní hlášení 01/2026
je uzamčené“ nebo „Fiskální měsíc 2026/01 je uzamčený“. Formulář je jen
k prohlížení a tlačítka pro změnu stavu chybí. Přílohy k zamčenému dokladu
přidávat můžeš.

Zamčené tvrzení samo nejde zrušit ani mu změnit období — jediné, co u něj
jde, je přejmenování a odemknutí. **Sestavit nad ním podání můžeš**, třeba
následné hlášení: podání jen čte.

## Na co narazíš

- **Přeúčtovat zamčený doklad nejde.** Účetní deník je odvozený z dokladu
  a bez změny dokladu se nemá měnit. Když opravíš účtovou osnovu a chceš
  přeúčtovat doklady v uzavřeném období, ozvi se správci — má na to nástroj,
  který zámek vědomě obejde a zapíše o tom záznam.
- **Při zamykání měsíce může Shipard varovat**, že za podané přiznání
  končící v tom měsíci zůstává na účtech DPH **nevypořádaný zůstatek**.
  Měsíc se zamkne i tak; varování říká, že přiznání ještě není zaúčtované,
  nebo že se DPH po podání změnila. Totéž hlídá denní upozornění
  **Nevypořádaná DPH za podané přiznání …** a sekce **Zůstatky DPH**
  v detailu tvrzení.
- **Změna období sousedního tvrzení může narazit na zámek.** Když by
  posun hranic přesunul doklady ze zamčeného tvrzení nebo do něj, uložení
  se nepovede a Shipard vypíše, kterých dokladů se to týká. Nejdřív
  odemkni.
- **Zámek roku Shipard zatím nehlídá.** Zaškrtnutí **Uzamčeno** u celého
  fiskálního roku doklady neblokuje — zamykej měsíce.
- Zamčené doklady zůstávají v seznamech a otevřít je k prohlížení můžeš
  kdykoli; jen je nezměníš.

## Souvisí

- [Podání DPH](dph-podani.md)
- [Živé výstupy DPH](dph-zive-vystupy.md)
- [Oprava dokladu](../faktury-prijate/oprava-dokladu.md)
- [Co Shipard dnes neumí](../co-dnes-nejde.md)
