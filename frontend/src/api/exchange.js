/**
 * API helpers for the canonical document exchange flow.
 *
 * Wraps the `/_mail/extracted-documents/{ndx}/{preview|apply}` endpoints
 * — symmetric, server-injects `source.extractedDoc` and `applyOptions`,
 * tolerates `ai_failed` rows by returning a wrapper payload.
 *
 * Direct callers of `/_exchange/docs/document/{validate,preview,apply}`
 * (e.g. external integrations) should not use this module; it's tailored
 * to the AnalysisController-mediated UI flow.
 */

import { post } from './client.js';
import { API_BASE_URL } from './config.js';

/**
 * Fetch a read-only preview of an extracted document.
 *
 * @param {number} extractedNdx
 * @returns {Promise<{
 *   success: boolean,
 *   data?: {
 *     aiFailed: boolean,
 *     canonical?: object,
 *     wrapper?: object,
 *     attachments: Array<{ndx: number, filename: string, mime_type: string, size_bytes: number}>,
 *     extractedNdx: number,
 *     messageNdx: number,
 *     status: number,
 *   },
 *   error?: object,
 * }>}
 */
export async function previewExtractedDocument(extractedNdx) {
  return await post(`/_mail/extracted-documents/${extractedNdx}/preview`, {});
}

/**
 * Apply an extracted document.
 *
 * Body shape and backend behaviour:
 *   - `userActions = null` → POST body `{}`. Backend derives
 *     `autoCreateMode = "safe"` (Phase 2 / CLI backward compat).
 *   - `userActions = {}` (empty object) → POST body `{_resolve: {}}`.
 *     Backend sees the `_resolve` key and switches to
 *     `autoCreateMode = "strict"` — 3b-aware client with no decisions
 *     (every reference must already be `matched`).
 *   - `userActions = {path: action, ...}` → POST body `{_resolve: ...}`.
 *     Backend expands flat paths to nested `_resolve.{path}.userAction`,
 *     mode = strict.
 *
 * userActions tvar: `{"supplier": "useExisting:42", "rows[0].item": "create"}`.
 * Backend (AnalysisController::expandUserActions) handles the flat → nested
 * translation.
 *
 * @param {number} extractedNdx
 * @param {object|null} [userActions]  Flat {path: action} map, or null
 *                                      for Phase 2 / non-3b callers.
 */
export async function applyExtractedDocument(extractedNdx, userActions = null) {
  const body = userActions !== null ? { _resolve: userActions } : {};
  return await post(`/_mail/extracted-documents/${extractedNdx}/apply`, body);
}

/**
 * Reject an extracted document — UI flow Phase 2.
 *
 * @param {number} extractedNdx
 * @param {string} reason  Required, non-empty
 */
export async function rejectExtractedDocument(extractedNdx, reason) {
  return await post(`/_mail/extracted-documents/${extractedNdx}/reject`, { reason });
}

/**
 * Undo an apply — trashes the created draft and returns the extracted document
 * to pending_review. Dashboard feed „Vrátit". Backend guards: applied (40) with
 * an untouched draft target (docState=10), else 409.
 *
 * @param {number} extractedNdx
 * @returns {Promise<{success: boolean, data?: {ndx: number, status: number, messageNdx: number, trashedDocId: number}, error?: object}>}
 */
export async function unapplyExtractedDocument(extractedNdx) {
  return await post(`/_mail/extracted-documents/${extractedNdx}/unapply`, {});
}

/**
 * Re-run AI analysis for a message (dashboard feed urgent card „Znovu
 * analyzovat"). Supersedes pending extracted docs and re-queues the message.
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
