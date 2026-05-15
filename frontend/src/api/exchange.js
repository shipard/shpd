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
 * Apply an extracted document — runs DocumentApplier with
 * `autoCreateMode = "safe"` and `targetDocState = 10` server-side.
 *
 * `resolveOverrides` is a placeholder for Phase 3b (userAction decisions
 * from the UI); in Phase 3a always pass `null` — backend ignores body
 * for the apply endpoint.
 *
 * @param {number} extractedNdx
 * @param {object|null} [resolveOverrides] - { supplier: {userAction: ...}, ... }
 */
export async function applyExtractedDocument(extractedNdx, resolveOverrides = null) {
  const body = resolveOverrides !== null ? { _resolve: resolveOverrides } : {};
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
