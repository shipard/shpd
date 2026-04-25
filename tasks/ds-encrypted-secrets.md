# DS encrypted secrets — generický šifrovací mechanismus

**Status:** Draft k odsouhlasení
**Cíl:** Zavést infrastrukturu pro šifrované ukládání citlivých dat
v DB. Per-DS šifrovací klíč v adresáři zdroje dat — přežije migrační
workflow (tarball + DB dump na jiný server) bez zásahu admina.

**Konzument v krátkodobém horizontu:** Fáze 3a (`mail-phase3a.md`) —
`core_mail_ai_backends.api_key`.

**Konzumenti v dlouhodobém horizontu** (mimo scope tohoto tasku, jen
pro kontext):

- Mail credentials — SMTP/IMAP `password`, OAuth refresh tokens
- Přihlášení k datovým schránkám
- Wi-Fi hesla v hospodářské evidenci
- Banking API tokens (až bude integrace bank)
- API klíče k externím službám (DMS, účetní SW, ERP)

**Princip:** každý nový citlivý sloupec, který v systému vznikne, použije
tento mechanismus **od začátku**. Žádný nový plain-text citlivý sloupec
už by neměl vzniknout.

---

## 1. Threat model a limity

Šifrování chrání primárně proti **izolované expozici DB**:

| Vektor                                         | Chráněno? |
|------------------------------------------------|-----------|
| DB backup leaknutý mimo server                 | ✓         |
| SQL injection (leakuje jen DB obsah)           | ✓         |
| Admin omylem ukáže DB dump v chatu/mailu       | ✓         |
| Filesystem dump / tarball leaknutý             | ✗         |
| Running server compromise (shell access)       | ✗         |

**Útočník s plným přístupem k filesystemu DS získá i klíč** — je ve
stejném adresáři. To je záměrný tradeoff za provozní jednoduchost
a bezbolestnou migraci mezi servery. Pro silnější model (HSM, externí
KMS) by se musel vzdát tarball-based migrace.

---

## 2. Per-DS klíč

### 2.1 Umístění

```
{ds_path}/secrets/secrets.key
```

kde `ds_path` je `DataSourceConfig::getDataSourceDir()`. Typicky
`/opt/shipard/data-sources/{ds_id}/secrets/secrets.key`.

### 2.2 Obsah a permissions

- **Obsah:** 32 bytes raw binary (256-bit klíč pro AES-256-GCM)
- **Permissions:**
  - Adresář `secrets/`: `0700 shipard:shipard`
  - Soubor `secrets.key`: `0600 shipard:shipard`

### 2.3 Generování

Při `DsCreate` přes `random_bytes(32)`. Atomicky:

1. Vytvořit `secrets/` s permissions `0700`
2. Vygenerovat 32 bytes
3. Zapsat do `secrets.key.tmp` s `0600`
4. `rename()` na `secrets.key`
5. Validace přes `DsSecretCipher::healthCheck()`

Při jakékoliv chybě → smazat částečně vytvořený stav, propagovat
exception.

### 2.4 Backup a migrace

`secrets/` je uvnitř `ds_path` → součást tarballu DS automaticky.

Dokumentace musí explicitně upozornit, že:
- `secrets/` adresář **musí** být součástí každé backup strategie
- Po rozbalení tarballu na cílovém serveru ověřit permissions (extract
  může default-nout na jiný umask)
- Po importu DB + rozbalení tarballu spustit `ds-secrets-health` pro
  verifikaci
- **Ztráta `secrets.key` = ztráta všech šifrovaných dat.** Admin musí
  znovu zadat všechny secrets.

---

## 3. Ciphertext format v DB

```
v1:{nonce_b64}:{tag_b64}:{ciphertext_b64}
```

| Prvek        | Popis                                                  |
|--------------|--------------------------------------------------------|
| `v1`         | Verze formátu. Budoucí upgrade (jiný alg, key rotation metadata) bez breaking change. |
| `nonce`      | 12 bytes (GCM standard), base64                        |
| `tag`        | 16 bytes (GCM auth tag), base64                        |
| `ciphertext` | Zašifrovaný plaintext, base64                          |

Délka pro typický 100-byte plaintext: ~200 chars.

Sloupec v DB: `text` — umožní libovolnou délku budoucích secrets bez
schema migration.

---

## 4. `DsSecretCipher` třída

Umístění: `src/Core/Security/DsSecretCipher.php`

Veřejné API:

```php
namespace Shipard\Core\Security;

class DsSecretCipher
{
    /**
     * Construct from DS config. Reads secrets.key on first use,
     * caches in memory for lifetime of instance.
     *
     * @throws SecretsKeyMissingException
     * @throws SecretsKeyInsecureException
     */
    public static function forConfig(DataSourceConfig $config): self;

    /**
     * Encrypt plaintext. Returns ciphertext string in v1 format.
     * Generates fresh nonce per call.
     */
    public function encrypt(string $plaintext): string;

    /**
     * Decrypt ciphertext. Throws on any failure (tampered, wrong key,
     * malformed). Never returns partial/garbage.
     *
     * @throws InvalidCiphertextException
     */
    public function decrypt(string $ciphertext): string;

    /**
     * Validate that secrets.key is present and has correct permissions.
     * Returns array of warning strings (empty if OK).
     *
     * Does NOT validate that ciphertext in DB is decryptable — for that
     * use the ds-secrets-health CLI command which scans tables.
     */
    public static function healthCheck(DataSourceConfig $config): array;
}
```

### 4.1 Implementace

Použít PHP `sodium_crypto_aead_aes256gcm_*` funkce (libsodium binding).
Vyžaduje PHP 7.2+ s built-in libsodium (od PHP 7.2 součást core).

**Zamítnuté alternativy:**
- `openssl_encrypt()` — vyžaduje větší kontrolu nad nonce/tag separation,
  větší prostor na chyby
- Composer balíček `paragonie/halite` — další dependency pro málo přidanou
  hodnotu; libsodium core stačí

### 4.2 Lifecycle a caching

`DsSecretCipher::forConfig($config)` cachuje instance per `ds_path`. V
rámci jednoho HTTP requestu / CLI invocation se klíč ze souboru čte jen
jednou. Klíč v paměti zůstává pro životnost instance — žádné explicitní
zero-out (PHP správa paměti to neumí garantovat, není kam investovat
úsilí pro malý zisk).

### 4.3 Exception types

```php
// src/Core/Security/Exception/
class SecretsKeyMissingException extends \RuntimeException;
class SecretsKeyInsecureException extends \RuntimeException;
class InvalidCiphertextException extends \RuntimeException;
```

Všechny jsou neoderdědené z jedné kořenové `SecretsException` (logging
i selektivní handling). Kořenovou class neexponovat ven, jen tyto tři.

### 4.4 Fail-fast chování

- `secrets.key` neexistuje při `forConfig()` → `SecretsKeyMissingException`
  s návodem ("run ds-upgrade or contact admin")
- `secrets.key` má wrong permissions (world-readable, group-readable) →
  `SecretsKeyInsecureException` s návodem na `chmod 0600`
- `decrypt()` failne (integrity check, wrong key, malformed format) →
  `InvalidCiphertextException`

---

## 5. `encrypted_text` sloupcový typ

Nový **logický typ** v JSONC schema, fyzicky mapovaný na `text`:

```jsonc
{
  "id": "api_key",
  "name": "API key",
  "type": "encrypted_text",
  "nullable": true,
  "group": "credentials"
}
```

### 5.1 Schema parser změny

`SchemaValidator` rozpoznává `encrypted_text` jako platný typ.

`SqlGenerator` mapuje na `text NULL` v DB. Default `NULL`.

### 5.2 Žádné automatické šifrování

Sloupcový typ sám **nedělá** automatickou encryption v `TableGateway`.
Aplikační vrstva (Document classes) odpovídá za encrypt před save
a decrypt po read.

**Důvod:** automatika v TableGateway by spojila DB vrstvu s
`DsSecretCipher`, komplikuje testování, migrace, CLI tools. Explicitní
volání je jednodušší a transparentnější.

### 5.3 Audit trail v migracích

Při schema change obsahujícím `encrypted_text` sloupec — log informational
message:

```
[INFO] Adding encrypted_text column 'core_mail_ai_backends.api_key'.
       Application layer must use DsSecretCipher for read/write.
       Plaintext values will not be readable from DB directly.
```

### 5.4 Schema introspection helper

Pro `ds-secrets-health` (sekce §6.2) potřebujeme umět najít všechny
`encrypted_text` sloupce v DS. Extension `SchemaIntrospector::
findEncryptedColumns()` vrací:

```php
[
    ['table' => 'core_mail_ai_backends', 'column' => 'api_key'],
    // future: ...
]
```

---

## 6. CLI příkazy

### 6.1 `bin/shpd-ds ds-secrets-rotate`

```
Usage: shpd-ds ds-secrets-rotate [--dry-run]

Generates a new secrets.key, re-encrypts all encrypted_text columns in
the data source with the new key, atomically swaps the key file. Backup
of old key saved with .{timestamp}.bak suffix.

--dry-run    Show what would be re-encrypted without modifying anything.
```

**Postup:**

1. Generate new key in memory (`random_bytes(32)`)
2. Begin DB transaction
3. Use `SchemaIntrospector::findEncryptedColumns()` to enumerate target
   columns
4. For each column:
   - `SELECT id, {column} FROM {table} WHERE {column} IS NOT NULL`
   - For each row:
     - Decrypt with old key (via existing `DsSecretCipher`)
     - Encrypt with new key (via temporary in-memory `DsSecretCipher`
       constructed from new key bytes)
     - `UPDATE {table} SET {column} = ? WHERE id = ?`
5. Commit DB transaction
6. Atomically swap `secrets.key`:
   - Write new key to `secrets.key.tmp` (`0600`)
   - `fsync()` the file
   - Backup old: `mv secrets.key secrets.key.{ISO8601}.bak`
   - `rename()` `secrets.key.tmp` → `secrets.key`
7. Run `ds-secrets-health` as final validation

**Failure recovery:**

- Step 4 failure → rollback DB transaction, no key swap, error to user
- Step 6 file failure → DB already committed (problem!), need to roll
  forward: re-encrypt back to old key. Mitigation: do step 6 BEFORE
  step 4 commit? No — risk of inconsistent DB. Solution:
  - Verify `secrets.key.tmp` was written successfully before commit
  - Fail loudly if step 6 fails after step 5 — admin must restore old
    key from `.bak` and accept that DB is using new key.

Rotation should be done rarely — typicky jen při suspected key
compromise nebo provozní policy.

### 6.2 `bin/shpd-ds ds-secrets-health`

```
Usage: shpd-ds ds-secrets-health

Checks:
  - secrets.key file exists
  - secrets.key permissions are 0600
  - secrets/ directory permissions are 0700
  - All encrypted_text columns can be decrypted
  - Lists rows with invalid ciphertext (corruption / mismatch)

Exits 0 if all OK, 1 on any warning, 2 on any error.
```

Output happy path:

```
✓ secrets.key present (32 bytes)
✓ secrets.key permissions 0600
✓ secrets/ directory permissions 0700
✓ core_mail_ai_backends.api_key — 3 rows, all decryptable
✓ All checks passed
```

Output with issues:

```
✓ secrets.key present (32 bytes)
✗ secrets.key permissions are 0644 (should be 0600)
    Fix: chmod 0600 /opt/shipard/data-sources/{id}/secrets/secrets.key
✓ secrets/ directory permissions 0700
✗ core_mail_ai_backends.api_key — 1 of 3 rows failed decryption:
    - row id=42: InvalidCiphertextException (auth tag mismatch)
✗ Health check failed (1 error, 1 warning)
```

Použití v cronu:

```
# Týdenní kontrola integrity
0 3 * * 1 shpd-ds ds-secrets-health || mail -s "secrets health failed" admin@example.com
```

### 6.3 Integrace do `ds-upgrade`

`bin/shpd-ds ds-upgrade` (existuje) rozšířit:

1. Early in upgrade flow: detekuje missing `secrets/secrets.key` →
   vygeneruje ho. Loguje informational message:

   ```
   [INFO] Created secrets/secrets.key — no data migration needed
          (no encrypted columns existed in this DS yet).
   ```

2. Late in upgrade flow: spustí `DsSecretCipher::healthCheck()` jako
   sanity check.

   Pokud failne (např. broken permissions po manuálním zásahu) → log
   warning, ale upgrade nezruší (admin to vidí a opraví).

---

## 7. Integrační patterns (pro konzumenty)

Tento task **neimplementuje** konkrétní použití — jen dokumentuje
patterny pro budoucí konzumenty.

### 7.1 Document class pattern

```php
class AIBackendDocument extends DefaultDocument
{
    public function beforeSave(): void {
        parent::beforeSave();
        if ($this->isFieldDirty('api_key') && $this->data['api_key'] !== null) {
            $cipher = DsSecretCipher::forConfig($this->config);
            $this->data['api_key'] = $cipher->encrypt($this->data['api_key']);
        }
    }
}
```

**Pravidlo:** šifruj jen když pole je dirty. Šifrování beze změny by
generovalo nový nonce a měnilo ciphertext, znečistilo audit, atd.

### 7.2 Controller pattern

```php
public function getBackendForAnalyzer(int $backendNdx): array
{
    $backend = $this->db->fetchRow(
        'SELECT * FROM core_mail_ai_backends WHERE ndx = %i', $backendNdx
    );
    
    $cipher = DsSecretCipher::forConfig($this->dsConfig);
    return [
        'provider' => $backend['provider'],
        'model' => $backend['model'],
        'api_key' => $cipher->decrypt($backend['api_key']),
        // ...
    ];
}
```

**Pravidlo:** decrypt až těsně před použitím. Plaintext nikdy nezůstává
v paměti déle než nutné.

### 7.3 Form/UI pattern

UI **nikdy nezobrazuje plaintext** existujících secrets. Form pro
editaci backendu má pole `api_key` se speciálním chováním:

- Při zobrazení existujícího záznamu: pole prázdné, placeholder
  `"●●●●●●●● (zadat pro změnu)"`
- Submit s prázdnou hodnotou: pole zůstává beze změny
- Submit s vyplněnou hodnotou: nahrazuje existující (přes dirty flag,
  který trigger-uje encryption v `beforeSave()`)

V MVP není UI v scope — pouze CLI `*-set-key` typu příkazy. UI se přidá
v navazujícím tasku.

### 7.4 Anti-patterny

**Nikdy:**
- Necachuj decrypted hodnotu v session, cookie, log soubor
- Nelogguj plaintext (dokonce ani v debug logu)
- Neposílej plaintext do view/template — UI ho nesmí dostat
- Nepřenášej plaintext v URL parametrech, query stringu

---

## 8. Task breakdown

### Task 1 — `DsSecretCipher` třída

- `src/Core/Security/DsSecretCipher.php` podle §4
- Exception třídy v `src/Core/Security/Exception/`
- Použít `sodium_crypto_aead_aes256gcm_*`
- Unit testy:
  - encrypt/decrypt round-trip
  - každý encrypt() generuje fresh nonce (dva encrypt téhož plaintextu →
    různé ciphertexty)
  - tamper detection: flip bit v ciphertext → throws
  - tamper detection: flip bit v auth tag → throws
  - wrong key: encrypt s key A, decrypt s key B → throws
  - missing secrets.key → `SecretsKeyMissingException`
  - permissions `0644` → `SecretsKeyInsecureException`
  - permissions `0600` ale parent dir `0755` → warning (ne error)
  - malformed ciphertext (chybí prefix `v1:`) → `InvalidCiphertextException`
  - empty plaintext → encrypt/decrypt funguje
  - large plaintext (1 MB) → funguje
  - Unicode plaintext → správný round-trip

**Akceptace:** Unit testy zelené, code coverage `DsSecretCipher` ≥ 95 %.

### Task 2 — `encrypted_text` schema type

- Rozšíření JSONC schema parseru o `encrypted_text` jako platný typ
- `SqlGenerator` mapping na `text NULL`
- Informational log message při schema change
- `SchemaIntrospector::findEncryptedColumns()` helper
- Unit testy:
  - parsing JSONC s `encrypted_text` projde
  - SQL output je `text NULL`
  - introspection vrací správné sloupce

**Akceptace:** Test tabulka s `encrypted_text` sloupcem se vytvoří.
Introspection ho najde.

### Task 3 — `DsCreate` a `DsUpgrade` integrace

- Rozšířit `DsCreateCommand`:
  - Vytvoří `secrets/` s `0700`
  - Vygeneruje `secrets.key` s `0600`
  - Health check po vytvoření
  - Atomicity (rollback na chybu)
- Rozšířit `DsUpgradeCommand`:
  - Detect missing `secrets.key` → vygeneruje (informational message)
  - Final health check
- Integration testy:
  - `ds-create` → secrets.key existuje s correct permissions
  - `ds-upgrade` na DS bez secrets.key → vytvoří
  - `ds-upgrade` na DS s existing secrets.key → nemění

**Akceptace:** Nový DS i upgrade existujícího DS skončí se zdravým
secrets state.

### Task 4 — CLI `ds-secrets-health`

- `src/Command/DataSource/DsSecretsHealthCommand.php`
- Implementace podle §6.2
- Strukturovaný output (success / warning / error)
- Exit codes 0/1/2
- Integration testy:
  - happy path → exit 0
  - missing key → exit 2
  - wrong permissions → exit 1
  - corrupted ciphertext v DB → exit 2

**Akceptace:** CLI funguje a v CI lze použít jako post-deployment
check.

### Task 5 — CLI `ds-secrets-rotate`

- `src/Command/DataSource/DsSecretsRotateCommand.php`
- Implementace podle §6.1
- `--dry-run` flag
- Atomic key file swap s backup
- Integration testy:
  - happy path: rotate s 5 rows → all re-encrypted, old key backed up
  - dry-run: no DB changes, no file changes
  - failure mid-rotation: DB rollback, old key still active
  - rotate idempotency: rotate twice in a row → druhý také funguje

**Akceptace:** Rotation je atomic a recoverable.

### Task 6 — Test integration: dummy `encrypted_text` table

V test suite vytvořit "kanárkovou" tabulku `core_test_secrets` s
`encrypted_text` sloupcem (existuje jen v testovém prostředí). Pokrývá:

- Document class šifruje při save (přes `beforeSave()`)
- Controller dešifruje při read
- Round-trip přes API endpoint

Po dokončení Task 6 je infrastruktura **prokazatelně použitelná**, i bez
napojení na AI backends (které řeší Fáze 3a).

**Akceptace:** Kanárková tabulka funguje end-to-end. Plaintext nikdy
neleží v `core_test_secrets` v DB (vizuální inspekce přes
`SELECT * FROM core_test_secrets`).

### Task 7 — Dokumentace

- **`docs/operations/secrets.md`** — generic secrets mechanism:
  - Threat model (§1)
  - Backup strategy
  - Migration mezi servery
  - Rotation flow
  - Troubleshooting
- **`docs/migration-guide.md`** — explicitní upozornění:
  - `secrets/` adresář DS je součástí backup/migrace
  - Tarball musí obsahovat `secrets/`
  - Po rozbalení: ověřit permissions
  - Po importu: spustit `ds-secrets-health`
- **`CLAUDE.md`** — krátká sekce "Citlivá data":
  - Vždy používej `encrypted_text` typ pro nové citlivé sloupce
  - Vždy přes `DsSecretCipher` v Document classes
  - Anti-patterny (§7.4)
- Update README.md — zmínka o secrets infrastructure

**Akceptace:** Dokumentace pokrývá všechny běžné admin úkoly + warns
explicitně proti běžným chybám.

---

## 9. Migrační kompatibilita

### 9.1 Nové DS (po této fázi)

Mají `secrets.key` automaticky. Žádná akce.

### 9.2 Existující DS (před touto fází)

Při `ds-upgrade`:
1. Detekuje missing `secrets/secrets.key` → vygeneruje nový
2. Loguje informational message
3. Žádné `encrypted_text` sloupce před touto fází neexistují → nic
   k migraci

### 9.3 Tarball + DB dump migrace na jiný server

**Kritický flow:**

1. Backup zdroj: `tar czf ds-backup.tgz {ds_path}` (zahrnuje `secrets/`)
2. Backup DB: `mysqldump {ds_db} > ds-dump.sql`
3. Přenos na cílový server
4. Rozbalit tarball — pozor na permissions:

   ```bash
   tar xzf ds-backup.tgz -C /opt/shipard/data-sources/
   chown -R shipard:shipard /opt/shipard/data-sources/{ds_id}
   chmod 0700 /opt/shipard/data-sources/{ds_id}/secrets
   chmod 0600 /opt/shipard/data-sources/{ds_id}/secrets/secrets.key
   ```

5. Import DB
6. **Ověření:** `bin/shpd-ds ds-secrets-health`

Pokud `secrets.key` chybí v tarballu → admin musí znovu zadat všechny
secrets (pro každý konzument samostatný `*-set-key` CLI).

---

## 10. Rozhodnutí k designu (potvrzená)

1. ✓ **libsodium dependency.** Vyžadovat libsodium na startu shpd
   (`extension_loaded('sodium')`) → fatal error pokud chybí. Žádný
   openssl fallback.

2. ✓ **Schema introspection** skrz JSONC parsing (single source of truth),
   cache výsledku per request.

3. ✓ **`secrets.key` rotation** je ruční přes CLI. Žádný scheduled task
   v MVP. Přidat až s konkrétním compliance požadavkem.

4. ✓ **Backup retention pro `.bak` files** — ruční mazání. Admin ví,
   kdy je bezpečné `.bak` smazat (po ověření, že rotation drží).

5. ✓ **Per-DS klíč** (nikoli per-table nebo per-column). Jednodušší,
   stačí pro use cases v plánu.

6. ✓ **Performance neoptimalizovat.** Encrypted řádků nikdy nebude
   velké množství. Žádný caching layer, žádné batch operations.
