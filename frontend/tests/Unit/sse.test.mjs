import { test } from 'node:test';
import assert from 'node:assert/strict';
import { parseSseFrames } from '../../src/api/sse.js';

test('parses a single complete frame', () => {
  const { frames, rest } = parseSseFrames('event: text-delta\ndata: {"text":"hi"}\n\n');
  assert.deepEqual(frames, [{ event: 'text-delta', data: '{"text":"hi"}' }]);
  assert.equal(rest, '');
});

test('parses multiple frames in one buffer', () => {
  const buf =
    'event: text-delta\ndata: {"text":"a"}\n\n' +
    'event: text-delta\ndata: {"text":"b"}\n\n';
  const { frames, rest } = parseSseFrames(buf);
  assert.equal(frames.length, 2);
  assert.equal(frames[0].data, '{"text":"a"}');
  assert.equal(frames[1].data, '{"text":"b"}');
  assert.equal(rest, '');
});

test('keeps an incomplete frame in rest across a chunk boundary', () => {
  // First chunk ends mid-frame (no terminating blank line yet).
  let { frames, rest } = parseSseFrames('event: text-delta\ndata: {"te');
  assert.equal(frames.length, 0);
  assert.equal(rest, 'event: text-delta\ndata: {"te');

  // Second chunk completes it.
  ({ frames, rest } = parseSseFrames(rest + 'xt":"hi"}\n\n'));
  assert.deepEqual(frames, [{ event: 'text-delta', data: '{"text":"hi"}' }]);
  assert.equal(rest, '');
});

test('ignores a frame with event: but no data:', () => {
  const { frames } = parseSseFrames('event: ping\n\n');
  assert.equal(frames.length, 0);
});

test('parses message-complete and error events', () => {
  const buf =
    'event: tool-call\ndata: {"name":"persons_search","arguments":{}}\n\n' +
    'event: message-complete\ndata: {"message_id":7}\n\n';
  const { frames } = parseSseFrames(buf);
  assert.equal(frames[0].event, 'tool-call');
  assert.equal(frames[1].event, 'message-complete');
});
