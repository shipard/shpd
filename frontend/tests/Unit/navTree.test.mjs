import { test } from 'node:test';
import assert from 'node:assert/strict';
import { flattenLeaves, findLeafById, findRootSectionId } from '../../src/utils/navTree.js';

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
