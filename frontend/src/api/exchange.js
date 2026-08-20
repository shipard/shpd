/**
 * API helpers for the canonical document exchange flow.
 *
 * Wraps the message-centric `/_mail/messages/{ndx}/{preview|apply|reject|unapply}`
 * endpoints — the document proposal lives on the message's latest successful
 * analysis (tasks/mail-message-centric.md). Server injects `source.message`
 * and `applyOptions`, tolerates invalid AI output by returning a wrapper
 * payload.
 *
 * Direct callers of `/_exchange/docs/document/{validate,preview,apply}`
 * (e.g. external integrations) should not use this module; it's tailored
 * to the AnalysisController-mediated UI flow.
 */

import { get, post } from './client.js';
import { API_BASE_URL } from './config.js';

/**
 * Fetch a read-only preview of the message's document proposal (latest
 * successful analysis).
 *
 * @param {number} messageNdx
 * @returns {Promise<{
 *   success: boolean,
 *   data?: {
 *     aiFailed: boolean,
 *     canonical?: object,
 *     wrapper?: object,
 *     attachments: Array<{ndx: number, filename: string, mime_type: string, size_bytes: number}>,
 *     messageNdx: number,
 *     analysisNdx: number,
 *     proposedType: string|null,
 *     confidence: number|null,
 *     resolution: number|null,
 *     target?: string,
 *   },
 *   error?: object,
 * }>}
 */
export async function previewMessage(messageNdx) {
  return await get(`/_mail/messages/${messageNdx}/preview`);
}

/**
 * Apply the message's document proposal.
 *
 * Body shape and backend behaviour:
 *   - `userActions = null` → POST body `{}`. Backend derives
 *     `autoCreateMode = "safe"` (one-click apply from the feed card).
 *   - `userActions = {}` (empty object) → POST body `{_resolve: {}}`.
 *     Backend sees the `_resolve` key and switches to
 *     `autoCreateMode = "strict"` — review-aware client with no decisions
 *     (every reference must already be `matched`).
 *   - `userActions = {path: action, ...}` → POST body `{_resolve: ...}`.
 *     Backend expands flat paths to nested `_resolve.{path}.userAction`,
 *     mode = strict.
 *
 * userActions tvar: `{"supplier": "useExisting:42", "rows[0].item": "create"}`.
 * Backend (MessageProposalApplier::expandUserActions) handles the flat →
 * nested translation.
 *
 * `applyOptions` passes through to the canonical apply verbatim. The only
 * client-driven key is `targetDocState`: 40 = „Vystavit a uzavřít“ (document
 * created directly in V pořádku — number assigned, accounted), default 10
 * (Koncept). `autoCreateMode` keeps being derived server-side from the
 * presence of `_resolve` — never send it here.
 *
 * @param {number} messageNdx
 * @param {object|null} [userActions]  Flat {path: action} map, or null
 *                                      for safe one-click apply.
 * @param {object|null} [applyOptions] e.g. {targetDocState: 40}, or null
 *                                      for server defaults.
 */
export async function applyMessage(messageNdx, userActions = null, applyOptions = null) {
  const body = {};
  if (userActions !== null) body._resolve = userActions;
  if (applyOptions !== null) body.applyOptions = applyOptions;
  return await post(`/_mail/messages/${messageNdx}/apply`, body);
}

/**
 * Reject the message's document proposal.
 *
 * @param {number} messageNdx
 * @param {string} reason  Required, non-empty
 */
export async function rejectMessage(messageNdx, reason) {
  return await post(`/_mail/messages/${messageNdx}/reject`, { reason });
}

/**
 * Undo an apply — trashes the created entity, clears the message lineage
 * and reopens the proposal (resolution → NULL). No UI — MCP / manual
 * escape hatch. Backend guards: applied proposal with an untouched docs
 * draft target (docState=10) / unmodified registry document, else 409.
 *
 * @param {number} messageNdx
 * @returns {Promise<{success: boolean, data?: {messageNdx: number, analysisNdx: number, trashedDocId: number}, error?: object}>}
 */
export async function unapplyMessage(messageNdx) {
  return await post(`/_mail/messages/${messageNdx}/unapply`, {});
}

/**
 * Re-run AI analysis for a message (dashboard feed urgent card „Znovu
 * analyzovat"). Re-queues the message; the latest run stays in history.
 *
 * @param {number} messageNdx
 * @param {number|null} [profileOverrideNdx]  Optional AI profile override.
 */
export async function reanalyzeMessage(messageNdx, profileOverrideNdx = null) {
  const body = profileOverrideNdx ? { profile_override_ndx: profileOverrideNdx } : {};
  return await post(`/_mail/messages/${messageNdx}/reanalyze`, body);
}

/**
 * Build a download URL for an attachment. With `inline=true` the URL
 * carries `?inline=1` so the browser renders the file inline (PDF embed
 * / image preview). The backend allowlist limits inline to PDF and
 * `image/*` regardless of the query — see AttachmentController.
 *
 * @param {number} attachmentNdx
 * @param {boolean} [inline]
 * @returns {string}
 */
export function attachmentUrl(attachmentNdx, inline = false) {
  const suffix = inline ? '?inline=1' : '';
  return `${API_BASE_URL}/_attachments/${attachmentNdx}/download${suffix}`;
}
