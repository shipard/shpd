# Oprava dvojího počítání DPH — derivace `vat_mode` u cen s DPH

**Stav:** naplánováno — rozhodnutí D1–D6 potvrzena 14. 8. 2026

**Cíl:** Doklady z AI analýzy, jejichž položkové řádky jsou v cenách
**s DPH** (koncové/maloobchodní ceny — účtenky, PHM, občerstvení),
vznikají s režimem výpočtu „shora" (`vat_mode 2`), takže daň není na
dokladu dvakrát a celková částka i rekapitulace sedí s předlohou.

**Referenční případ:** účtenka za PHM, dev DS, koncept `!0000000005`
ze zprávy 3 — 45 l × 38,80 Kč (koncová cena) = 1746,00 k úhradě, recap
na účtence 1442,98 + 303,02. AI vrátila všechna čísla doslovně správně,
ale `vat.mode: "fromBase"` → applier `vat_mode 1` → doklad
1746,00 / 366,66 / **2112,66** (daň podruhé). Správný výsledek
s `vat_mode 2`: 1442,98 / 303,02 / 1746,00.

**Návaznost:**

- Vychází ze záznamu „AI extrakce: špatný `vat.mode` u faktur s cenami
  včetně DPH" v `tasks/TODO.md` (07/2026, diagnostika zaokrouhlování) —
  sekci po dokončení z TODO odstranit. GitHub issue #13.
- Vzor řešení: derivace `deriveTotalRoundingMode`
  (`tasks/mail-invoice-rounding.md`) — deterministické odvození údaje,
  který AI neumí spolehlivě vrátit.
- Řetězec „shora" už existuje end-to-end: `VAT_MODE_MAP` má
  `fromTotal → 2` (DocumentApplier ~ř. 59), `DocDocument::calculateRowVat`
  má korektní větev pro mode 2 (`base = total / (1 + pct/100)`).
  Nová je jen derivace + prompt.
- Externí analyzer daemon (`ai_analyzer`) beze změn — prompt/schéma
  dostává z profilu přes claim response.

---

## Klíčová rozhodnutí (potvrzena 14. 8. 2026)

1. **D1 — Deterministická derivace `vat_mode` v `DocumentApplier`**
   (hlavní oprava). Diskriminátor: Σ `rows[].totalPrice` vs.
   Σ `vatRecap[].base` a Σ `vatRecap[].total`. Sedí-li Σ řádků právě
   na **total** a mode je `fromBase` → `vat_mode 2`; symetricky sedí-li
   právě na **base** a mode je `fromTotal` → `vat_mode 1`. Korekce
   přepisuje **jen** `vat_mode` hlavičky — canonical (vč. `totals`)
   zůstává forenzně nedotčený, `DocDocument` si base/VAT/totals
   přepočítá při apply sám. Každá korekce → poznámka do
   `_resolve.issues`.
2. **D2 — Prompt v4.1.0**: pravidlo pro rozpoznání koncových cen →
   `vat.mode: "fromTotal"`; čísla z dokladu opisovat, nepřepočítávat.
   Derivace z D1 zůstává jako pojistka.
3. **D3 — `priceCalcMode: "fromTotal"` pro účtenky** v pravidlech
   promptu: když je autoritativní celková cena řádku (PHM — qty × unit
   nemusí kvůli zaokrouhlení u stojanu sedět na total), vrátit
   `fromTotal`. Řeší haléřovou věrnost, ne zdvojení daně.
4. **D4 — Validátor**: warning pro konstelaci „mode `fromBase`, ale
   Σ řádků sedí na recap.total", uplatní se jen když D1 korekci
   neprovede (typicky chybějící recap i totals).
5. **D5 — Testy + ověření** na `!0000000005` a hledání dalších vzorů
   na alfě.
6. **D6 — Pořadí commitů** viz níže; po nasazení `ai-profile-reload
   --force`.

## Mimo scope

- Smíšené doklady (část řádků zdola, část shora) — `vat_mode` je
  hlavičkový, per-řádkový režim neexistuje a nezavádí se.
- Přepis `totals`/`vatRecap` v canonicalu při korekci (D1: jen
  `vat_mode`).
- Zpětná oprava dříve applynutých dokladů — jednotlivě ručně
  (reanalýza/editace), žádný hromadný backfill.

---

## Implementační kroky

### 1. `DocumentApplier` — derivace `deriveVatMode`

`modules/core/exchange/src/Document/DocumentApplier.php`:

Nová privátní metoda `deriveVatMode(array $canonical): ?int` volaná
z místa, kde se dnes čte `VAT_MODE_MAP` (~ř. 954):

```php
$vatMode = self::VAT_MODE_MAP[(string) ($canonical['vat']['mode'] ?? 'fromBase')] ?? 1;
$derived = $this->deriveVatMode($canonical);
if ($derived !== null && $derived !== $vatMode) {
    $vatMode = $derived; // + issue do _resolve
}
```

Algoritmus derivace:

1. Σ `rows[].totalPrice` přes item řádky (`rowKind` item / bez
   kontace); bez číselných totalPrice → `null` (žádná derivace).
2. Reference primárně z `vatRecap` (jen je-li kompletní — vzor
   `deriveTotalRoundingMode`): `refBase = Σ base`,
   `refTotal = Σ total`. Fallback bez recap: `refBase =
   totals.totalBase`, `refTotal = totals.totalAmount −
   (totals.totalRounding ?? 0)`.
3. Guardy: `|refTotal − refBase| < 1,00` → `null` (0% sazby /
   osvobozeno — oba režimy dají stejná čísla, nekorigovat).
   Tolerance shody `ε = max(0,02; 0,01 × počet řádků)`.
4. Shoda Σ řádků s právě jednou referencí:
   - ≈ `refTotal` a zároveň ≉ `refBase` → mode 2 (`fromTotal`),
   - ≈ `refBase` a zároveň ≉ `refTotal` → mode 1 (`fromBase`),
   - jinak (shoda s oběma / žádnou) → `null`.
5. **noPayTax pojistka:** pokud všechny řádky nesou vat kódy
   s `noPayTax` (PDP, EU pořízení — tam Σ řádků == base == total
   placené částky), guard 3 to přirozeně vyřadí (`refTotal ≈ refBase`
   z pohledu placené částky recap `total` vs `base` se u samovyměření
   liší o informativní daň — ověřit na testu z `docs-vat-totals-…`
   scénářů; kdyby guard nestačil, derivaci u noPayTax kódů explicitně
   přeskočit).

Při korekci přidat issue (vzor stávajících `$issues[]`):
`kind: 'vatModeDerived'`, severity info/warning, text
„Řádky jsou v cenách s DPH — režim výpočtu odvozen shora (fromTotal)."
(resp. zrcadlově pro opačný směr).

### 2. `DocumentValidator` — warning bez derivace

`modules/core/exchange/src/Document/DocumentValidator.php`
(`checkTotalsCoherence` nebo sesterská metoda): když canonical
deklaruje `vat.mode: fromBase`, recap chybí a Σ řádků ≈
`totals.totalAmount` ale ≉ `totals.totalBase` → warning
`vat_mode_suspect` („řádky vypadají jako ceny s DPH"). Nepřekrývat
s D1: pokud by derivace v applieru proběhla, warning nevzniká
(derivace má stejná data k dispozici dřív).

### 3. Prompt v4.1.0

`modules/core/mail/profiles/czech_general.jsonc`:

- Do PRAVIDEL: „`vat.mode`: pokud jsou jednotkové/celkové ceny řádků
  uvedeny VČETNĚ DPH (koncové ceny — účtenky, PHM, maloobchod;
  poznáš to tak, že součet položek odpovídá částce k úhradě, ne
  základu daně), vrať `\"fromTotal\"`. Jinak `\"fromBase\"`. Čísla
  z dokladu vždy opisuj tak, jak jsou uvedena — nikdy je nepřepočítávej
  na základ bez daně."
- Do PRAVIDEL PRO ÚČTENKY: „`rows[].priceCalcMode`: pokud je na
  účtence autoritativní celková cena řádku (typicky PHM, kde
  qty × jednotková cena nemusí přesně sedět na celkovou), vrať
  `\"fromTotal\"` a `totalPrice` opiš přesně z dokladu."
- Bump `prompt_version` **v4.0.0 → v4.1.0** na všech místech: pole
  + 2 výskyty v textu (pravidlo `source.promptVersion` a ukázkový
  JSON).
- `modules/core/mail/docs/ai-prompts.md` — changelog v4.1.0.

### 4. Dokumentace

- `docs/exchange-format.md` — u `vat.mode` poznámka o derivaci
  applierem (kdy a proč server režim odvodí jinak, než AI vrátila).
- `modules/core/mail/docs/ai-analysis.md` — zmínka
  o `vatModeDerived` issue v review flow.

## Testy

`DocumentApplierTest` (nové případy):

1. Referenční účtenka (čísla z `!0000000005`): mode `fromBase`,
   recap base 1442,98 / total 1746,00, Σ řádků 1746,00 → derivace
   `vat_mode 2` + issue `vatModeDerived`.
2. Korektní „zdola" faktura (Σ řádků == recap.base) s mode
   `fromBase` → beze změny, žádné issue.
3. Opačný směr: mode `fromTotal`, Σ řádků == recap.base → `vat_mode 1`.
4. 0% sazba / osvobozeno (refBase ≈ refTotal) → žádná derivace.
5. Bez recap, fallback přes totals (vč. nenulového `totalRounding`
   — reference `totalAmount − totalRounding`).
6. Bez recap i totals → žádná derivace.
7. noPayTax scénář (PDP cz-117): derivace se neaktivuje, doklad
   z `docs-vat-totals-reverse-charge` beze změny.
8. Nejednoznačná shoda (Σ řádků sedí na obě reference v toleranci)
   → žádná derivace.

`DocumentValidatorTest`: warning `vat_mode_suspect` jen v konstelaci
bez recap; s recap (derivace možná) warning nevzniká.

Regrese: `DocDocumentTotalsTest`, `DocDocumentVatRecapTest`,
`DocDocumentPdpOutputTest` zelené beze změn (úzké filtry).

## Pořadí commitů

1. `fix(exchange): derivace vat_mode z pomeru radku a rekapitulace`
   (kroky 1, 2 + testy)
2. `mail: prompt v4.1.0 — vat.mode a priceCalcMode pro koncove ceny`
   (krok 3)
3. `docs: derivace vat_mode` (krok 4; může jít s 1)

## Hotovo když

- [ ] Referenční účtenka projde applierem s `vat_mode 2` a doklad
      po apply má 1442,98 / 303,02 / 1746,00 (shoda s předlohou).
- [ ] Korektní „zdola" faktury procházejí beze změny (žádná falešná
      derivace) — regrese na stávajících fixtures.
- [ ] noPayTax doklady (PDP, EU pořízení) derivace nezasahuje.
- [ ] Korekce viditelná v `_resolve.issues` (review modal ji zobrazí).
- [ ] Validátor warnuje jen tam, kde derivace neměla data.
- [ ] Prompt v4.1.0 nasazen (`ds-upgrade` + `ai-profile-reload
      --force` na dev i alfě), verze bumpnutá na všech 3 místech.
- [ ] PHPUnit (exchange + docs + mail) zelené, úzké filtry.

## Nasazení a ověření

1. Merge do `stable`, deploy dev.
2. `ai-profile-reload --force` na dev DS; reanalýza zprávy 3
   („Znova analyzovat") → nový návrh má `vat.mode: fromTotal`
   (prompt) — a i kdyby ne, applier derivuje mode 2.
3. Apply → doklad 1442,98 / 303,02 / 1746,00; deník vyrovnaný
   (MD 501/343110, DAL 321 na 1746,00).
4. Alfa: po dokončení message-centric nasazení vyhledat vzor
   dotazem z TODO (Σ řádků ≈ recap.total) nad reálnými analýzami
   a namátkou ověřit review karty.
