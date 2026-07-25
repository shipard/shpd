/**
 * Maps an MCP tool name to an i18n key for a human-friendly chip label
 * (e.g. `persons_search` → `chat.tool.personsSearch` → "🔍 Hledám osoby…").
 *
 * Returns null for unknown tools so the caller can fall back to a generic
 * label (`chat.tool.generic`) with the raw name — nothing ever "falls through"
 * to raw JSON as the catalog grows.
 */
const TOOL_LABEL_KEYS = {
  persons_search: 'chat.tool.personsSearch',
  persons_get: 'chat.tool.personsGet',
  documents_search: 'chat.tool.documentsSearch',
  documents_aggregate: 'chat.tool.documentsAggregate',
  mail_list_pending: 'chat.tool.mailListPending',
  registry_search: 'chat.tool.registrySearch',
};

/**
 * @param {string} name
 * @returns {string|null} i18n key, or null when the tool is unknown
 */
export function toolLabelKey(name) {
  return TOOL_LABEL_KEYS[name] ?? null;
}
