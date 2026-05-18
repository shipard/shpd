/**
 * API helpers for the alerts subsystem.
 *
 * Backend endpoints (see `src/Api/Controller/AlertsController.php`):
 *   GET   /_alerts/registry
 *   POST  /_alerts/checks/{checkId}/run
 *   POST  /_alerts/alerts/{id}/snooze
 *   POST  /_alerts/alerts/{id}/dismiss
 *   POST  /_alerts/alerts/{id}/unsnooze
 *
 * Alerty samotné se čtou jako jakákoliv jiná tabulka přes /_ui/viewer
 * (viewer ID `core.alerts.alerts`). Tyhle helpery slouží jen pro
 * lifecycle akce a meta info, ne pro list.
 */

import { get, post } from './client.js';

/**
 * Seznam zaregistrovaných checků + jejich runtime info (last run, next run,
 * findings count, error message).
 */
export async function listAlertRegistry() {
  return await get('/_alerts/registry');
}

/**
 * Synchronní re-run jednoho checku. Vrací AlertRunResult + aktuální seznam
 * open alertů z toho checku.
 */
export async function runAlertCheck(checkId) {
  return await post(`/_alerts/checks/${encodeURIComponent(checkId)}/run`, {});
}

/**
 * Spustí všechny due (a dosud nikdy nespuštěné) enabled checky. Vrací
 * souhrnný počet (checksRun, totalFindings, newFindings, stats per status,
 * results per check). Analog `shpd-ds alerts-run` bez argumentů.
 */
export async function runDueAlertChecks() {
  return await post('/_alerts/run-due', {});
}

/**
 * Odložit alert.
 *
 * `duration` může být:
 *   - ISO 8601: "PT1H", "P7D"
 *   - shipard suffix: "1h", "30m", "7d"
 *
 * Alternativně lze místo `duration` poslat `{hours: N}`, `{days: N}` nebo
 * `{minutes: N}` — viz backend AlertsController::parseDurationSeconds.
 *
 * Server: minimum 5 minut, maximum 365 dní.
 */
export async function snoozeAlert(alertId, duration) {
  const body = typeof duration === 'string' ? { duration } : duration;
  return await post(`/_alerts/alerts/${alertId}/snooze`, body);
}

/** Trvale (= terminálně) zamítnout alert. Reconciler ho znovu nevzkřísí — */
/* — udělá nový řádek se stejným check_id+finding_key, pokud problém přetrvá. */
export async function dismissAlert(alertId) {
  return await post(`/_alerts/alerts/${alertId}/dismiss`, {});
}

/** Vrátit snooznutý alert zpět na Active. */
export async function unsnoozeAlert(alertId) {
  return await post(`/_alerts/alerts/${alertId}/unsnooze`, {});
}

/** Předdefinovaná snooze doby pro UI dropdown — viz spec §9. */
export const SNOOZE_PRESETS = [
  { label: '1 h',  duration: 'PT1H' },
  { label: '4 h',  duration: 'PT4H' },
  { label: '1 den', duration: 'P1D' },
  { label: '1 týden', duration: 'P7D' },
];
