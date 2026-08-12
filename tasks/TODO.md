# TODO — poznámky k budoucímu řešení

## AI extrakce: špatný `vat.mode` u faktur s cenami včetně DPH

**Zjištěno:** 07/2026 při diagnostice zaokrouhlování
(`tasks/mail-invoice-rounding.md`) na reálné faktuře z testovacího
prostředí.

**Problém:** U faktur, kde jsou položkové řádky uvedené v cenách
**s DPH** (koncové/maloobchodní ceny — typicky občerstvení, drobný
prodej), AI vrací `vat.mode: "fromBase"` a `rows[].totalPrice` s cenou
včetně daně. Applier mapuje `fromBase` → `vat_mode = 1`, takže
`DocDocument` při apply počítá DPH **zdola nad cenou, která už daň
obsahuje** → DPH je na dokladu dvakrát a celková částka je věcně
špatně (o celou sazbu vyšší než na faktuře).

**Jak vzor poznat na datech:** Σ `rows[].totalPrice` ≈
Σ `vatRecap[].total` (základ + daň), nikoli ≈ `totals.totalBase`;
jednotkové ceny řádků odpovídají koncovým cenám z faktury.

**Směr řešení (k rozmyšlení, až se to bude dělat):**

1. **Prompt** — pravidlo pro rozpoznání faktury s cenami s DPH: pokud
   součet řádků odpovídá částce s daní / faktura uvádí jednotkové
   ceny s DPH, vrátit `vat.mode: "fromTotal"` (počítá se „shora“).
   Ověřit, že `VAT_MODE_MAP` v `DocumentApplier` a větev fromTotal
   v `DocDocument` fungují end-to-end.
2. **Deterministický sanity check** (validator nebo applier) —
   nezávisle na promptu: když Σ řádků sedí na recap total (a ne na
   totalBase) a mode je `fromBase`, minimálně warning do
   `_resolve.issues` („řádky vypadají jako ceny s DPH, zkontroluj
   režim výpočtu“), případně automatická korekce modu.
3. Otestovat proti reálným případům na testovacím prostředí
   (najde se přes vzor v bodu „jak poznat na datech“).

**Priorita:** střední — u dodavatelů s koncovými cenami to bude bít
opakovaně a výsledný doklad je věcně špatně (ne jen kosmetický
warning).

---

## Země vlastního subjektu má dva zdroje

**Zjištěno:** 08/2026 při přípravě `vat-payer-01`.

`AccountingEngine::resolveOwnCompanyCountry()` bere zemi z **adresy sídla
vlastní Osoby** (`OwnCompanyResolver::getOwnHeadquartersAddress()`)
s natvrdo zadrátovaným fallbackem `'cz'`. Task `ds-setup-01` přitom
zavedl `DataSourceConfig::getCountry()` jako parametr vrstvy A
(`docs/ds-setup.md` D1).

**Proč to není jen kosmetika:** na čerstvém DS adresa sídla neexistuje,
dokud ji nezaloží průvodce, takže engine tiše počítá s `'cz'`. U slovenské
firmy to znamená špatně vyhodnocené vat kódy ještě předtím, než si toho kdo
všimne.

**Směr řešení:** země subjektu (vrstva A) a země sídla nejsou totéž, takže
ne slepé sjednocení. Nejspíš: primárně `getCountry()`, adresa sídla jako
upřesnění tam, kde na něm záleží, a fallback `'cz'` zrušit.

**Priorita:** nízká dokud jsou všechny zdroje české; roste s prvním
nečeským DS. Vědomě **mimo oblast** `ds-setup` (rozhodnutí Anny).

---

## `dismiss` alertu není trvalý — check ho při dalším běhu obnoví

**Zjištěno:** 08/2026 při přípravě Fáze 3 `ds-setup`.

`AlertReconciler::mergeFindings()` si existující řádky tahá
`WHERE check_id = %s AND alert_state IN (ACTIVE, SNOOZED)`. Dismissnutý řádek
v lookupu není, takže `$existingRow === null` a vloží se **nový** řádek se
stavem `ACTIVE`. Index `idx_check_finding_state` je `type: "index"` nad
`(check_id, finding_key, alert_state)`, takže ani nekoliduje.

**Proti záměru:** komentář v `AlertsController` říká
`Max 1 rok snooze (deliberate "navždy" je dismiss, ne snooze)` — dismiss má
tedy být trvalý, ale fakticky funguje jako „skryj do dalšího běhu“
a v tabulce se hromadí mrtvé řádky.

**Směr řešení:** buď zahrnout `DISMISSED` do lookupu a dismissnutý řádek
neoživovat (dismiss = trvalé potlačení této `finding_key`), nebo dismiss
přeznačit na „potlač do změny obsahu findingu“. Rozhodnutí je věcné, ne
technické — u různých checků se hodí různě.

**Pro `ds-setup` to není blokující:** setup checky mají snooze i dismiss
zakázaný (`ds-setup.md` D13), takže se jich chování dismissu netýká.

**Priorita:** nízká, ale je to tichá nekonzistence mezi kódem a dokumentovaným
záměrem — čím dřív se rozhodne, tím míň řádků bude třeba uklidit.

---

## Settings stránky neumí pole typu `select` a `checkbox`

**Zjištěno:** 08/2026 při návrhu panelu `dsSetup` (`docs/ds-setup.md` D14).

`ModuleDefinition::fromArray()` whitelistuje field typy
`text`, `image`, `theme`, `language`, `avatar`; `app-settings.md` odkládá
`select` a `checkbox` na „první stránku, která je potřebuje“.

**Proč to není úkol oblasti `ds-setup`:** parametry vrstvy C se ovládají
v ručně psaném panelu, který si select vyrenderuje sám (D14). Zůstává to
tedy jako obecná mezera settings stránek, ne jako blokace.

**Směr řešení:** rozšířit whitelist, doplnit větev v
`SettingsController::savePage()` (vzor `language` — validace proti seznamu
povolených hodnot) a render ve `SettingsPage.svelte`. U `select` rozhodnout,
jestli se volby berou z literálního `options` v module.jsonc, nebo z `cfgItem`
(`world.base.currencies` a spol.) — druhá varianta je lokalizovaná, ale
vyžaduje přístup ke `ConfigRuntime` při sestavování definice stránky.

**Poznámka k `checkbox`:** tříhodnotové příznaky (nerozhodnuto / ano / ne)
checkbox neunese — pro ty je správná odpověď `select` s prázdnou volbou
(typ `text` už dnes mapuje prázdný string na `null`, tedy smazání klíče).

**Priorita:** nízká, dokud žádná settings stránka takové pole nepotřebuje.
