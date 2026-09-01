/**
 * API helpers for the reports endpoints (tasks/reports-phase3.md, docs/reports.md):
 *   GET /_reports              — catalog of declared reports + fiscal periods
 *   GET /_reports/{reportId}   — run a report; query = params
 *                                (fiscalYear, monthFrom, monthTo, detail)
 *
 * Result with `status: errors` is HTTP 200 — a data error is not a request
 * error; the renderer shows it (badge + red rows), it is not a fetch failure.
 */

import { get } from './client.js';

/**
 * @returns {Promise<{success: boolean, data?: {items: Array<{id: string, name: string,
 *   periodGranularities: string[], params: Array<object>}>,
 *   periods: {fiscalYears: Array<{name: string, months: number}>}}, error?: object}>}
 */
export async function fetchReportCatalog() {
  return await get('/_reports');
}

/**
 * Query se staví jen z definovaných klíčů — report s periodSource
 * 'vatPeriod' posílá vatRegistration+dateFrom/dateTo, fiskální report
 * fiscalYear+monthFrom/monthTo; `detail` jen když ho report deklaruje
 * (server by neznámý parametr odmítl jako 400).
 *
 * @param {string} reportId
 * @param {{fiscalYear?: string, monthFrom?: number, monthTo?: number,
 *   vatRegistration?: number, dateFrom?: string, dateTo?: string, detail?: string}} params
 * @returns {Promise<{success: boolean, data?: object, error?: object}>} data = ReportResult
 */
export async function runReport(reportId, params) {
  const entries = params.vatRegistration != null
    ? {
        vatRegistration: String(params.vatRegistration),
        dateFrom: params.dateFrom,
        dateTo: params.dateTo,
      }
    : {
        fiscalYear: params.fiscalYear,
        monthFrom: String(params.monthFrom),
        monthTo: String(params.monthTo),
      };
  if (params.detail !== undefined) entries.detail = params.detail;
  const query = new URLSearchParams(entries);
  return await get(`/_reports/${encodeURIComponent(reportId)}?${query}`);
}

/**
 * Deep-link reportu z query stringu (`?report=<id>&fy=<rok>&mf=<od>&mt=<do>
 * &detail=<d>`, u vatPeriod reportů `&reg=<id>&df=<od>&dt=<do>`) — čistý
 * parser jako parseAuthAction. Bez `report` → null; jednotlivá nevalidní
 * pole se zahodí (doplní je default v ReportsPage). „V tisících" do URL
 * nepatří (čistě vizuální volba).
 *
 * @param {string} search window.location.search
 * @returns {{reportId: string, params: {fiscalYear?: string, monthFrom?: number,
 *   monthTo?: number, vatRegistration?: number, dateFrom?: string,
 *   dateTo?: string, detail?: string}}|null}
 */
export function parseReportDeepLink(search) {
  const query = new URLSearchParams(search);
  const reportId = query.get('report');
  if (!reportId) return null;

  const params = {};
  const fy = query.get('fy');
  if (fy) params.fiscalYear = fy;
  for (const [key, name] of [['mf', 'monthFrom'], ['mt', 'monthTo']]) {
    const raw = query.get(key);
    const value = Number.parseInt(raw ?? '', 10);
    if (Number.isInteger(value) && value >= 1 && value <= 12) params[name] = value;
  }
  const reg = Number.parseInt(query.get('reg') ?? '', 10);
  if (Number.isInteger(reg) && reg >= 1) params.vatRegistration = reg;
  for (const [key, name] of [['df', 'dateFrom'], ['dt', 'dateTo']]) {
    const raw = query.get(key);
    if (raw && /^\d{4}-\d{2}-\d{2}$/.test(raw)) params[name] = raw;
  }
  const detail = query.get('detail');
  if (detail === 'analytic' || detail === 'synthetic') params.detail = detail;

  return { reportId, params };
}

/**
 * Výchozí období = poslední celý měsíc existujícího fiskálního roku.
 * Fiskální roky v1 jsou zarovnané na kalendář (name = kalendářní rok) —
 * bez mapy fiskální↔kalendářní měsíc bereme pořadí měsíce v roce jako
 * kalendářní měsíc; u ne-kalendářního roku degraduje na poslední měsíc.
 *
 * @param {Array<{name: string, months: number}>} fiscalYears (řazené dle name)
 * @param {Date} [now]
 * @returns {{fiscalYear: string, monthFrom: number, monthTo: number}|null} null bez fiskálních roků
 */
export function defaultPeriod(fiscalYears, now = new Date()) {
  if (!Array.isArray(fiscalYears) || fiscalYears.length === 0) return null;
  const currentYear = now.getFullYear();
  const candidates = fiscalYears.filter((y) => Number(y.name) <= currentYear);
  const year = (candidates.length > 0 ? candidates : fiscalYears).at(-1);
  const month = Number(year.name) === currentYear
    ? Math.min(Math.max(now.getMonth(), 1), year.months) // getMonth() 0-based → minulý měsíc
    : year.months;
  return { fiscalYear: String(year.name), monthFrom: month, monthTo: month };
}

/**
 * Výchozí období DPH = poslední uzavřené (dateEnd < dnes) období první
 * registrace; bez uzavřeného období poslední existující.
 *
 * @param {Array<{id: number, name: string,
 *   periods: Array<{id: number, name: string, dateBegin: string, dateEnd: string, locked: boolean}>}>} registrations
 * @param {Date} [now]
 * @returns {{vatRegistration: number, dateFrom: string, dateTo: string}|null}
 */
export function defaultVatPeriod(registrations, now = new Date()) {
  if (!Array.isArray(registrations) || registrations.length === 0) return null;
  const registration = registrations[0];
  const periods = registration.periods ?? [];
  if (periods.length === 0) return null;
  const today = now.toISOString().slice(0, 10);
  const closed = periods.filter((p) => p.dateEnd < today);
  const period = (closed.length > 0 ? closed : periods).at(-1);
  return { vatRegistration: registration.id, dateFrom: period.dateBegin, dateTo: period.dateEnd };
}
