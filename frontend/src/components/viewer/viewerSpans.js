// Sdílená normalizace span hodnot viewer řádků (list i grid layout).
// Span formát viz docs/viewer-grid.md §3.4 a TableViewer::renderRow docblock.

/**
 * Normalize a field value into an array of span objects.
 * Handles: null, string, object {text, class?, icon?, badge?}, or array of those.
 */
export function normalizeSpans(value) {
  if (value == null) return null;
  if (typeof value === 'string') return [{ text: value }];
  if (Array.isArray(value)) return value;
  if (typeof value === 'object' && value.text != null) return [value];
  return null;
}
