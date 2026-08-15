# Apply z AI analýzy: doplnění pohybu (operation) na řádcích dokladu

**Stav:** hotovo (dev) — implementace, testy, docs i dodatek
(ac50f44, vč. odstranění preview větve) hotové a ověřené 15. 8. 2026
na dev DS: `2260007` prošel na 40 bez ruční korekce, apply bez
rutinní hlášky, `DocumentApplierTest` zelený (59 testů). Zbývá:
ruční doplnění konceptů `!0000000005`–`!0000000009`; alfa přechází
pod message-centric nasazení

**Cíl:** Doklady vzniklé z AI analýzy (tlačítko Použít na Kontrole)
mají na item řádcích vyplněný sloupec Pohyb (`operation`), odvozený
primárně z typu položky a jinak z výchozího pohybu per docType —
takže přechod do stavu V pořádku (docState 40) nevyžaduje ruční
doplňování.

**Referenční případ:** koncept `!0000000009` na dev DS — vznikl přes
Použít, vše v pořádku kromě `operation = NULL` na řádku; kontroly při
přechodu na 40 správně hlásí „Pohyb je povinný". Stejně jsou na tom
všechny AI koncepty (`!0000000005`–`!0000000007`); doklady 2260004
a 2260005 prošly jen díky ručnímu doplnění.

**Návaznost:**

- `DocumentApplier` dnes dělá vědomý verbatim passthrough
  (`'operation' => $row['operation'] ?? null`, komentář „caller's
  job") — a volající to nedodává. AI pohyb správně nevrací (interní
  účetní koncept, na předloze není; prompt pro něj žádné pravidlo
  nemá a mít nemá).
- Ruční UI default existuje jinde: `DocRowsForm` předvyplní prázdnou
  operaci první nabídkou dle `order`; AI apply ale zapisuje řádky
  mimo formulář.
- Kontroly: `DocRowOperationRules::validateRow` — item řádek bez
  operace nesmí na 40 („Pohyb je povinný"); `acc.entry` navíc
  vyžaduje vybranou položku (AI řádky ji mají — `resolvedRowItems`
  / side-create).
- Předloha chování: starý Shipard — při automatickém vytváření se
  pohyb přiřazuje podle typu položky (Účetní položka → Účetní
  položka, Služba → Nákup služeb…), výchozí pohyb `invni` = Účetní
  položka, `invno` = Prodej služeb.
- Dostupné stavební kameny: `economy_items.item_type` (denormalizace
  z `item_kind`; 0 Služba, 1 Zásoba, 2 Účetní položka, 3 Ostatní —
  `economy.items.itemTypes`); side-created položky dostávají
  `item_kind` vždy (`prepareItemCreatePayload`), takže `item_type`
  je dohledatelný jednotně přes ID položky.

---

## Klíčová rozhodnutí (potvrzena 15. 8. 2026)

1. **D1 — Applier doplní pohyb dvoustupňově** u item řádků
   (`row_kind 1`) s `operation = null`: primárně mapou z `item_type`
   resolvované/založené položky, jinak výchozím pohybem per docType.
   Kontační řádky (accSide / rowSide operace) a textové řádky
   nedotčené. Pokud AI/canonical operation nese (dnes nenastává),
   passthrough má přednost — doplňuje se jen `null`.
2. **D2 — Konfigurační mechanismus per docType**: nový cfgItem
   `docs.core.applyRowOperations` (samostatný soubor, viz níže)
   s mapou `byItemType` + `default` pro každý docType. Výchozí
   hodnoty dle starého Shipardu: `invni` default `acc.entry`,
   `invno` default `sale.services`.
3. **D3 — Transparentnost v review**: každé doplnění přidá info
   poznámku do `_resolve.issues` (vzor `vat_mode_derived`), kind
   `row_operation_defaulted`, s uvedením zvoleného pohybu a zdroje
   (typ položky / default docType).
4. **D4 — Testy** (viz níže) + ověření na dev DS novým konceptem.

## Mimo scope

- UI chování ze starého Shipardu: pohyb filtruje nabídku položek;
  nový řádek dědí pohyb z posledního řádku dokladu. Kandidát na
  samostatný UI task, pokud bude chtít uživatel.
- Prompt/schéma AI — beze změn (`rows[].operation` zůstává
  `string|null`, AI ho nevyplňuje).
- Backfill existujících konceptů (`!0000000005`–`!0000000007`,
  `!0000000009`) — doplní se ručně nebo novým apply; žádná datová
  migrace.
- Jiné docTypes než `invni`/`invno` — mapa je rozšiřitelná, ale
  AI apply dnes jiné typy negeneruje; docType bez záznamu v mapě
  → dnešní chování (null).

---

## Implementační kroky

### 1. Konfigurace `docs.core.applyRowOperations`

Nový soubor `modules/docs/core/config/applyRowOperations.jsonc`:

```jsonc
{
    // docs.core.applyRowOperations
    //
    // Doplnění pohybu (operation) na item řádcích při apply
    // z AI analýzy (DocumentApplier). Klíč = docType.
    //   byItemType: economy.items.itemTypes → kód operace
    //               (docs.core.rowOperations)
    //   default:    fallback, když položka chybí nebo typ není v mapě
    "invni": {
        "byItemType": {
            "0": "purchase.services",   // Služba
            "1": "purchase.goods",      // Zásoba
            "2": "acc.entry",           // Účetní položka
            "3": "purchase.other"       // Ostatní
        },
        "default": "acc.entry"
    },
    "invno": {
        "byItemType": {
            "0": "sale.services",
            "1": "sale.goods",
            "2": "acc.entry"
            // 3 (Ostatní): invno nemá sale.other → spadne na default
        },
        "default": "sale.services"
    }
}
```

Registrace v `modules/docs/core/module.jsonc` (sekce cfgItems, vedle
`docs.core.rowOperations`):
`{ "id": "docs.core.applyRowOperations", "file": "config/applyRowOperations.jsonc" }`.

**Pozor: po přidání cfgItem přebuildit kompilovanou cfg** — bez toho
`cfgItem()` novou položku nevidí (samostatný krok vedle ds-upgrade).

Sanity: kódy operací v mapě musí existovat v
`docs.core.rowOperations` a být povolené pro daný docType — hlídá
test (níže), applier při neznámém kódu operaci nedoplní (chová se
jako bez záznamu) a přidá warning issue.

### 2. `DocumentApplier` — doplnění

`modules/core/exchange/src/Document/DocumentApplier.php`:

- Po vyřešení položek (matched `resolvedRowItems` + side-creates,
  tj. v místě, kde jsou známá finální ID položek řádků) batch-fetch
  `item_type` pro všechna ID (`SELECT id, item_type FROM
  economy_items WHERE id IN (…)` přes příslušnou gateway).
- V mapování řádku (u dnešního `'operation' => $row['operation'] ??
  null`): je-li výsledek `null`, `row_kind` item a řádek není
  kontační → `resolveRowOperation(docType, ?itemType)`:
  1. `byItemType[itemType]`, existuje-li položka i mapování,
  2. jinak `default` docTypu,
  3. bez záznamu pro docType / neznámý kód operace → `null`
     (dnešní chování) + warning issue při neznámém kódu.
- Každé doplnění → `$issues[]` kind `row_operation_defaulted`
  (severity info): „Pohyb '<name:cs>' doplněn podle typu položky"
  / „…podle výchozího pohybu dokladu".

### 3. Dokumentace

- `docs/exchange-format.md` (applier — `modules/core/exchange/docs/`
  neexistuje) — sekce o doplňování pohybu v §10, odkaz na cfgItem.
- `modules/core/mail/docs/ai-analysis.md` — poznámka
  o `row_operation_defaulted` v review flow.

## Testy

`DocumentApplierTest`:

1. Řádek s matched položkou typu 0 (Služba), invni →
   `purchase.services` + issue `row_operation_defaulted`.
2. Řádek s položkou typu 2 → `acc.entry` (a splňuje
   `item_required_for_acc_entry` — položka je vyplněná).
3. Řádek se side-created položkou (kind → item_type) → dle mapy.
4. Řádek bez položky → `default` docTypu (invni `acc.entry`).
5. invno: typ 0 → `sale.services`; typ 3 (bez mapování) → default
   `sale.services`.
6. Canonical s vyplněnou `operation` → passthrough beze změny,
   žádné issue.
7. Kontační řádek (accSide) → nedotčen (operation dle dnešní
   logiky).
8. docType bez záznamu v mapě → `null` (dnešní chování).
9. Neznámý kód operace v mapě → `null` + warning issue.
10. Konzistence konfigurace: všechny kódy
    v `applyRowOperations.jsonc` existují
    v `docs.core.rowOperations` a jsou povolené pro daný docType
    (test čte oba cfg soubory).

Regrese: `DocRowOperationRules` testy, `RowOperationsSelfBalancingParityTest`,
`DocumentApplierTest` stávající případy — zelené, úzké filtry.

## Pořadí commitů

1. `feat(exchange): apply dopln pohyb radku dle typu polozky
   a vychoziho pohybu docType` (kroky 1, 2 + testy)
2. `docs: apply row operations` (krok 3; může jít s 1)

## Hotovo když

- [ ] Nový apply na dev DS (nový koncept z Kontroly) má na item
      řádcích vyplněný Pohyb a projde na docState 40 bez ručního
      doplňování.
- [ ] Účtenka za PHM (položka typu Služba/Zásoba dle resolvované
      položky) dostane odpovídající purchase.* pohyb; řádek
      s účetní položkou dostane acc.entry.
- [ ] Doplnění je vidět v Kontrole jako info issue
      `row_operation_defaulted`.
- [ ] Canonical s explicitní operation zůstává passthrough.
- [ ] Kontační a textové řádky beze změn; docType mimo mapu beze
      změn.
- [ ] Kompilovaná cfg přebuildnutá (cfgItem viditelný), testy
      zelené vč. konzistence konfigurace.

## Nasazení a ověření

1. Merge do `stable`, deploy dev, rebuild kompilované cfg.
2. Nový apply z Kontroly na dev DS → koncept s vyplněným pohybem,
   přechod na 40 bez ručního zásahu; kontrola issue v review.
3. Stávající koncepty `!0000000005`–`!0000000009`: doplnit ručně
   (mimo scope backfill).
4. Alfa: přechází pod koordinované message-centric nasazení (jako
   prompt v4.1.0).

---

## Dodatek (15. 8. 2026): revize D3 — bez info issue u rutinního doplnění

**Rozhodnutí potvrzeno 15. 8. 2026.**

### Zjištění z živého provozu

Info issue `row_operation_defaulted` se v okně Zkontrolovat objevuje
u **každého item řádku každého apply** — AI pohyb nikdy nevrací
(a vracet nemá), doplnění je stoprocentní rutina, ne výjimečná
událost. Upozornění, které svítí vždy, nenese žádnou informaci
a učí uživatele sekci Upozornění přeskakovat — degraduje pozornost
pro vzácné a důležité hlášky (`vat_mode_derived`, nespárovaný
partner, `row_operation_config_invalid`).

Transparentnost zajišťuje samotný výsledek: doplněný pohyb je přímo
vidět ve sloupci Pohyb vzniklého konceptu (na rozdíl od korekce
`vat_mode`, kde výsledek sám nevysvětluje odchylku od výstupu AI —
tam poznámka zůstává).

### Změna

1. **Odstranit** emit info issue `row_operation_defaulted`
   z `DocumentApplier` (doplnění pohybu probíhá beze změny, jen
   tiše). Upravit testy, které issue očekávají (případy 1–5:
   kontrola doplněné operace zůstává, aserce na issue odpadá;
   případ 6 passthrough beze změny).
2. **Zachovat** warning `row_operation_config_invalid` — hlásí
   skutečný problém (neexistující/nepovolený kód v konfiguraci)
   a vystřelí jen při rozbité mapě.
3. Dokumentace: v `ai-analysis.md` (review flow) odstranit zmínku
   o `row_operation_defaulted`; v docs applieru poznamenat, že
   doplnění je tiché a proč.

Commit: `fix(exchange): apply — bez info issue u rutinniho doplneni pohybu`

### Hotovo když (dodatek)

- [x] Apply z Kontroly nevytváří žádné `row_operation_defaulted`
      issue; pohyb je doplněný beze změny chování. (dev ověření)
- [x] `row_operation_config_invalid` zůstává (test s rozbitou
      mapou zelený).
- [x] Testy applieru upravené a zelené (úzký filtr
      `DocumentApplierTest`); preview větev (predikce item_type
      u side-creates) odstraněna celá — existovala jen kvůli D3,
      preview tak neplatí DB dotazy za nic.
- [x] Docs bez zmínky o odstraněné hlášce.
