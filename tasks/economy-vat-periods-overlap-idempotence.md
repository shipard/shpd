# Task: `VatPeriodsProvisioner` — idempotence přes překryv

**Status:** ✅ Implementováno 2026-07-26 — zbývá ověřit no-op `ds-upgrade` po Fázi 23 importu

## Cíl

`VatPeriodsProvisioner` dnes testuje existenci období přes **rovnost
`date_begin`**. To stačilo, dokud byl provisioner jediným zdrojem období
a všechna měla stejnou frekvenci. Jakmile do `economy_codebooks_vat_periods`
přitečou **reálná historická období z importu ze starého Shipardu**
(`old_shipard/modules/imports/newShipard/tasks/23-vat-periods-runner.md`),
rovnostní lookup přestane stačit: pokud se frekvence naimportovaných období
liší od `tax_period_kind` na registraci, provisioner nenajde existující období
a **založí nové, které se s ním překrývá**.

Konkrétně — registrace měsíční (`tax_period_kind = 1`), naimportovaná období
čtvrtletní:

| Kandidát provisioneru | Existující období | Dnešní lookup | Výsledek |
|---|---|---|---|
| `2028-01-01 … 2028-01-31` | `2028-01-01 … 2028-03-31` | `date_begin = 2028-01-01` → nalezeno | skip ✓ |
| `2028-02-01 … 2028-02-29` | `2028-01-01 … 2028-03-31` | `date_begin = 2028-02-01` → nenalezeno | **vytvoří překryv** ✗ |
| `2028-03-01 … 2028-03-31` | `2028-01-01 … 2028-03-31` | `date_begin = 2028-03-01` → nenalezeno | **vytvoří překryv** ✗ |

Překrývající se období znamenají nedeterministické dohledání:
`DocDocument::resolveVatPeriodId()` má `LIMIT 1` **bez `ORDER BY`**, takže
dva doklady se stejným `vat_duzp` mohou skončit v různých obdobích DPH.

Na alfě je to reálné riziko: `nsa-firma` má čtvrtletní registraci
(`tax_period_kind = 2`), zbylé tři měsíční.

## Návaznost

- **Souvisí:** `old_shipard/modules/imports/newShipard/tasks/23-vat-periods-runner.md`
  (import reálné historie období). Tato změna je jeho předpoklad — bez ní první
  `ds-upgrade` po importu může založit překryvy.
- **Nezávisí na ničem jiném** — samostatná lokální změna v provisioneru.

## Scope

### V rozsahu

- `VatPeriodsProvisioner::generatePeriodsForYear()` — lookup na překryv
  místo rovnosti `date_begin`
- Aktualizace `VatPeriodsProvisionerTest` (mock harness + nové případy)
- Dokumentace: `tables/economy_codebooks_vat_periods.md`, `README.md` modulu

### Mimo rozsah

- Deterministické `ORDER BY` v `DocDocument::resolveVatPeriodId()` — až
  překryvy nebudou vznikat, není co řešit. Pokud se ukáže, že v datech
  překryvy legitimně existují (ruční mimořádná období), je to samostatné
  rozhodnutí.
- Detekce a oprava už existujících překryvů — na alfě žádná období nejsou,
  cílový scénář je `ds-reset` + plný import.

## Změna

### `modules/economy/codebooks/src/VatPeriodsProvisioner.php`

V `generatePeriodsForYear()` nahradit:

```php
$row = $this->db->fetchRow(
    'SELECT `id` FROM `economy_codebooks_vat_periods`'
    . ' WHERE `vat_registration` = %i AND `date_begin` = %d',
    $regId,
    $candidate['date_begin']->format('Y-m-d'),
);
```

za:

```php
// Idempotence přes PŘEKRYV, ne rovnost date_begin: v tabulce mohou být
// období importovaná ze starého Shipardu s jinou frekvencí, než jakou má
// registrace v `tax_period_kind` (např. čtvrtletní historie + měsíční
// registrace). Rovnostní lookup by je nenašel a vygeneroval by období
// překrývající se s existujícím → nedeterministické dohledání v
// DocDocument::resolveVatPeriodId() (LIMIT 1 bez ORDER BY).
$row = $this->db->fetchRow(
    'SELECT `id` FROM `economy_codebooks_vat_periods`'
    . ' WHERE `vat_registration` = %i'
    . ' AND `date_begin` <= %d AND `date_end` >= %d',
    $regId,
    $candidate['date_end']->format('Y-m-d'),
    $candidate['date_begin']->format('Y-m-d'),
);
```

Pořadí parametrů je záměrně `regId, candidateEnd, candidateBegin` — standardní
test překryvu dvou intervalů (`a.begin <= b.end AND a.end >= b.begin`).

**Lookup dál ignoruje `docState`** — invariant „smazané období zůstává
smazané" se zachovává. Nová semantika ho ale rozšiřuje: smazané období
blokuje generování **všeho, co se s ním překrývá**, ne jen období se stejným
`date_begin`. Prakticky: uživatel smaže leden 2028 u měsíční registrace →
provisioner leden nikdy nevrátí (stejné jako dnes); uživatel smaže Q1 2028
u čtvrtletní registrace a pak přepne registraci na měsíční → provisioner
nevygeneruje ani leden, ani únor, ani březen. To je konzistentní s dosavadní
filozofií (uživatelovo smazání se respektuje) a doříkává se to v dokumentaci.

Upravit i docblock třídy — dnes tvrdí:

> Lookup před insertem ignoruje docState — smazaná období (`docState=90`) se
> proto nikdy nevracejí.

Doplnit, že lookup je překryvový a proč (import historie s jinou frekvencí).

## Testy

`tests/Unit/Module/Economy/Codebooks/VatPeriodsProvisionerTest.php`

**1. Mock harness `recordingDb()`** — callback pro `fetchRow` dnes porovnává
`date_begin` na rovnost s `$params[1]`. Přepsat na překryv podle nového
pořadí parametrů:

```php
$db->method('fetchRow')->willReturnCallback(
    function (string $sql, mixed ...$params) use ($store): ?array {
        if (str_contains($sql, 'economy_codebooks_vat_periods')
            && str_contains($sql, 'date_begin')
            && str_contains($sql, 'date_end')
        ) {
            $regId = (int) ($params[0] ?? 0);
            $candEnd   = (string) ($params[1] ?? '');
            $candBegin = (string) ($params[2] ?? '');
            foreach ($store->tables['economy_codebooks_vat_periods'] as $row) {
                if ((int) ($row['vat_registration'] ?? 0) === $regId
                    && ($row['date_begin'] ?? '') <= $candEnd
                    && ($row['date_end'] ?? '')   >= $candBegin
                ) {
                    return $row;
                }
            }
            return null;
        }
        return null;
    }
);
```

Zkontrolovat fixtures, které staví `existingPeriods` ručně (minimálně
`testIdempotenceSkipsDeletedPeriod`) — nově musí mít vyplněný **`date_end`**,
jinak překryv nikdy nesedne. Rows vzniklé přes `insertRow` `date_end` mají.

**2. Existující testy musí projít bez změny očekávání** — u shodné frekvence
je překryvový lookup ekvivalentní rovnostnímu:
`testMonthlyRegistrationGenerates24Periods`, `testQuarterlyRegistrationGenerates8Periods`,
`testValidFromMidYearSkipsEarlierMonths`, `testValidToTruncatesLaterMonths`,
`testIdempotenceSecondRunCountsExisting`, `testIdempotenceSkipsDeletedPeriod`,
`testDeletedRegistrationIsIgnored`, `testTwoRegistrationsHandledIndependently`,
`testNoRegistrationsResultsInZero`.

**3. Nové testy:**

- `testQuarterlyExistingPeriodBlocksMonthlyCandidates` — registrace měsíční
  (`kind = 1`, `valid_from = 2026-01-01`), v tabulce existující období
  `2026-01-01 … 2026-03-31`; refDate `2026-04-15` → leden/únor/březen 2026
  se **nevytvoří** (`existing` += 3), zbytek 2026 (9 měsíců) + celý 2027
  (12) vznikne → `created = 21`. Žádné vložené období nesmí zasahovat do
  `2026-01-01 … 2026-03-31`.
- `testMonthlyExistingPeriodBlocksQuarterlyCandidate` — registrace čtvrtletní
  (`kind = 2`), v tabulce jen `2026-02-01 … 2026-02-28`; refDate `2026-04-15`
  → Q1 2026 se **nevytvoří** (překryv s únorem), Q2–Q4 2026 + 4 kvartály 2027
  vzniknou → `created = 7`, `existing = 1`.
- `testNonAlignedImportedPeriodBlocksOverlappingCandidate` — reálný případ
  z importu: existující `2026-11-02 … 2026-12-31` (neúplné vstupní období),
  registrace měsíční → listopad i prosinec 2026 se nevytvoří (`existing`
  += 2), leden–říjen 2026 a celý 2027 ano → `created = 22`.
- `testDeletedOverlappingPeriodStillBlocks` — existující období s
  `docState = 90` překrývající kandidáta → kandidát se nevytvoří
  (invariant „smazané zůstává smazané" platí i pro překryv).

Spuštění (vždy úzký filtr):

```
vendor/bin/phpunit --filter VatPeriodsProvisioner
```

## Dokumentace

**`modules/economy/codebooks/tables/economy_codebooks_vat_periods.md`** —
v sekci „Pravidla" přepsat bullet o idempotenci:

> - Idempotence: lookup před insertem je **překryvový**
>   (`vat_registration` + `date_begin <= kandidát.date_end AND date_end >=
>   kandidát.date_begin`) a **ignoruje docState**. Důvody: (a) v tabulce
>   mohou být období importovaná ze starého Shipardu s jinou frekvencí, než
>   má registrace — rovnostní lookup by je nenašel a založil překryv;
>   (b) smazané období (`docState=90`) zůstává smazané a blokuje i generování
>   překrývajících se kandidátů.

**`modules/economy/codebooks/README.md`** — v sekci „Auto-generování období
DPH" upravit odstavec **Idempotence** stejným způsobem a v sekci „Manuální
správa" doplnit k bulletu o změně frekvence: po smazání nesedících starých
období provisioner doplní chybějící podle aktuální frekvence, ale období,
která se s nesmazanými zbytky překrývají, nevytvoří — úklid musí být úplný.

## Commit strategie

1. `fix(economy): overlap-based idempotence in VatPeriodsProvisioner`
   — provisioner + testy
2. `docs(economy): document overlap-based vat period idempotence`
   — `tables/*.md` + `README.md`

## Hotovo když

- [x] Lookup v `generatePeriodsForYear()` je překryvový, s komentářem proč
- [x] Docblock třídy popisuje překryvovou semantiku i rozšířený dopad na
      smazaná období
- [x] Mock harness v testu simuluje překryv podle nového pořadí parametrů
- [x] Všech 9 existujících testů prochází bez změny očekávaných hodnot
- [x] 4 nové testy prochází (čtvrtletní blokuje měsíční, měsíční blokuje
      čtvrtletní, nezarovnané období blokuje, smazané překrývající blokuje)
- [x] `vendor/bin/phpunit --filter VatPeriodsProvisioner` zelené
- [ ] `bin/shpd-ds ds-upgrade` na DS s naimportovanými obdobími je no-op
      (`vat periods — created: 0, existing: N`) — ověřit až po Fázi 23 importu
- [x] Dokumentace (tabulka + README) aktualizovaná
