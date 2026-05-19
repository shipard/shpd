import { get } from './client.js';

/**
 * Fetch dashboard data — agregát alerts/mail/tasks + AI summary counts.
 * Vrací `{ generatedAt, summary, widgets }` nebo null při selhání.
 */
export async function fetchDashboard() {
  const res = await get('/_ui/dashboard');
  return res?.success ? res.data : null;
}
