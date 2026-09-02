#!/usr/bin/env python3
"""Generátor fiktivních přijatých faktur (PDF) pro demo videa.

Zadání: tasks/demo-invoices.md. Načte identity ze suppliers.jsonc a data
faktur z data/*.jsonc, spočítá DPH a součty (Decimal, ROUND_HALF_UP),
ověří kontrolní součty identifikátorů, vyrenderuje HTML ze šablony
(templates/{a,b,c}.html, placeholdery {{key}}) a přes Gotenberg
(Chromium, docs/render.md) uloží out/NNNN-<slug>.pdf.

Pouze stdlib. Použití:

    python3 demo/invoices/build.py

Env: GOTENBERG_URL (default http://10.199.6.210:3000).
Exit != 0 = kterýkoli assert nebo render selhal.
"""

from __future__ import annotations

import html
import json
import os
import re
import sys
import urllib.error
import urllib.request
import uuid
from datetime import date
from decimal import Decimal, ROUND_HALF_UP
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent
GOTENBERG_URL = os.environ.get("GOTENBERG_URL", "http://10.199.6.210:3000")

NNBSP = "\u202f"  # úzká nezlomitelná mezera pro tisíce
CENTS = Decimal("0.01")
WHOLE = Decimal("1")

UNIT_DISPLAY = {"m2": "m²", "měs": "měs."}

VALID_VAT_PCTS = {0, 12, 21}


# ── JSONC ────────────────────────────────────────────────────────────────────

def _strip_comments(text: str) -> str:
    out: list[str] = []
    in_string = False
    escape = False
    i = 0
    while i < len(text):
        ch = text[i]
        if in_string:
            out.append(ch)
            if escape:
                escape = False
            elif ch == "\\":
                escape = True
            elif ch == '"':
                in_string = False
            i += 1
            continue
        if ch == '"':
            in_string = True
            out.append(ch)
            i += 1
            continue
        if ch == "/" and i + 1 < len(text) and text[i + 1] == "/":
            while i < len(text) and text[i] != "\n":
                i += 1
            continue
        out.append(ch)
        i += 1
    return "".join(out)


def _strip_trailing_commas(text: str) -> str:
    out: list[str] = []
    in_string = False
    escape = False
    for i, ch in enumerate(text):
        if in_string:
            out.append(ch)
            if escape:
                escape = False
            elif ch == "\\":
                escape = True
            elif ch == '"':
                in_string = False
            continue
        if ch == '"':
            in_string = True
            out.append(ch)
            continue
        if ch == ",":
            j = i + 1
            while j < len(text) and text[j] in " \t\r\n":
                j += 1
            if j < len(text) and text[j] in "}]":
                continue  # trailing čárka — vypustit
        out.append(ch)
    return "".join(out)


def load_jsonc(path: Path) -> dict:
    text = _strip_trailing_commas(_strip_comments(path.read_text(encoding="utf-8")))
    return json.loads(text, parse_float=Decimal)


# ── Kontrolní součty ─────────────────────────────────────────────────────────

def ico_valid(ico: str) -> bool:
    if not re.fullmatch(r"\d{8}", ico):
        return False
    total = sum(int(d) * w for d, w in zip(ico[:7], range(8, 1, -1)))
    return (11 - total % 11) % 10 == int(ico[7])


def _mod11(digits: str) -> bool:
    # váha číslice = 2^i mod 11, i od pravého kraje
    weights = [1, 2, 4, 8, 5, 10, 9, 7, 3, 6]
    total = sum(int(d) * weights[i] for i, d in enumerate(reversed(digits)))
    return total % 11 == 0


def account_valid(account: str) -> bool:
    m = re.fullmatch(r"(?:(\d{2,6})-)?(\d{2,10})/(\d{4})", account)
    if not m:
        return False
    prefix, number, _bank = m.groups()
    if prefix is not None and not _mod11(prefix):
        return False
    return _mod11(number)


def iban_valid(iban: str) -> bool:
    if not re.fullmatch(r"[A-Z]{2}\d{2}[A-Z0-9]+", iban):
        return False
    rearranged = iban[4:] + iban[:4]
    numeric = "".join(str(int(c, 36)) for c in rearranged)
    return int(numeric) % 97 == 1


def iban_matches_account(iban: str, account: str) -> bool:
    m = re.fullmatch(r"(?:(\d{2,6})-)?(\d{2,10})/(\d{4})", account)
    if not m:
        return False
    prefix, number, bank = m.groups()
    bban = bank + (prefix or "").zfill(6) + number.zfill(10)
    return iban.startswith("CZ") and iban[4:] == bban


# ── Formátování ──────────────────────────────────────────────────────────────

def fmt_money(value: Decimal) -> str:
    s = f"{value.quantize(CENTS, rounding=ROUND_HALF_UP):,.2f}"
    return s.replace(",", NNBSP).replace(".", ",")


def fmt_qty(value: Decimal) -> str:
    normalized = value.normalize()
    if normalized == normalized.to_integral_value():
        return str(int(normalized))
    return format(normalized, "f").replace(".", ",")


def fmt_date(iso: str) -> str:
    d = date.fromisoformat(iso)
    return f"{d.day}. {d.month}. {d.year}"


def fmt_iban(iban: str) -> str:
    return " ".join(iban[i:i + 4] for i in range(0, len(iban), 4))


# ── Výpočty ──────────────────────────────────────────────────────────────────

def compute(invoice: dict) -> dict:
    """Per-řádek totály, rekapitulace po sazbách, celkové součty."""
    rows = []
    for row in invoice["rows"]:
        qty = Decimal(str(row["qty"]))
        unit_price = Decimal(str(row["unitPrice"]))
        vat_pct = int(row["vatPct"])
        assert vat_pct in VALID_VAT_PCTS, f"neznámá sazba DPH {vat_pct}"
        assert qty > 0, f"neplatné množství {qty}"
        total = (qty * unit_price).quantize(CENTS, rounding=ROUND_HALF_UP)
        rows.append({**row, "qty": qty, "unitPrice": unit_price,
                     "vatPct": vat_pct, "total": total})

    recap: dict[int, dict] = {}
    for row in rows:
        r = recap.setdefault(row["vatPct"], {"base": Decimal("0")})
        r["base"] += row["total"]
    for pct, r in recap.items():
        r["tax"] = (r["base"] * pct / 100).quantize(CENTS, rounding=ROUND_HALF_UP)
        r["total"] = r["base"] + r["tax"]

    total_base = sum(r["base"] for r in recap.values())
    total_vat = sum(r["tax"] for r in recap.values())
    computed_total = total_base + total_vat

    rounding_mode = invoice.get("rounding", "none")
    if rounding_mode == "czk":
        total_amount = computed_total.quantize(WHOLE, rounding=ROUND_HALF_UP)
        total_rounding = total_amount - computed_total
        assert CENTS < abs(total_rounding) < WHOLE, (
            f"zaokrouhlení {total_rounding} mimo pásmo (0,01; 1,00) — "
            f"derivace total_rounding_mode by nezabrala, uprav částky"
        )
    elif rounding_mode == "none":
        total_amount = computed_total
        total_rounding = Decimal("0")
    else:
        raise AssertionError(f"neznámý rounding: {rounding_mode}")

    # Integrita: Σ řádků == Σ rekapitulace == totals
    assert sum(r["total"] for r in rows) == total_base
    assert sum(r["base"] for r in recap.values()) == total_base
    assert sum(r["total"] for r in recap.values()) == computed_total
    for pct, r in recap.items():
        assert r["base"] + r["tax"] == r["total"]
        assert r["tax"] == (r["base"] * pct / 100).quantize(CENTS, rounding=ROUND_HALF_UP)

    return {
        "rows": rows,
        "recap": dict(sorted(recap.items(), reverse=True)),
        "totalBase": total_base,
        "totalVat": total_vat,
        "totalAmount": total_amount,
        "totalRounding": total_rounding,
    }


# ── Validace identit a hlavičky ──────────────────────────────────────────────

def validate_party(name: str, party: dict, with_bank: bool) -> None:
    assert ico_valid(party["ico"]), f"{name}: IČO {party['ico']} nemá validní kontrolní součet"
    assert party["dic"] == "CZ" + party["ico"], f"{name}: DIČ nesedí na IČO"
    if with_bank:
        assert account_valid(party["account"]), f"{name}: účet {party['account']} nesedí na mod-11"
        assert iban_valid(party["iban"]), f"{name}: IBAN {party['iban']} nemá validní check digits"
        assert iban_matches_account(party["iban"], party["account"]), \
            f"{name}: IBAN neodpovídá číslu účtu"


def validate_header(invoice: dict) -> None:
    vs = invoice["variableSymbol"]
    assert re.fullmatch(r"\d{1,10}", vs), f"VS '{vs}' — jen číslice, max 10"
    assert invoice["docNumber"], "chybí docNumber"
    assert invoice["currency"] == "CZK", "sada je CZK-only"
    for key in ("issue", "tax", "due"):
        date.fromisoformat(invoice["dates"][key])  # ValueError = nevalidní


# ── HTML ─────────────────────────────────────────────────────────────────────

def esc(value: str) -> str:
    return html.escape(str(value), quote=False)


def render_rows(computed: dict) -> str:
    parts = []
    for row in computed["rows"]:
        unit = UNIT_DISPLAY.get(row["unit"], row["unit"])
        parts.append(
            '\t\t\t<tr class="row">'
            f'<td class="c-text">{esc(row["text"])}</td>'
            f'<td class="c-qty">{fmt_qty(row["qty"])}</td>'
            f'<td class="c-unit">{esc(unit)}</td>'
            f'<td class="c-price">{fmt_money(row["unitPrice"])}</td>'
            f'<td class="c-vat">{row["vatPct"]}{NNBSP}%</td>'
            f'<td class="c-total">{fmt_money(row["total"])}</td>'
            "</tr>"
        )
    return "\n".join(parts)


def render_vat_recap(computed: dict) -> str:
    parts = []
    for pct, r in computed["recap"].items():
        parts.append(
            '\t\t\t<tr class="vr">'
            f'<td class="v-pct">{pct}{NNBSP}%</td>'
            f'<td class="v-base">{fmt_money(r["base"])}</td>'
            f'<td class="v-tax">{fmt_money(r["tax"])}</td>'
            f'<td class="v-total">{fmt_money(r["total"])}</td>'
            "</tr>"
        )
    return "\n".join(parts)


def render_html(template: str, values: dict[str, str]) -> str:
    def repl(match: re.Match) -> str:
        key = match.group(1)
        if key not in values:
            raise KeyError(f"šablona chce neznámý placeholder {{{{{key}}}}}")
        return values[key]

    rendered = re.sub(r"\{\{(\w+)\}\}", repl, template)
    assert "{{" not in rendered, "v HTML zůstal nenahrazený placeholder"
    return rendered


def build_values(invoice: dict, supplier: dict, own: dict, computed: dict) -> dict[str, str]:
    rounding_row = ""
    if computed["totalRounding"] != 0:
        rounding_row = (
            f"<tr><th>Zaokrouhlení</th><td>{fmt_money(computed['totalRounding'])}"
            f"{NNBSP}Kč</td></tr>"
        )
    return {
        "supplierName": esc(supplier["name"]),
        "supplierStreet": esc(supplier["street"]),
        "supplierZipCity": esc(f"{supplier['zip']} {supplier['city']}"),
        "supplierIco": supplier["ico"],
        "supplierDic": supplier["dic"],
        "supplierEmail": esc(supplier["email"]),
        "supplierCourtReg": esc(supplier["courtReg"]),
        "supplierAccount": supplier["account"],
        "supplierIban": fmt_iban(supplier["iban"]),
        "customerName": esc(own["name"]),
        "customerStreet": esc(own["street"]),
        "customerZipCity": esc(f"{own['zip']} {own['city']}"),
        "customerIco": own["ico"],
        "customerDic": own["dic"],
        "docNumber": esc(invoice["docNumber"]),
        "vs": invoice["variableSymbol"],
        "issueDate": fmt_date(invoice["dates"]["issue"]),
        "taxDate": fmt_date(invoice["dates"]["tax"]),
        "dueDate": fmt_date(invoice["dates"]["due"]),
        "rows": render_rows(computed),
        "vatRecap": render_vat_recap(computed),
        "totalBase": fmt_money(computed["totalBase"]),
        "totalVat": fmt_money(computed["totalVat"]),
        "roundingRow": rounding_row,
        "totalAmount": fmt_money(computed["totalAmount"]),
        "note": esc(invoice["notes"]["onDocument"]),
        "accent": supplier.get("accent", "#0e7490"),
    }


# ── Gotenberg ────────────────────────────────────────────────────────────────

# A4 rozměrově — jmenované formáty Gotenberg nezná (docs/render.md).
# Okraje 0, vnitřní okraje řeší CSS šablon (accent bar šablony a smí k hraně).
GOTENBERG_FIELDS = {
    "paperWidth": "8.27",
    "paperHeight": "11.7",
    "marginTop": "0",
    "marginBottom": "0",
    "marginLeft": "0",
    "marginRight": "0",
    "printBackground": "true",
}


def render_pdf(html_content: str) -> bytes:
    boundary = "----shpd-demo-" + uuid.uuid4().hex
    parts: list[bytes] = []
    for name, value in GOTENBERG_FIELDS.items():
        parts.append(
            (f"--{boundary}\r\n"
             f'Content-Disposition: form-data; name="{name}"\r\n\r\n'
             f"{value}\r\n").encode("utf-8")
        )
    # Hlavní HTML MUSÍ mít jméno index.html, jinak Gotenberg vrátí 400.
    parts.append(
        (f"--{boundary}\r\n"
         'Content-Disposition: form-data; name="files"; filename="index.html"\r\n'
         "Content-Type: text/html\r\n\r\n").encode("utf-8")
        + html_content.encode("utf-8")
        + b"\r\n"
    )
    parts.append(f"--{boundary}--\r\n".encode("utf-8"))
    body = b"".join(parts)

    request = urllib.request.Request(
        f"{GOTENBERG_URL}/forms/chromium/convert/html",
        data=body,
        headers={"Content-Type": f"multipart/form-data; boundary={boundary}"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            pdf = response.read()
    except urllib.error.HTTPError as e:
        detail = e.read().decode("utf-8", errors="replace")[:500]
        raise RuntimeError(f"Gotenberg HTTP {e.code}: {detail}") from e
    except urllib.error.URLError as e:
        raise RuntimeError(f"Gotenberg nedostupný na {GOTENBERG_URL}: {e.reason}") from e

    assert pdf.startswith(b"%PDF-"), "odpověď není PDF"
    return pdf


# ── Main ─────────────────────────────────────────────────────────────────────

def main() -> int:
    identities = load_jsonc(BASE_DIR / "suppliers.jsonc")
    own = identities["ownCompany"]
    validate_party("ownCompany", own, with_bank=False)
    for key, supplier in identities["suppliers"].items():
        validate_party(key, supplier, with_bank=True)

    templates = {
        p.stem: p.read_text(encoding="utf-8")
        for p in sorted((BASE_DIR / "templates").glob("*.html"))
    }

    data_files = sorted((BASE_DIR / "data").glob("*.jsonc"))
    assert data_files, "data/*.jsonc nenalezena"
    out_dir = BASE_DIR / "out"
    out_dir.mkdir(exist_ok=True)

    for path in data_files:
        invoice = load_jsonc(path)
        validate_header(invoice)
        supplier = identities["suppliers"][invoice["supplier"]]
        computed = compute(invoice)

        template = templates[invoice["template"]]
        rendered = render_html(template, build_values(invoice, supplier, own, computed))
        pdf = render_pdf(rendered)

        out_path = out_dir / (path.stem + ".pdf")
        out_path.write_bytes(pdf)

        rounding_note = (
            f", zaokrouhlení {fmt_money(computed['totalRounding'])}"
            if computed["totalRounding"] != 0 else ""
        )
        print(
            f"{path.stem}: základ {fmt_money(computed['totalBase'])} "
            f"+ DPH {fmt_money(computed['totalVat'])}{rounding_note} "
            f"= {fmt_money(computed['totalAmount'])} Kč "
            f"→ {out_path.relative_to(BASE_DIR)} ({len(pdf) // 1024} kB)"
        )

    print(f"OK: {len(data_files)} PDF v {out_dir.relative_to(Path.cwd()) if out_dir.is_relative_to(Path.cwd()) else out_dir}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
