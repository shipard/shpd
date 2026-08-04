---
title: Slovníček
summary: Co která věc v Shipardu znamená a jak se jmenuje v rozhraní.
keywords: [slovníček, pojmy, názvy, co to znamená, jak se to jmenuje, terminologie]
related: [co-dnes-nejde.md]
---

# Slovníček

Shipard občas používá názvy, které nejsou samozřejmé — a ty občas používáš
jiné než on. Tady je oboje pohromadě: **první sloupec je to, co asi řekneš,
druhý to, jak se to jmenuje v aplikaci.**

Slovníček roste s dokumentací. Zatím pokrývá orientaci v aplikaci, došlou
poštu a přijaté faktury.

---

## Orientace v aplikaci

| Když řekneš | V Shipardu | Co to je |
|---|---|---|
| úvodní stránka, plocha, přehled | **Dashboard** | Domovská obrazovka po přihlášení. Ukazuje, co je právě potřeba udělat — nová pošta, upozornění, rozdělané doklady |
| seznam, tabulka, výpis | **Prohlížeč** (v rozhraní občas i *viewer*) | Seznamová obrazovka, třeba přehled faktur |
| moje firma, můj účet, prostor | **Datový zdroj** (DS) | Jedna firma nebo organizace se svými daty. Tvoje data jsou oddělená od ostatních |
| menu vlevo | **Sekce** *Základní*, *Nákup*, *Prodej*, *Účtárna*, *Systém* | Nad nimi stojí samostatné položky *Dashboard*, *Došlá pošta*, *Úkoly* a *Chat* |
| dokumenty, smlouvy, přílohy k ničemu | **Spisovna** | Evidence dokumentů, které nejsou doklady — smlouvy, výpisy, úřední pošta |
| asistent, AI, chatbot | **Chat** | Vestavěný AI asistent. Umí se dívat do tvých dat a odpovídat na otázky. Nic za tebe nezaloží ani nezmění |
| hláška, varování, červená věc | **Upozornění** | Kontrola, která našla nesrovnalost. Objeví se jako karta na Dashboardu |

---

## Došlá pošta a vytěžení

| Když řekneš | V Shipardu | Co to je |
|---|---|---|
| e-maily, co přišlo | **Došlá pošta** | Zprávy, které do systému přišly na tvou adresu pro příjem pošty |
| AI to přečetla, načtení faktury | **Analýza** / **vytěžení** | Co AI udělá s přiloženou fakturou: přečte ji a nabídne hotový doklad |
| co z toho vypadlo, rozečtená faktura | **Vytěžený doklad** | Výsledek analýzy — ještě není doklad, je to návrh ke kontrole |
| jak si je tím AI jistá, spolehlivost | **Jistota** (v procentech) | Odhad modelu, jak dobře fakturu přečetl. Nízká jistota = kontroluj pečlivě. Vysoká jistota **není** záruka správnosti |
| ještě se to nezpracovalo | stav **Nová** | Zpráva přišla, analýza ještě nezačala |
| právě se to čte | stav **V analýze** | Pracuje na tom AI. Tenhle stav nastavuje systém, ručně do něj nevstoupíš |
| přečteno, čeká na mě | stav **Analyzovaná** | Analýza hotová, čeká se na tvou kontrolu a potvrzení |
| hotovo, už jsem to udělal | stav **Zpracovaná** | Ze zprávy už vznikl doklad. Zpráva se dál needituje |
| uklidit z cesty | stav **Archiv** | Zpráva, se kterou už nic nebude — reklama, nevyžádaná pošta |
| elektronická faktura, XML faktura | **ISDOC** | Strojově čitelný formát faktury. Když ho dodavatel přiloží, Shipard ho použije přímo a AI analýzu vůbec nepotřebuje — je to přesnější |
| účtenka, paragon | **Zjednodušený daňový doklad** | Doklad z prodejny bez tvých údajů. Shipard je zpracovává také |

---

## Doklady a faktury

| Když řekneš | V Shipardu | Co to je |
|---|---|---|
| faktura, paragon, cokoli k zaúčtování | **Doklad** | Souhrnný název pro fakturu, pokladní doklad a podobné |
| faktura, co mi přišla | **Faktura přijatá** (sekce *Nákup*) | Doklad od dodavatele |
| faktura, co jsem poslal | **Faktura vydaná** (sekce *Prodej*) | Doklad pro odběratele |
| rozdělaný, ještě to nechci | stav **Koncept** | Doklad se dá libovolně měnit, nemá vliv na nic dalšího |
| mám číslo, ale ještě to ladím | stav **Potvrzeno** | Doklad má přidělené číslo z číselné řady, ale pořád se dá upravovat |
| hotovo, platí to | stav **V pořádku** | Doklad je uzavřený a nedá se přímo měnit. Tenhle stav se účtuje |
| chci to opravit | stav **V opravě** | Do tohohle stavu doklad převedeš, když potřebuješ změnit hotový doklad |
| zrušit fakturu, škrtnout | stav **Storno** | Doklad zůstává v evidenci, ale neplatí. Nemazat — storno je správná cesta |
| číslování faktur | **Číselná řada** | Předpis, podle kterého Shipard přiděluje čísla dokladů. Číslo se přiděluje při přechodu z Konceptu |
| řádek faktury, co se fakturuje | **Položka** | Jeden řádek dokladu — množství, jednotková cena, sazba DPH |
| součet daní dole na faktuře | **Rekapitulace DPH** | Rozpis základu a daně po jednotlivých sazbách |
| daň platí odběratel, přenesená daň | **Reverse charge** / **samovyměření** | Režim, kdy daň neodvádí dodavatel, ale odběratel. Typicky u zboží z EU nebo u stavebních prací |
| dodavatel, odběratel, partner | **Osoba** (sekce *Základní*) | Jedna evidence pro firmy i fyzické osoby |
| natáhnout firmu podle IČO | **Přidat firmu z registru** | Doplnění údajů partnera z veřejného registru — stačí IČO |

---

## Účtárna

| Když řekneš | V Shipardu | Co to je |
|---|---|---|
| kam se to zaúčtovalo | **Účetní deník** | Účetní záznamy vzniklé z dokladů. Účty nezadáváš, skládají se automaticky |
| kdo mi kolik dluží | **Saldokonto** | Nezaplacené faktury proti přijatým platbám, po partnerech |
| výpis z banky, platby | **Bankovní transakce** | Pohyby na bankovních účtech, ze kterých se párují úhrady |
| přiřadit platbu k faktuře | **Párování** | Spojení bankovní platby s fakturou, kterou uhrazuje |
| platba, která nikam nesedí | **Clearing účet** | Přechodné místo pro platbu, ke které se zatím nenašla faktura |

---

## Souvisí

- [Co Shipard dnes neumí](co-dnes-nejde.md)
- [Pro testery](../TESTERS.md) — jak si říct o přístup a jak hlásit chyby
