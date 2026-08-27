/**
 * Časová osa záznamu — druhý artefakt vedle videa.
 *
 * Díky ní je přepsání titulku otázkou `compose`, ne přetočení. `rect`
 * u akcí nad prvky nepoužívá dnes nikdo; je tam proto, aby se zoomy
 * a rámečky daly dodělat v postprodukci, aniž by se musely přetáčet už
 * hotové nahrávky (D5).
 */

import { writeFile } from 'node:fs/promises';

/** Souřadnice se ukládají v pixelech rawu, ne v CSS — proto tenhle přepočet. */
function toCaptureSpace(rect, scale) {
  if (!rect) return undefined;
  return rect.map((v) => Math.round(v * scale));
}

export class Timeline {
  /**
   * @param {import('./scenario.mjs').Scenario} scenario
   * @param {'cdp'|'x11'|'none'} capture
   */
  constructor(scenario, capture) {
    this.scenario = scenario;
    this.capture = capture;
    /** @type {Array<Record<string, unknown>>} */
    this.events = [];
    this.t0 = null;
    this.end = null;
    /**
     * Sekundy rawu, které předcházejí nule časové osy. U `cdp` je to nula
     * (osa začíná prvním framem), u `x11` doba, než se ffmpeg rozjel —
     * `compose` ji ořízne, takže se obě varianty chovají stejně.
     */
    this.rawOffset = 0;
  }

  /** @param {number} seconds */
  setRawOffset(seconds) {
    this.rawOffset = Math.max(0, seconds);
  }

  /**
   * Nula časové osy. Volá ji driver záznamu ve chvíli, kdy se skutečně
   * začalo nahrávat — ne interpret, ten o rozjezdu ffmpegu nic neví.
   */
  start() {
    this.t0 = performance.now();
  }

  /** Sekundy od začátku záznamu, zaokrouhleno na milisekundy. */
  now() {
    if (this.t0 === null) return 0;
    return Math.round(performance.now() - this.t0) / 1000;
  }

  /**
   * @param {string} type
   * @param {Record<string, unknown>} [data] Klíč `rect` se očekává v CSS px.
   */
  mark(type, data = {}) {
    const { rect, ...rest } = data;
    this.events.push({
      t: this.now(),
      type,
      ...rest,
      ...(rect ? { rect: toCaptureSpace(rect, this.scenario.capture.scale) } : {}),
    });
  }

  /** Uzavře osu. Bez toho by `compose` nevěděl, kam sahá poslední titulek. */
  finish() {
    this.end = this.now();
  }

  duration() {
    return this.end ?? this.now();
  }

  toJSON() {
    return {
      scenario: this.scenario.id,
      capture: this.capture,
      // Prostor, ve kterém jsou souřadnice `rect` — bez toho by je
      // postprodukce nemohla přepočítat na výstupní rozlišení.
      captureSize: { w: this.scenario.capture.w, h: this.scenario.capture.h },
      duration: this.duration(),
      rawOffset: Math.round(this.rawOffset * 1000) / 1000,
      fps: this.scenario.output.fps,
      events: this.events,
    };
  }

  /** @param {string} path */
  async write(path) {
    await writeFile(path, `${JSON.stringify(this.toJSON(), null, 2)}\n`, 'utf8');
  }
}
