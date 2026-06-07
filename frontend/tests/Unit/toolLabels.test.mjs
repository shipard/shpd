import { test } from 'node:test';
import assert from 'node:assert/strict';
import { toolLabelKey } from '../../src/components/chat/toolLabels.js';

test('known tools map to i18n keys', () => {
  assert.equal(toolLabelKey('persons_search'), 'chat.tool.personsSearch');
  assert.equal(toolLabelKey('persons_get'), 'chat.tool.personsGet');
  assert.equal(toolLabelKey('documents_search'), 'chat.tool.documentsSearch');
  assert.equal(toolLabelKey('mail_list_pending'), 'chat.tool.mailListPending');
});

test('unknown tool returns null (caller falls back to generic)', () => {
  assert.equal(toolLabelKey('mail_draft_document'), null);
  assert.equal(toolLabelKey('whatever_new_tool'), null);
});
