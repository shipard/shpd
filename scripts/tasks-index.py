#!/usr/bin/env python3
"""Generátor souhrnu stavů tasků do tasks/README.md.

Zdroj pravdy je řádek `**Stav:** <stav>[ — poznámka]` v hlavičce každého
task filu (v prvních 10 řádcích, hned za nadpisem H1). Tento skript z nich
sestaví souhrnnou tabulku a vloží ji do tasks/README.md mezi značky
STAV:BEGIN / STAV:END.

Použití:
    python3 scripts/tasks-index.py           # přepíše blok v tasks/README.md
    python3 scripts/tasks-index.py --check   # jen ověří, exit 1 když nesedí

Volá se z git hooku (pre-commit) v režimu --check.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
TASKS_DIR = REPO_ROOT / "tasks"
INDEX_FILE = TASKS_DIR / "README.md"

BEGIN = "<!-- STAV:BEGIN — generováno scripts/tasks-index.py, needituj ručně -->"
END = "<!-- STAV:END -->"

# Kanonické stavy v pořadí, v jakém se vypisují.
STATUSES = ["naplánováno", "částečně", "zrušeno", "hotovo"]

# Soubory, které nejsou tasky.
SKIP = {"README.md", "TODO.md"}

STAV_RE = re.compile(r"^\*\*Stav:\*\*\s*(.+?)\s*$")


def parse_status(path: Path) -> tuple[str, str] | None:
    """Vrátí (stav, poznámka) z hlavičky tasku, nebo None když chybí."""
    with path.open(encoding="utf-8") as fh:
        for i, line in enumerate(fh):
            if i >= 10:
                break
            m = STAV_RE.match(line)
            if not m:
                continue
            raw = m.group(1)
            if " — " in raw:
                status, note = raw.split(" — ", 1)
            else:
                status, note = raw, ""
            return status.strip(), note.strip()
    return None


def collect() -> tuple[dict[str, list[tuple[str, str]]], list[str]]:
    buckets: dict[str, list[tuple[str, str]]] = {s: [] for s in STATUSES}
    missing: list[str] = []
    for path in sorted(TASKS_DIR.glob("*.md")):
        if path.name in SKIP:
            continue
        parsed = parse_status(path)
        if parsed is None:
            missing.append(path.name)
            continue
        status, note = parsed
        if status not in buckets:
            missing.append(f"{path.name} (neznámý stav: {status})")
            continue
        buckets[status].append((path.name, note))
    return buckets, missing


def render(buckets, missing) -> str:
    total = sum(len(v) for v in buckets.values())
    lines = [BEGIN, ""]
    lines.append("## Stav")
    lines.append("")
    counts = " · ".join(
        f"**{s}** {len(buckets[s])}" for s in STATUSES if buckets[s]
    )
    lines.append(f"Celkem {total} tasků: {counts}.")
    lines.append("")
    lines.append(
        "Zdroj pravdy je řádek `**Stav:**` v hlavičce každého tasku; tato"
    )
    lines.append(
        "tabulka je generovaná (`scripts/tasks-index.py`). Hotové tasky se"
    )
    lines.append("nevypisují — níže je jen to, co není dokončené.")
    lines.append("")

    open_statuses = [s for s in STATUSES if s != "hotovo" and buckets[s]]
    if not open_statuses:
        lines.append("Všechny tasky jsou hotové.")
    else:
        lines.append("| Task | Stav | Poznámka |")
        lines.append("|------|------|----------|")
        for status in open_statuses:
            for name, note in sorted(buckets[status]):
                lines.append(f"| `{name}` | {status} | {note} |")
    lines.append("")

    if missing:
        lines.append("**Bez hlavičky `**Stav:**` nebo s neznámým stavem:**")
        lines.append("")
        for name in missing:
            lines.append(f"- `{name}`")
        lines.append("")

    lines.append(END)
    return "\n".join(lines)


def splice(original: str, block: str) -> str:
    if BEGIN in original and END in original:
        pre = original.split(BEGIN)[0]
        post = original.split(END, 1)[1]
        return pre + block + post
    # První spuštění — vloží blok za úvodní odstavce, před první "---".
    marker = "\n---\n"
    idx = original.find(marker)
    if idx == -1:
        return original.rstrip() + "\n\n" + block + "\n"
    return (
        original[: idx + len(marker)]
        + "\n"
        + block
        + "\n"
        + original[idx + len(marker) :]
    )


def main() -> int:
    check = "--check" in sys.argv
    buckets, missing = collect()
    block = render(buckets, missing)
    original = INDEX_FILE.read_text(encoding="utf-8")
    updated = splice(original, block)

    if check:
        if updated != original:
            print(
                "tasks/README.md není aktuální — spusť "
                "`python3 scripts/tasks-index.py`",
                file=sys.stderr,
            )
            return 1
        if missing:
            print(
                "Tasky bez hlavičky `**Stav:**`: " + ", ".join(missing),
                file=sys.stderr,
            )
            return 1
        return 0

    if updated != original:
        INDEX_FILE.write_text(updated, encoding="utf-8")
        print("tasks/README.md aktualizován.")
    else:
        print("tasks/README.md je aktuální.")
    if missing:
        print("Pozor — tasky bez hlavičky: " + ", ".join(missing))
    return 0


if __name__ == "__main__":
    sys.exit(main())
