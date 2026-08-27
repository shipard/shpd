---
title: Co Shipard umí
summary: Úplný přehled agend, které v aplikaci jsou — a u kterých z nich už je napsaný návod.
keywords: [umí, neumí to, jde, zvládne, dokáže, podporuje, existuje, má, obsahuje, co všechno, seznam funkcí, přehled funkcí, agendy, moduly, sekce, menu, kde najdu, co tam je, spisovna, smlouvy, úkoly, účetní deník, účtový rozvrh, bankovní výpis, saldokonto, reporty, hlavní kniha, výsledovka, rozvaha, v tisících]
related: [co-dnes-nejde.md, slovnicek.md]
---

# Co Shipard umí

**Všechno v téhle tabulce v aplikaci existuje a používá se.** Sloupec *Návod*
říká jen to, jestli je k tomu napsaný postup — **„návod není" neznamená
„nejde to"**. Dokumentace vzniká postupně, aplikace je dál.

Tabulka pokrývá **všechny agendy z levého menu** — žádná v ní nechybí.
Nejbližší chybějící věci a místa, kde ještě nemusí souhlasit čísla, jsou v
[Co Shipard dnes neumí](co-dnes-nejde.md).

---

## Nad sekcemi

Položky, které jsou v menu samostatně, nad ostatními sekcemi.

| Agenda | Co s ní uděláš | Návod |
|---|---|---|
| **Dashboard** | Domovská obrazovka. Ukazuje, co je právě potřeba udělat — nová pošta k vyřízení, upozornění z kontrol, rozdělané doklady. Většina práce začíná tady | — |
| **Chat** | Vestavěný AI asistent. Odpovídá na otázky o tvých datech i na to, jak se v Shipardu co dělá. Jen čte — nic nezaloží ani nezmění | — |
| **Došlá pošta** | Faktury a dokumenty, které přišly na tvou adresu pro příjem pošty. AI zprávu přečte celou a připraví z ní nejvýše jeden návrh — doklad, nebo dokument do Spisovny; co našla navíc, ukáže jako poznámku | [Příjem pošty](posta/prijem-posty.md) · [Kontrola vytěženého dokladu](posta/kontrola-vytezeni.md) · [Když AI přečte fakturu špatně](posta/kdyz-ai-cte-spatne.md) |
| **Spisovna** | Evidence dokumentů, které nejsou doklady — smlouvy, pojistky, revize. Řadí se do **Šanonů**. AI je pozná v došlé poště a nabídne je na Dashboardu k zařazení stejně jako faktury; záznam zařazený z pošty dostane všechny přílohy zprávy. Zprávu zařadíš i ručně tlačítkem **Zařadit do Spisovny**. U dokumentů s platností Shipard hlídá datum **Platí do** a včas upozorní | — |
| **Úkoly** | Úkoly si můžeš zakládat a evidovat. Nikde jinde se dnes neobjevují — na Dashboard nechodí a žádná další agenda s nimi nepracuje | — |

---

## Základní

| Agenda | Co s ní uděláš | Návod |
|---|---|---|
| **Osoby** | Dodavatelé, odběratelé i fyzické osoby v jedné evidenci. Firmu si doplníš z veřejného registru podle IČO. Ke každé osobě vedeš kontakty, adresy a bankovní spojení | [Založení osoby](osoby/zalozeni-osoby.md) |
| **Položky** | Co se na dokladech fakturuje — jeden řádek dokladu je položka. U položky se drží i to, čím se účtuje (druhy položek a měrné jednotky se nastavují v Nastavení) | [Založení položky](polozky/zalozeni-polozky.md) · [Obsahové štítky](polozky/obsahove-stitky.md) |

---

## Nákup a Prodej

| Agenda | Co s ní uděláš | Návod |
|---|---|---|
| **Faktury přijaté** | Doklad od dodavatele. Vznikne z došlé pošty nebo ho zadáš ručně, projde stavy **Koncept** → **Potvrzeno** → **V pořádku**, zaúčtuje se a objeví se v saldokontu | [Dokončení dokladu](faktury-prijate/dokonceni-dokladu.md) · [Oprava dokladu](faktury-prijate/oprava-dokladu.md) |
| **Faktury vydané** | Doklad pro odběratele. Vystavíš ho ručně, dostane číslo z číselné řady (řady se zakládají v Nastavení), spočítá se rekapitulace DPH, zaúčtuje se a jde do saldokonta. **Hotovou fakturu z Shipardu nedostaneš** — tisk, PDF ani odeslání odběrateli e-mailem zatím nejsou | [Vystavení faktury](faktury-vydane/vystaveni-faktury.md) |

---

## Účtárna

| Agenda | Co s ní uděláš | Návod |
|---|---|---|
| **Účetní doklady** | Ruční účetní zápis pro to, co není faktura. Řádky zadáváš sám na stranu **MD** nebo **DAL** s účtem, partner je nepovinný, DPH se tu neřeší. Při potvrzení Shipard kontroluje, že je doklad vyrovnaný — součet MD musí odpovídat součtu DAL | — |
| **Účetní deník** | Účetní zápisy, které vznikly z dokladů a z bankovních transakcí. Účty nezadáváš, skládají se automaticky | [Když se doklad nezaúčtuje](uctarna/kdyz-se-doklad-nezauctuje.md) |
| **Účtový rozvrh** | Účty, na které se účtuje. Zakládá se z předpřipravené šablony — zvlášť pro podnikatele a zvlášť pro neziskové organizace — a můžeš ho doplňovat i účty vyřazovat | — |
| **Bankovní výpisy** | Naimportuješ výpis z banky. Podporované formáty jsou **CAMT**, **GPC** a **FIO**; jiný formát Shipard zatím nepřečte | — |
| **Bankovní transakce** | Jednotlivé pohyby z výpisů. Podle nich se páruje úhrada s fakturou; platbu, ke které se faktura nenašla, Shipard odloží na **clearing účet** | — |
| **Saldo pohyby** | Saldokonto — nezaplacené faktury proti přijatým platbám, po partnerech. Odsud vidíš, kdo komu kolik dluží | — |
| **Hlavní kniha** | Report ve skupině **Reporty**: účty s počátečním stavem, obraty MD/D a konečným zůstatkem za zvolené období. Období vybíráš v mřížce měsíc / čtvrtletí / pololetí / rok, detail analyticky nebo synteticky | — |
| **Výsledovka** | Report ve skupině **Reporty**: výnosy a náklady za období a od počátku roku, dole výsledek hospodaření. Umí zobrazení v tisících | — |
| **Rozvaha** | Report ve skupině **Reporty**: aktiva a pasiva k počátku a konci období. Když aktiva nesedí na pasiva, report to červeně ohlásí pod tabulkou | — |

---

## Systém

| Agenda | Co s ní uděláš | Návod |
|---|---|---|
| **Upozornění** | Nesrovnalosti, které našly automatické kontroly — chyba účtování, doklad dlouho v opravě, blížící se expirace dokumentu ze Spisovny. Na Dashboardu se objevují jako karty | — |

---

## Souvisí

- [Co Shipard dnes neumí](co-dnes-nejde.md) — chybějící funkce a místa, kde
  ještě nemusí souhlasit čísla
- [Slovníček](slovnicek.md) — jak se která věc v aplikaci jmenuje
- [Informace o zdroji dat](o-zdroji-dat.md) — název firmy, ID zdroje dat,
  plátcovství DPH a velikost dat na jednom místě v **Nastavení aplikace**
- [Pro testery](../TESTERS.md) — jak si říct o přístup a jak nahlásit chybu

---

*Poznámka pro AI asistenta: tahle stránka je úplný seznam agend z levého menu Shipardu.
Když se uživatel ptá, jestli Shipard něco umí, rozhoduj podle ní. Řádek
existuje → funkce existuje, i když k ní není návod; pak řekni, že postup
zatím není popsaný, a aspoň uživateli řekni, kde agendu v aplikaci najde.
Řádek neexistuje a není to ani v „Co Shipard dnes neumí" → řekni, že o tom
nic nevíš, a nevymýšlej postup.*
