/**
 * API helpers for the Spisovna module (`base.registry`).
 *
 * Backend endpoints:
 *   POST /api/v1/_registry/from-message/{ndx} — file an incoming message
 *     into the registry (creates a draft document with copied attachments,
 *     marks the message done). Returns {id, warning?}; warning code
 *     DUPLICATE_IN_REGISTRY means another live registry document already
 *     holds an attachment with the same checksum (non-blocking).
 *   POST /api/v1/_registry/documents/{id}/extract-text — regenerate the
 *     document's extracted_text from its current attachments (fulltext
 *     ft_text). Returns {chars, attachments}. Wired via the attachments
 *     tab change_endpoint, callable directly too.
 *
 * Note: the ARES company-registry wizard lives in `personsRegistry.js` —
 * unrelated to Spisovna despite the similar name.
 */

import { post } from './client.js';

/**
 * File an incoming message into the registry (Spisovna).
 *
 * @param {number} messageNdx
 * @returns {Promise<{success: boolean, data?: {id: number, warning?: {
 *   code: string, message: string, existing_document_ndx: number,
 * }}, error?: object}>}
 */
export async function fileFromMessage(messageNdx) {
  return await post(`/_registry/from-message/${messageNdx}`, {});
}

/**
 * Regenerate a registry document's extracted_text from its attachments.
 *
 * @param {number} documentId
 * @returns {Promise<{success: boolean, data?: {chars: number, attachments: number}, error?: object}>}
 */
export async function extractDocumentText(documentId) {
  return await post(`/_registry/documents/${documentId}/extract-text`, {});
}
