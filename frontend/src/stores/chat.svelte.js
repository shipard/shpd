/**
 * Chat store (Svelte 5 runes) — shared state between the conversation list and
 * the active thread.
 *
 * A "new conversation" is a draft (activeId = null, empty thread); the backend
 * row is created lazily on the first `send()`, so the list never fills with
 * empty conversations. After a streamed turn completes (or errors) we refetch
 * the conversation so the thread shows the canonical persisted messages
 * (correct seq / ids / tool_results) rather than the optimistic copy.
 */

import {
  listConversations,
  createConversation,
  getConversation,
  renameConversation,
  deleteConversation,
  sendMessageStream,
} from '../api/chat.js';

let conversations = $state([]);
let activeId = $state(null);
let messages = $state([]);
let loadingList = $state(false);
let loadingThread = $state(false);
let streaming = $state(false);
let streamingText = $state('');
let streamingTools = $state([]);
let error = $state(null); // { code, message } | null

async function loadConversations() {
  loadingList = true;
  try {
    const res = await listConversations();
    conversations = res && res.success ? res.data : [];
  } finally {
    loadingList = false;
  }
}

async function openConversation(id) {
  activeId = id;
  error = null;
  loadingThread = true;
  try {
    const res = await getConversation(id);
    messages = res && res.success ? res.data.messages : [];
  } finally {
    loadingThread = false;
  }
}

function newConversation() {
  activeId = null;
  messages = [];
  error = null;
  streamingText = '';
  streamingTools = [];
}

async function send(text) {
  const trimmed = (text ?? '').trim();
  if (streaming || trimmed === '') return;

  error = null;
  streaming = true;
  streamingText = '';
  streamingTools = [];

  // Optimistic user bubble.
  messages = [
    ...messages,
    { id: `tmp-${Date.now()}`, role: 'user', kind: 'user_text', content: [{ type: 'text', text: trimmed }] },
  ];

  // Create the conversation lazily on first message.
  let id = activeId;
  if (id == null) {
    const created = await createConversation();
    if (!created || !created.success) {
      streaming = false;
      error = { code: 'CREATE_FAILED', message: 'Could not create conversation' };
      return;
    }
    id = created.data.id;
    activeId = id;
    await loadConversations();
  }

  await sendMessageStream(id, trimmed, {
    onTextDelta: (delta) => { streamingText += delta; },
    onToolCall: (call) => { streamingTools = [...streamingTools, call]; },
    onComplete: () => finalizeTurn(id),
    onError: (err) => { error = err; finalizeTurn(id); },
  });
}

async function finalizeTurn(id) {
  // Refetch canonical state, then drop the transient streaming view.
  await openConversation(id);
  await loadConversations(); // reorder list by `modified`
  streamingText = '';
  streamingTools = [];
  streaming = false;
}

async function rename(id, title) {
  const res = await renameConversation(id, title);
  if (res && res.success) await loadConversations();
  return res;
}

async function remove(id) {
  const res = await deleteConversation(id);
  if (res && res.success) {
    if (activeId === id) newConversation();
    await loadConversations();
  }
  return res;
}

export const chatStore = {
  get conversations() { return conversations; },
  get activeId() { return activeId; },
  get messages() { return messages; },
  get loadingList() { return loadingList; },
  get loadingThread() { return loadingThread; },
  get streaming() { return streaming; },
  get streamingText() { return streamingText; },
  get streamingTools() { return streamingTools; },
  get error() { return error; },
  loadConversations,
  openConversation,
  newConversation,
  send,
  rename,
  remove,
};
