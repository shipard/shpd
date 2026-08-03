# Dev-update skript a git hooks

**Stav:** hotovo

## Status / Cíl

Odstranit ruční kroky po `git pull`. Vytvořit jeden shell skript, který
spustí všechny sync operace (PHP autoloader, frontend deps, frontend build),
plus volitelnou git-hook automatizaci.

## Návaznost

- Spárovaný task: [`ds-upgrade-all.md`](ds-upgrade-all.md) — řeší čtvrtý
  bod (upgrade všech datových zdrojů). Tento task řeší body 1–3 (composer,
  npm install, npm build).
- `tasks/install-for-developers.md` — výchozí instalační workflow,
  který tento task rozšiřuje o post-pull fázi.
- Žádné PHP změny — vše je shell + dokumentace.

## Co je potřeba udělat

### 1. `scripts/dev-update.sh`

Bash skript s těmito vlastnostmi:

- `#!/usr/bin/env bash` + `set -euo pipefail`
- Najde repo root z `${BASH_SOURCE[0]}` (skript může běžet z libovolného CWD)
- `cd` do repo rootu
- Tři kroky, vždycky všechny:
  1. `composer install --no-interaction` — regeneruje autoloader jako
     side-effect, takže `composer dump-autoload` není potřeba samostatně
  2. `(cd frontend && npm install --no-audit --no-fund)`
  3. `(cd frontend && npm run build)`
- Mezi kroky vypisuje `==> [1/3] composer install` apod.
- Na konci připomínka:

  ```
  ==> Done. Pokud se měnily definice tabulek nebo cfgItems modulů,
      upgraduj všechny datové zdroje:

          sudo shpd-server ds-upgrade-all
  ```

- Hlavičkový komentář vysvětlí filozofii: "vždy spouštíme všechny kroky,
  jsou idempotentní a rychlé na no-op".
- Soubor musí mít executable bit (`chmod +x`).

### 2. `.githooks/_run-update` + symlinky

Společné jádro pro všechny tři hooks. Symlinky jsou součástí repa, takže
se distribuují s každým clone.

#### `.githooks/_run-update` (regulární soubor, executable)

```bash
#!/usr/bin/env bash
set -euo pipefail

HOOK_NAME="$(basename "$0")"

# post-checkout: argumenty jsou (prev_HEAD, new_HEAD, is_branch_checkout).
# Pouštět jen pro branch checkout (= "1"), ne pro file checkout (= "0").
if [ "$HOOK_NAME" = "post-checkout" ] && [ "${3:-0}" != "1" ]; then
    exit 0
fi

# post-rewrite: 1. argument je "rebase" nebo "amend".
# Pouštět jen pro rebase, ne pro amend (amend = jen lokální změna).
if [ "$HOOK_NAME" = "post-rewrite" ] && [ "${1:-}" != "rebase" ]; then
    exit 0
fi

REPO_ROOT="$(git rev-parse --show-toplevel)"

echo ""
echo "==> Running dev-update.sh ($HOOK_NAME)"
echo ""

bash "$REPO_ROOT/scripts/dev-update.sh"
```

#### Symlinky (commitnout do gitu jako symlinky)

- `.githooks/post-merge` → `_run-update`
- `.githooks/post-rewrite` → `_run-update`
- `.githooks/post-checkout` → `_run-update`

Vytvořit přes `ln -s _run-update .githooks/post-merge` apod. Git je uloží
jako symlinky, pokud `core.symlinks=true` (default na Linuxu).

### 3. Update `DEVELOPERS.md`

Po sekci "## 4. Ověření instalace" přidat **dvě nové sekce** (a posunout
následující číslování o 1 dál):

```markdown
## 5. Po `git pull`

Po každém stažení nové verze je potřeba zaktualizovat závislosti a
frontend build:

\`\`\`bash
bash scripts/dev-update.sh
\`\`\`

Skript vždy spustí `composer install`, `npm install` (ve `frontend/`)
a `npm run build`. Všechny kroky jsou idempotentní — pokud se nic
nezměnilo, projdou během pár sekund.

Pokud se měnily definice tabulek nebo cfgItems modulů, je potřeba
zaktualizovat i datové zdroje:

\`\`\`bash
sudo shpd-server ds-upgrade-all
\`\`\`

### Automatizace přes git hooks (volitelné)

Aby se `dev-update.sh` pouštěl automaticky po `git pull`,
`git pull --rebase` a `git checkout <branch>`:

\`\`\`bash
git config core.hooksPath .githooks
\`\`\`

Stačí spustit jednou v repu. Hook skripty jsou součástí repozitáře.
```

Sekce, které byly předtím "## 5. CLI utility" a "## 6. Kam dál", se
posouvají na 6 a 7.

## Co netřeba

- Žádné PHP změny — `ds-upgrade-all` příkaz je v navazujícím tasku
- Žádné testy pro shell skripty
- Žádné `docs/cli.md` — to je v navazujícím tasku
- Neřešit Windows kompatibilitu (DEVELOPERS.md cílí na Ubuntu LTS)

## Konvence k dodržení

- Bash, ne sh — `set -euo pipefail` se v sh neumí stejně
- Czech v UI textech a doc updatech, English v komentářích kódu
- Symlinky v `.githooks/` jsou v gitu skutečně jako symlinky (ne jako
  kopie souboru)

## Hotovo když

- `bash scripts/dev-update.sh` z libovolného CWD funguje a projde čistě
- `git config core.hooksPath .githooks && git pull` automaticky spustí
  build (a failne čistě, pokud npm/composer nejsou v PATH)
- `git pull --rebase` taky aktivuje hook; `git commit --amend` ne
- `git checkout <branch>` aktivuje hook; `git checkout file.php` ne
- Symlinky v `.githooks/` jsou v gitu jako symlinky (`git ls-files -s
  .githooks/post-merge` vrací mode `120000`)
- `DEVELOPERS.md` má novou sekci 5 "Po `git pull`" a sekce CLI utility
  + Kam dál posunuté na 6 a 7
- `scripts/dev-update.sh` má executable bit
