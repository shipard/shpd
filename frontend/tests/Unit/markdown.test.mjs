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
