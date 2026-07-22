import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  enrichedRowCount,
  matchKindKey,
  suggestedFieldKeys,
} from '../../src/components/exchange/enrichBadge.js';

test('enrichedRowCount: null / prázdné pole → 0', () => {
  assert.equal(enrichedRowCount(null), 0);
  assert.equal(enrichedRowCount(undefined), 0);
  assert.equal(enrichedRowCount([]), 0);
});

test('enrichedRowCount: počítá jen řádky s matchedBy', () => {
  const rows = [
    { index: 0, enrichment: { matchedBy: 'historyExactRaw', suggested: { ourCode: 'A' } } },
    { index: 1, enrichment: { matchedBy: null, skipped: 'hasOurCode', suggested: {} } },
    { index: 2, enrichment: { matchedBy: null, suggested: {} } },
    { index: 3, enrichment: { matchedBy: 'historyFuzzy', suggested: { ourCode: 'B' } } },
    { index: 4 }, // blok chybí úplně
    { index: 5, item: { status: 'matched' } }, // jen resolve, bez enrichment
  ];
  assert.equal(enrichedRowCount(rows), 2);
});

test('matchKindKey: ExactRaw i ExactNorm → exact, Fuzzy → fuzzy', () => {
  assert.equal(matchKindKey('historyExactRaw'), 'exact');
  assert.equal(matchKindKey('historyExactNorm'), 'exact');
  assert.equal(matchKindKey('historyFuzzy'), 'fuzzy');
});

test('matchKindKey: DominantItem → dominant', () => {
  assert.equal(matchKindKey('historyDominantItem'), 'dominant');
});

test('suggestedFieldKeys: plná trojice v deterministickém pořadí', () => {
  assert.deepEqual(
    suggestedFieldKeys({ account: '518001', vatCode: 'std21', ourCode: 'NET500' }),
    ['item', 'vat', 'account'],
  );
});

test('suggestedFieldKeys: jen ourCode → jeden klíč', () => {
  assert.deepEqual(suggestedFieldKeys({ ourCode: 'NET500' }), ['item']);
});

test('suggestedFieldKeys: prázdné / chybějící → []', () => {
  assert.deepEqual(suggestedFieldKeys({}), []);
  assert.deepEqual(suggestedFieldKeys(null), []);
  assert.deepEqual(suggestedFieldKeys(undefined), []);
});
