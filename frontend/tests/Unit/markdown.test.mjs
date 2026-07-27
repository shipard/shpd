import { test } from 'node:test';
import assert from 'node:assert/strict';
import { parseMarkdown } from '../../src/components/chat/markdown.js';

test('bold, italic and inline code spans', () => {
  assert.deepEqual(parseMarkdown('**hi**'), [
    { type: 'paragraph', spans: [{ type: 'strong', text: 'hi' }] },
  ]);
  assert.deepEqual(parseMarkdown('*hi*'), [
    { type: 'paragraph', spans: [{ type: 'em', text: 'hi' }] },
  ]);
  assert.deepEqual(parseMarkdown('_hi_'), [
    { type: 'paragraph', spans: [{ type: 'em', text: 'hi' }] },
  ]);
  assert.deepEqual(parseMarkdown('`x = 1`'), [
    { type: 'paragraph', spans: [{ type: 'code', text: 'x = 1' }] },
  ]);
});

test('mixed inline spans keep order', () => {
  const [block] = parseMarkdown('a **b** c');
  assert.deepEqual(block.spans, [
    { type: 'text', text: 'a ' },
    { type: 'strong', text: 'b' },
    { type: 'text', text: ' c' },
  ]);
});

test('fenced code block', () => {
  const blocks = parseMarkdown('```\nline1\nline2\n```');
  assert.deepEqual(blocks, [{ type: 'code_block', text: 'line1\nline2' }]);
});

test('unordered and ordered lists', () => {
  const ul = parseMarkdown('- one\n- two');
  assert.equal(ul[0].type, 'list');
  assert.equal(ul[0].ordered, false);
  assert.equal(ul[0].items.length, 2);
  assert.deepEqual(ul[0].items[0], [{ type: 'text', text: 'one' }]);

  const ol = parseMarkdown('1. first\n2. second');
  assert.equal(ol[0].ordered, true);
  assert.equal(ol[0].items.length, 2);
});

test('separate paragraphs split on blank line', () => {
  const blocks = parseMarkdown('first para\n\nsecond para');
  assert.equal(blocks.length, 2);
  assert.equal(blocks[0].type, 'paragraph');
  assert.equal(blocks[1].type, 'paragraph');
});

test('HTML is kept as literal text (no raw-HTML token)', () => {
  const blocks = parseMarkdown('<script>alert(1)</script>');
  assert.deepEqual(blocks, [
    { type: 'paragraph', spans: [{ type: 'text', text: '<script>alert(1)</script>' }] },
  ]);
  // No token type ever carries renderable HTML.
  const types = new Set(blocks.flatMap((b) => (b.spans ?? []).map((s) => s.type)));
  assert.ok(!types.has('html'));
});

test('basic table with outer pipes', () => {
  const blocks = parseMarkdown('| A | B |\n| --- | --- |\n| 1 | 2 |\n| 3 | 4 |');
  assert.deepEqual(blocks, [
    {
      type: 'table',
      header: [[{ type: 'text', text: 'A' }], [{ type: 'text', text: 'B' }]],
      rows: [
        [[{ type: 'text', text: '1' }], [{ type: 'text', text: '2' }]],
        [[{ type: 'text', text: '3' }], [{ type: 'text', text: '4' }]],
      ],
      align: [null, null],
    },
  ]);
});

test('table without outer pipes', () => {
  const blocks = parseMarkdown('A | B\n--- | ---\n1 | 2');
  assert.equal(blocks.length, 1);
  assert.equal(blocks[0].type, 'table');
  assert.deepEqual(blocks[0].header, [
    [{ type: 'text', text: 'A' }],
    [{ type: 'text', text: 'B' }],
  ]);
  assert.deepEqual(blocks[0].rows, [
    [[{ type: 'text', text: '1' }], [{ type: 'text', text: '2' }]],
  ]);
});

test('table alignment from delimiter row', () => {
  const blocks = parseMarkdown('| a | b | c | d |\n| :--- | :---: | ---: | --- |\n| 1 | 2 | 3 | 4 |');
  assert.deepEqual(blocks[0].align, ['left', 'center', 'right', null]);
});

test('inline formatting inside table cells', () => {
  const blocks = parseMarkdown('| Name | Value |\n| --- | --- |\n| **bold** | `code` |');
  assert.deepEqual(blocks[0].rows[0], [
    [{ type: 'strong', text: 'bold' }],
    [{ type: 'code', text: 'code' }],
  ]);
});

test('inconsistent cell counts: short row padded, long row truncated', () => {
  const blocks = parseMarkdown('| A | B |\n| --- | --- |\n| only |\n| 1 | 2 | extra |');
  assert.deepEqual(blocks[0].rows[0], [[{ type: 'text', text: 'only' }], []]);
  assert.deepEqual(blocks[0].rows[1], [
    [{ type: 'text', text: '1' }],
    [{ type: 'text', text: '2' }],
  ]);
});

test('pipe line without delimiter row stays a paragraph', () => {
  const blocks = parseMarkdown('a | b\nplain text');
  assert.equal(blocks.length, 1);
  assert.equal(blocks[0].type, 'paragraph');
});

test('table ends at blank line, following paragraph and list parse normally', () => {
  const blocks = parseMarkdown('| A |\n| --- |\n| 1 |\n\nafter\n- item');
  assert.equal(blocks.length, 3);
  assert.equal(blocks[0].type, 'table');
  assert.equal(blocks[1].type, 'paragraph');
  assert.equal(blocks[2].type, 'list');
});

test('paragraph directly before a table does not swallow it', () => {
  const blocks = parseMarkdown('intro text\n| A | B |\n| --- | --- |\n| 1 | 2 |');
  assert.equal(blocks.length, 2);
  assert.equal(blocks[0].type, 'paragraph');
  assert.deepEqual(blocks[0].spans, [{ type: 'text', text: 'intro text' }]);
  assert.equal(blocks[1].type, 'table');
});

test('HTML in table cells is kept as literal text', () => {
  const blocks = parseMarkdown('| A |\n| --- |\n| <img src=x onerror=alert(1)> |');
  assert.deepEqual(blocks[0].rows[0][0], [
    { type: 'text', text: '<img src=x onerror=alert(1)>' },
  ]);
});

test('headings level 1-6 with cap on regex', () => {
  for (let level = 1; level <= 6; level++) {
    const blocks = parseMarkdown(`${'#'.repeat(level)} Title`);
    assert.deepEqual(blocks, [
      { type: 'heading', level, spans: [{ type: 'text', text: 'Title' }] },
    ]);
  }
});

test('hash without space is a paragraph, not a heading', () => {
  const blocks = parseMarkdown('#nospace');
  assert.equal(blocks[0].type, 'paragraph');
});

test('heading with inline formatting', () => {
  const blocks = parseMarkdown('## **Tučný** nadpis');
  assert.deepEqual(blocks, [
    {
      type: 'heading',
      level: 2,
      spans: [
        { type: 'strong', text: 'Tučný' },
        { type: 'text', text: ' nadpis' },
      ],
    },
  ]);
});

test('paragraph directly before a heading does not swallow it', () => {
  const blocks = parseMarkdown('intro\n# Title');
  assert.equal(blocks.length, 2);
  assert.equal(blocks[0].type, 'paragraph');
  assert.equal(blocks[1].type, 'heading');
});
