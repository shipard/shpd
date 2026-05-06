#!/usr/bin/env node
// Cross-check that cs.js and en.js dictionaries cover the same set of keys.
// Run via `npm run check:i18n`. Exits 0 when in sync, 1 when keys diverge.

import csDict from '../src/i18n/cs.js';
import enDict from '../src/i18n/en.js';

const csKeys = new Set(Object.keys(csDict));
const enKeys = new Set(Object.keys(enDict));

const missingInEn = [...csKeys].filter(k => !enKeys.has(k));
const missingInCs = [...enKeys].filter(k => !csKeys.has(k));

if (missingInEn.length === 0 && missingInCs.length === 0) {
  console.log('✓ i18n dictionaries are in sync (' + csKeys.size + ' keys)');
  process.exit(0);
}

if (missingInEn.length > 0) {
  console.error('Keys missing in en:');
  missingInEn.forEach(k => console.error('  ' + k));
}
if (missingInCs.length > 0) {
  console.error('Keys missing in cs:');
  missingInCs.forEach(k => console.error('  ' + k));
}
process.exit(1);
