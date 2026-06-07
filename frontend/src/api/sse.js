/**
 * Pure SSE frame parser for the chat stream.
 *
 * The backend emits frames separated by a blank line:
 *   event: text-delta\n
 *   data: {"text":"…"}\n
 *   \n
 *
 * `parseSseFrames` pulls every COMPLETE frame out of `buffer` and returns the
 * unparsed remainder (`rest`) — a frame split across chunk boundaries stays in
 * `rest` until the next chunk completes it. Kept dependency-free and free of
 * any browser API so it is unit-testable under plain Node.
 *
 * @param {string} buffer  accumulated text (previous `rest` + new chunk)
 * @returns {{ frames: Array<{event: string, data: string}>, rest: string }}
 */
export function parseSseFrames(buffer) {
  const frames = [];
  let idx;

  while ((idx = buffer.indexOf('\n\n')) !== -1) {
    const rawFrame = buffer.slice(0, idx);
    buffer = buffer.slice(idx + 2);

    let event = null;
    let data = null;
    for (const line of rawFrame.split('\n')) {
      if (line.startsWith('event:')) {
        event = line.slice('event:'.length).trim();
      } else if (line.startsWith('data:')) {
        data = line.slice('data:'.length).trim();
      }
      // `:` comments and stray lines are ignored.
    }

    // A frame is only meaningful with both an event name and a data payload.
    if (event !== null && data !== null) {
      frames.push({ event, data });
    }
  }

  return { frames, rest: buffer };
}
