# Modul: Obchodní unie (world.trade)

Definice nadnárodních ekonomických a daňových uskupení (obchodních unií),
členství zemí v nich a daňových prefixů. Primárně slouží pro Evropskou unii
a její pravidla pro DPH (reverse charge, OSS), ale struktura je dostatečně
obecná pro další unie (GCC apod.).

## Závislosti

- `world.base` — číselníky zemí, měn a jazyků

## Tabulky

Modul nemá vlastní tabulky.

## Konfigurace

| Klíč | Soubor | Popis |
|---|---|---|
| `world.trade.unions` | [config/tradeUnions.jsonc](config/tradeUnions.jsonc) | Obchodní unie, členství a daňové prefixy |

## Struktura konfiguračního souboru

### tradeUnions.jsonc

Klíčem je identifikátor unie malými písmeny (`eu`, `gcc`).

Každá unie obsahuje:

- `name`, `name:cs`, `name:en` — vícejazyčné názvy
- `shortName` — zkratka (EU, GCC)
- `taxType` — typ daně v rámci unie (`vat`)

#### members

Členské země unie. Klíčem je ISO 3166-1 alpha-2 kód země (odkaz do
`world.base.countries`).

- `joinedAt` — datum vstupu do unie (`null` = zakládající člen / datum není známo)
- `leftAt` — datum odchodu z unie (`null` = stále člen)

Příklad: Velká Británie (`gb`) má `leftAt: "2020-12-31"` (Brexit).

#### taxPrefixes

Prefixy používané v daňových identifikačních číslech (VAT-ID). Klíčem je
prefix **velkými písmeny** — přesně tak, jak se reálně používá ve VAT-ID
(CZ12345678, EL123456789).

- `country` — ISO kód země, ke které prefix patří
- `validFrom` / `validTo` — platnost prefixu (`null` = od začátku / bez konce)
- `region` (volitelné) — sub-národní oblast, pokud prefix není celostátní
- `note` (volitelné) — poznámka ke speciálním případům

Většina prefixů odpovídá 1:1 ISO kódu země. Existují dvě výjimky:

- **EL** → Řecko (ISO kód země je `gr`, ale VAT prefix je historicky `EL`)
- **XI** → Severní Irsko; speciální post-Brexit režim (Windsor Framework),
  kde firmy ze Severního Irska nadále fungují v EU VAT systému pro zboží.
  Mapuje se na zemi `gb` s upřesněním `region: "Northern Ireland"`.

### Příklady použití v aplikaci

**Ověření, zda je země členem EU k danému datu:**

```php
$unions = $config->cfgItem('world.trade.unions');
$eu = $unions['eu'];
$member = $eu['members']['cz'] ?? null;

if ($member !== null) {
    $joined = $member['joinedAt'];  // "2004-05-01"
    $left = $member['leftAt'];      // null
    // Česko je členem EU od 1. 5. 2004 dodnes
}
```

**Vyhledání země podle VAT prefixu:**

```php
$prefix = 'EL';  // z parsovaného VAT-ID
$taxPrefix = $eu['taxPrefixes'][$prefix] ?? null;
// $taxPrefix['country'] === 'gr' (Řecko)
```

## Plánovaná rozšíření

- VAT sazby členských zemí EU (standardní, snížené, nulové) — pro OSS
- Validační vzory VAT-ID per prefix (regex)
