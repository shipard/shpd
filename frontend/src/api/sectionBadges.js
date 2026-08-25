import { get } from './client.js';

/**
 * Fetch badge stavů sekcí navigace (GET /_ui/section-badges).
 * Vrací mapu `{ "<sectionId>": { count, severity } }` (jen neprázdné
 * sekce, `_top` je platný klíč) nebo null při selhání/401.
 */
export async function fetchSectionBadges() {
  const res = await get('/_ui/section-badges');
  return res?.success ? (res.data?.sections ?? null) : null;
}
