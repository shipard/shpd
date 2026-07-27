import { test } from 'node:test';
import assert from 'node:assert/strict';
import { tableToTsv } from '../../src/components/chat/clipboardTable.js';
import { parseMarkdown } from '../../src/components/chat/markdown.js';

test('tableToTsv joins rows with \\n and cells with \\t', () => {
  const [token] = parseMarkdown('| A | B |\n| --- | --- |\n| 1 | 2 |\n| 3 | 4 |');
  assert.equal(tableToTsv(token), 'A\tB\n1\t2\n3\t4');
});

test('tableToTsv strips inline formatting (plain span text only)', () => {
  const [token] = parseMarkdown('| Name | Value |\n| --- | --- |\n| **bold** x | `code` |');
  assert.equal(tableToTsv(token), 'Name\tValue\nbold x\tcode');
});

test('tableToTsv collapses tabs/newlines inside a cell to a space', () => {
  const token = {
    header: [[{ type: 'text', text: 'A\tB' }]],
    rows: [[[{ type: 'text', text: 'x\ny' }]]],
  };
  assert.equal(tableToTsv(token), 'A B\nx y');
});

test('tableToTsv keeps padded empty cells', () => {
  const [token] = parseMarkdown('| A | B |\n| --- | --- |\n| only |');
  assert.equal(tableToTsv(token), 'A\tB\nonly\t');
});
