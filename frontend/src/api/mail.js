/**
 * API helpers for incoming-mail noise handling (sender rules + auto-archive
 * digest, Fáze 3). Dashboard feed cards call these from Dashboard.svelte.
 */

import { post } from './client.js';

/**
 * Confirm a suggested sender rule (Koncept 10 → 40). The rule starts
 * auto-archiving matching senders on ingest.
 *
 * @param {number} ruleId
 * @returns {Promise<{success: boolean, data?: {id: number, docState: number}, error?: object}>}
 */
export async function confirmSenderRule(ruleId) {
  return await post(`/_mail/sender-rules/${ruleId}/confirm`, {});
}

/**
 * Reject a suggested sender rule (Koncept 10 → 90).
 *
 * @param {number} ruleId
 */
export async function rejectSenderRule(ruleId) {
  return await post(`/_mail/sender-rules/${ruleId}/reject`, {});
}

/**
 * Undo the day's auto-archive: restores messages auto-disposed on `date`
 * (default today; backend allows today/yesterday only) back to Nová (10),
 * re-queues analysis and clears the audit columns.
 *
 * @param {string|null} [date]  YYYY-MM-DD
 * @returns {Promise<{success: boolean, data?: {restored: number}, error?: object}>}
 */
export async function undoAutoArchive(date = null) {
  return await post('/_mail/auto-archive/undo', date ? { date } : {});
}
