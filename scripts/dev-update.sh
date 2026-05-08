#!/usr/bin/env bash
#
# dev-update.sh — sync dev environment after pulling new code.
#
# Philosophy: always run all steps. They're idempotent and fast on no-op,
# so it's simpler and safer to run everything than to detect what changed.
#
# Steps:
#   1. composer install   (regenerates autoloader as a side-effect)
#   2. npm install        (in frontend/)
#   3. npm run build      (in frontend/)
#
# Data-source upgrades (ds-upgrade-all) are intentionally NOT run here —
# they need sudo and touch real DB state. The script only reminds the user.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$REPO_ROOT"

echo "==> [1/3] composer install"
composer install --no-interaction

echo ""
echo "==> [2/3] npm install (frontend)"
(cd frontend && npm install --no-audit --no-fund)

echo ""
echo "==> [3/3] npm run build (frontend)"
(cd frontend && npm run build)

echo ""
echo "==> Done. Pokud se měnily definice tabulek nebo cfgItems modulů,"
echo "    upgraduj všechny datové zdroje:"
echo ""
echo "        sudo shpd-server ds-upgrade-all"
echo ""
