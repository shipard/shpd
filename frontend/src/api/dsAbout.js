// API panelu „O zdroji dat" (tasks/ds-about-panel.md, Issue #41):
//   GET /_ui/ds-about — read-only agregace identity, charakteristiky
//                       a velikostí DS; jediná mutace na serveru je cache
//                       skenu příloh (TTL 1 h)
import { get } from './client.js';

/**
 * @returns {Promise<{success: boolean, data?: {
 *   identity: {dsName: string, ownPerson: ?{fullName: ?string, companyId: ?string, taxId: ?string}, mailAddress: ?string},
 *   profile: {vatPayer: boolean, taxpayerKind: ?number, taxpayerKindLabel: ?string, accountChart: ?string, dsId: string, created: string},
 *   storage: {databaseBytes: number, attachments: {bytes: number, files: number, computedAt: string},
 *             counts: {documents: number, incomingMail: number, attachmentFiles: number}}
 * }, error?: object}>}
 */
export function fetchDsAbout() {
  return get('/_ui/ds-about');
}
