---
title: Co Shipard dnes neumí
summary: Poctivý seznam chybějících funkcí a míst, kde ještě nemusí souhlasit čísla.
keywords: [neumí, nejde, chybí, omezení, alfa, přiznání k DPH, kontrolní hlášení, záloha, nefunguje]
related: [slovnicek.md]
---

# Co Shipard dnes neumí

Shipard je ve stavu **alfa**. Tahle stránka je záměrně na jednom místě, ať
nemusíš hledat funkci, která ještě neexistuje — a ať ti ji nikdo neslibuje.

Seznam se mění. Když něco nenajdeš ani tady, ani v dokumentaci, napiš na
**podpora@shipard.cz**.

---

## Výstupy pro daně

**Přiznání k DPH ani kontrolní hlášení se z Shipardu vygenerovat nedají.**
Evidence pro ně existuje — období DPH, rozpad po sazbách i po kódech DPH —
ale výstup pro daňový portál se zatím nevytvoří. Je to nejbližší velká věc
na plánu.

Praktický důsledek: **Shipard zatím nemůže být jediné místo, kde tvoje
účetnictví existuje.** Přiznání za tebe musí sestavit tvůj dnešní program
nebo účetní.

**Období DPH se nedá uzamknout.** Nic tě dnes nezastaví, když do už
odevzdaného období dopíšeš doklad.

---

## Vydané faktury: nic pro odběratele

**Vydanou fakturu nevytiskneš, neuložíš do PDF ani neodešleš e-mailem.**
Vystavit a zaúčtovat ji jde celou — viz
[Vystavení faktury](faktury-vydane/vystaveni-faktury.md) — ale doklad, který
drží v ruce odběratel, musíš zatím vyrobit jinde. U vydaných faktur je tak
Shipard dneska evidence, ne fakturační nástroj.

---

## Kde ještě nemusí souhlasit čísla

Tohle je pro nás priorita číslo jedna a pracuje se na tom. Do té doby
u těchto případů **porovnej celkovou částku dokladu s originálem faktury**:

- **Faktury s jednotkovými cenami včetně DPH** (typicky drobný prodej,
  občerstvení). Daň se může spočítat dvakrát a celková částka pak vyjde
  vyšší než na faktuře.
- **Zaokrouhlení celkové částky** — dodělané, ale ještě neověřené na širší
  sadě faktur.
- **Reverse charge (samovyměření) v rekapitulaci DPH** — rozpis daně
  u těchto dokladů se opravuje.

Když najdeš rozdíl, chceme ho vědět i kdyby byl o korunu. Jak ho nahlásit
je v [TESTERS.md](../TESTERS.md).

---

## AI vytěžení faktur

- **Vytěžení není zaručeně správné.** Je to návrh ke kontrole, ne hotový
  doklad. Vysoká **Jistota** znamená, že model neměl pochybnost — ne že má
  pravdu.
- **Z jednoho e-mailu vznikne nejvýše jeden návrh dokumentu.** Když zpráva
  nese víc dokumentů (dvě faktury, faktura + smlouva), AI vytěží jen ten
  hlavní; ostatní nálezy uvidíš na kartě jako poznámku a založíš je ručně.
  Je to vědomé omezení, ne chyba čtení — víc faktur pošli každou
  samostatným e-mailem.
- **Nedá se to nastavit „na dodavatele".** Zvyklosti se sice zohledňují
  z tvé dosavadní historie, ale nemáš žádnou obrazovku, kde bys pravidla
  pro konkrétního dodavatele zadal ručně.
- **Analýzu nespustíš, kdy se ti zachce.** První běží automaticky po
  doručení zprávy; ručně jde jen **Znovu analyzovat** u zprávy, která už
  je analyzovaná nebo u které analýza selhala. Zprávu v Archivu nebo
  v koši znovu analyzovat nelze.

Když dodavatel přiloží fakturu ve formátu **ISDOC**, AI se nepoužije vůbec
a data se převezmou přímo — je to přesnější. Vyplatí se o ISDOC dodavatele
požádat.

---

## Vnitřní AI asistent (Chat)

- **Asistent umí jen čtení.** Nezaloží ti doklad, nezmění záznam, nic
  neodešle. Poradí, najde, spočítá — provést to musíš ty.
- **Neví o všem.** Když se ptáš na postup a asistent odpoví, že to neví,
  je to správná odpověď — lepší než vymyšlený návod.

---

## Správa dat a provoz

- **Zálohu a obnovu svého datového zdroje si sám neuděláš.** Data
  zálohujeme my; obnova ze zálohy se dnes řeší přes podporu.
- **Datový zdroj se nedá smazat z rozhraní.** Napiš na podporu.
- **Převod dat ze starého Shipardu neděláš sám.** Import existuje, ale
  spouštíme ho my a s tebou pak porovnáme kontrolní součty.
- **Adresu pro příjem pošty v aplikaci neuvidíš.** Přijde ti e-mailem
  s přístupy; když se ztratí, pošleme ji znovu. Zobrazení v aplikaci se
  chystá.
- **Uživatele a přístupy zakládáme ručně.** Viz [TESTERS.md](../TESTERS.md).

---

## Rozhraní

- **Mobilní aplikace neexistuje a nechystá se.** Rozhraní je responzivní,
  na telefonu i tabletu funguje v prohlížeči.
- **Anglické rozhraní není úplné.** Čeština je hlavní jazyk; v angličtině
  můžeš narazit na nepřeložené popisky.
- **Narazíš na nedodělané obrazovky.** Nic tím nerozbiješ tak, abychom to
  nespravili.

---

## Souvisí

- [Slovníček](slovnicek.md)
- [Pro testery](../TESTERS.md) — jak nahlásit chybu
- [Kam projekt směřuje](../docs/roadmap.md) — v jakém pořadí se chybějící
  věci dodělávají

---

*Poznámka pro AI asistenta: když se dotaz uživatele týká něčeho z téhle
stránky, řekni, že to Shipard zatím neumí, a nabídni náhradní postup, pokud
existuje. Nevymýšlej návod k funkci, která není.*
