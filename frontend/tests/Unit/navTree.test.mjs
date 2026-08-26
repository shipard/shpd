import { test } from 'node:test';
import assert from 'node:assert/strict';
import { flattenLeaves, findLeafById, findRootSectionId, findSectionLabel } from '../../src/utils/navTree.js';

// Vzorek stromu ve tvaru /_ui/navigation: root mixuje leafy (`type`)
// a sekce (bez `type`, s `children`), sekce mohou mít pod-skupiny.
const tree = [
  { id: 'dashboard', label: 'Dashboard', type: 'dashboard' },
  { id: 'mail', label: 'Došlá pošta', type: 'viewer', viewerId: 'core.mail.inbox' },
  {
    id: 'sales',
    label: 'Prodej',
    children: [
      { id: 'invoices-out', label: 'Faktury vydané', type: 'viewer' },
      {
        id: 'sales-codebooks',
        label: 'Číselníky',
        children: [
          { id: 'price-lists', label: 'Ceníky', type: 'table' },
        ],
      },
    ],
  },
  {
    id: 'accounting',
    label: 'Účtárna',
    children: [
      { id: 'journal', label: 'Deník', type: 'viewer' },
    ],
  },
];

test('flattenLeaves: vnořené skupiny se zploští depth-first, leafy na rootu zůstávají', () => {
  assert.deepEqual(
    flattenLeaves(tree).map((l) => l.id),
    ['dashboard', 'mail', 'invoices-out', 'price-lists', 'journal'],
  );
});

test('flattenLeaves: prázdný strom', () => {
  assert.deepEqual(flattenLeaves([]), []);
});

test('flattenLeaves: skupina bez children se přeskočí', () => {
  assert.deepEqual(flattenLeaves([{ id: 'empty', label: 'Prázdná' }]), []);
});

test('flattenLeaves: withGroupLabel — label nejbližší nadřazené skupiny', () => {
  const leaves = flattenLeaves(tree, { withGroupLabel: true });
  const byId = Object.fromEntries(leaves.map((l) => [l.id, l.groupLabel]));
  assert.equal(byId['dashboard'], null);      // root-level leaf
  assert.equal(byId['mail'], null);           // root-level leaf
  assert.equal(byId['invoices-out'], 'Prodej');
  assert.equal(byId['price-lists'], 'Číselníky'); // pod-skupina, ne root sekce
  assert.equal(byId['journal'], 'Účtárna');
});

test('flattenLeaves: withGroupLabel vrací kopie, originální uzly se nemutují', () => {
  flattenLeaves(tree, { withGroupLabel: true });
  assert.equal(tree[2].children[0].groupLabel, undefined);
});

test('flattenLeaves: bez options vrací původní objekty (zpětná kompatibilita)', () => {
  const leaves = flattenLeaves(tree);
  assert.equal(leaves[0], tree[0]); // identita, ne kopie
  assert.equal(leaves[0].groupLabel, undefined);
});

test('findLeafById: leaf na rootu i vnořený', () => {
  assert.equal(findLeafById(tree, 'dashboard')?.label, 'Dashboard');
  assert.equal(findLeafById(tree, 'price-lists')?.label, 'Ceníky');
});

test('findLeafById: nenalezen / id skupiny (bez type) → null', () => {
  assert.equal(findLeafById(tree, 'nonexistent'), null);
  assert.equal(findLeafById(tree, 'sales'), null);
});

test('findLeafById: ne-pole → null', () => {
  assert.equal(findLeafById(null, 'x'), null);
  assert.equal(findLeafById(undefined, 'x'), null);
});

test('findRootSectionId: leaf přímo v sekci', () => {
  assert.equal(findRootSectionId(tree, 'invoices-out'), 'sales');
  assert.equal(findRootSectionId(tree, 'journal'), 'accounting');
});

test('findRootSectionId: leaf v pod-skupině sekce → id root sekce', () => {
  assert.equal(findRootSectionId(tree, 'price-lists'), 'sales');
});

test('findRootSectionId: root-level leaf (_top, dashboard) → null', () => {
  assert.equal(findRootSectionId(tree, 'dashboard'), null);
  assert.equal(findRootSectionId(tree, 'mail'), null);
});

test('findRootSectionId: neznámý leaf / prázdný strom / null id → null', () => {
  assert.equal(findRootSectionId(tree, 'nonexistent'), null);
  assert.equal(findRootSectionId([], 'journal'), null);
  assert.equal(findRootSectionId(tree, null), null);
});

test('findSectionLabel: label root sekce dle id', () => {
  assert.equal(findSectionLabel(tree, 'sales'), 'Prodej');
  assert.equal(findSectionLabel(tree, 'accounting'), 'Účtárna');
});

test('findSectionLabel: root-level leaf se nepočítá za sekci → fallback id', () => {
  assert.equal(findSectionLabel(tree, 'dashboard'), 'dashboard');
});

test('findSectionLabel: neznámá sekce → syrové id; null id / ne-pole → degradace', () => {
  assert.equal(findSectionLabel(tree, 'purchase'), 'purchase');
  assert.equal(findSectionLabel(tree, null), null);
  assert.equal(findSectionLabel(null, 'sales'), 'sales');
});
