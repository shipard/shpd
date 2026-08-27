/**
 * ② Overlay — kurzor, efekt kliknutí, rámeček `highlight`.
 *
 * Dvě rozhodnutí drží celý tenhle soubor:
 *
 * 1. **Overlay nemá vlastní animaci.** Kurzor se polohuje výhradně z DOM
 *    eventů `mousemove`, které skutečně dispatchuje `page.mouse.move()`.
 *    Synchronizace vizuálního kurzoru se syntetickou myší — hlavní
 *    technické riziko z #48 — tím vzniká konstrukcí, ne časováním: kurzor
 *    nemůže dorazit dřív ani později než hover stav, protože oboje spouští
 *    tentýž event.
 *
 * 2. **Obsah overlaye bydlí v shadow rootu.** Overlay je součástí stránky,
 *    takže na něj jinak platí stylopis aplikace. Konkrétně
 *    `svg { max-width: 100% }` v `frontend/src/styles/reset.css` smáčklo
 *    šipku kurzoru na nulovou šířku (rodič má `width: 0`, takže
 *    `max-width: 100%` znamená nulu) a kurzor nebyl ve videu vidět. Opravit
 *    to explicitní šířkou by fungovalo do prvního `!important` v resetu;
 *    shadow root je hranice, kterou stylopis stránky nepřekročí.
 *
 * Skript se injektuje přes `addInitScript`, takže přežije navigaci.
 */

const DEFAULTS = {
  cursorFill: '#111827',
  cursorStroke: '#ffffff',
  accent: '#2563eb',
  highlightColor: '#2563eb',
  highlightWidth: 3,
  highlightRadius: 6,
  highlightPad: 4,
};

/** Běží uvnitř stránky. Nesmí sahat na nic z okolního modulu. */
function overlayMain(opts) {
  const KEY = '__shpdVideoOverlay';
  if (window[KEY]) return;

  const state = {
    host: null, cursor: null, ring: null, box: null,
    target: null, raf: 0, timer: 0, placed: '',
  };

  // Rozměry šipky jsou tady napsané dvakrát (v atributech i v CSS)
  // záměrně: atributy kvůli poměru stran viewBoxu, CSS kvůli tomu, aby
  // šířku nemohlo přebít nic zvenčí, kdyby se overlay jednou renderoval
  // i mimo shadow root.
  const SVG =
    '<svg width="24" height="30" viewBox="-2 -2 24 30">'
    + '<path d="M0 0 L0 19 L5 14.4 L8.2 21.5 L11.4 20 L8.2 13 L15 13 Z" '
    + `fill="${opts.cursorFill}" stroke="${opts.cursorStroke}" `
    + 'stroke-width="1.6" stroke-linejoin="round"/></svg>';

  const CSS = `
    .box {
      position: fixed;
      display: none;
      box-sizing: border-box;
      pointer-events: none;
      border: ${opts.highlightWidth}px solid ${opts.highlightColor};
      border-radius: ${opts.highlightRadius}px;
    }
    /* Kurzor je jen ukotvení pro transform — šipka a prstenec z něj čouhají. */
    .cursor {
      position: fixed;
      left: 0; top: 0; width: 0; height: 0;
      opacity: 0;
      will-change: transform;
    }
    /* Špička šipky je v uživatelské souřadnici (0,0); posun o −2 px proti
       viewBoxu ji srovná přesně na bod kurzoru. */
    .cursor svg {
      position: absolute;
      left: -2px; top: -2px;
      width: 24px; height: 30px;
      max-width: none;
      display: block;
      overflow: visible;
    }
    .ring {
      position: absolute;
      left: -16px; top: -16px;
      width: 32px; height: 32px;
      border-radius: 50%;
      border: 2px solid ${opts.accent};
      opacity: 0;
      transform: scale(.4);
    }
  `;

  function build() {
    const host = document.createElement('div');
    host.setAttribute('data-shpd-video-overlay', '');
    host.style.cssText =
      'position:fixed;left:0;top:0;width:0;height:0;pointer-events:none;z-index:2147483647;';

    const shadow = host.attachShadow({ mode: 'open' });

    const style = document.createElement('style');
    style.textContent = CSS;

    const box = document.createElement('div');
    box.className = 'box';

    const cursor = document.createElement('div');
    cursor.className = 'cursor';
    cursor.innerHTML = SVG;

    const ring = document.createElement('div');
    ring.className = 'ring';
    cursor.appendChild(ring);

    shadow.append(style, box, cursor);

    state.host = host;
    state.box = box;
    state.cursor = cursor;
    state.ring = ring;
  }

  /** Overlay musí přežít i to, když aplikace přepíše obsah body. */
  function ensure() {
    if (!state.host) build();
    const parent = document.body || document.documentElement;
    if (!parent) return false;
    if (!state.host.isConnected) parent.appendChild(state.host);
    return true;
  }

  function place(rect) {
    const pad = opts.highlightPad;
    const key = `${rect.x}|${rect.y}|${rect.width}|${rect.height}`;
    if (key === state.placed) return;
    state.placed = key;
    state.box.style.left = `${rect.x - pad}px`;
    state.box.style.top = `${rect.y - pad}px`;
    state.box.style.width = `${rect.width + pad * 2}px`;
    state.box.style.height = `${rect.height + pad * 2}px`;
  }

  // Rámeček drží u prvku i při scrollu — bez toho by po odjetí stránky
  // zůstal viset v prázdnu.
  function follow() {
    if (!state.target) { state.raf = 0; return; }
    place(state.target.getBoundingClientRect());
    state.raf = requestAnimationFrame(follow);
  }

  function clearHighlight() {
    state.target = null;
    state.placed = '';
    if (state.box) state.box.style.display = 'none';
  }

  function highlight(selector, seconds) {
    const el = document.querySelector(selector);
    if (!el || !ensure()) return null;

    const rect = el.getBoundingClientRect();
    state.target = el;
    state.box.style.display = 'block';
    place(rect);
    if (!state.raf) state.raf = requestAnimationFrame(follow);

    clearTimeout(state.timer);
    state.timer = setTimeout(clearHighlight, seconds * 1000);

    return [rect.x, rect.y, rect.width, rect.height];
  }

  document.addEventListener('mousemove', (event) => {
    if (!ensure()) return;
    state.cursor.style.transform = `translate3d(${event.clientX}px, ${event.clientY}px, 0)`;
    state.cursor.style.opacity = '1';
  }, true);

  document.addEventListener('mousedown', () => {
    if (!ensure()) return;
    state.ring.style.transition = 'transform 90ms ease-out, opacity 90ms ease-out';
    state.ring.style.transform = 'scale(.75)';
    state.ring.style.opacity = '.9';
  }, true);

  document.addEventListener('mouseup', () => {
    if (!ensure()) return;
    state.ring.style.transition = 'transform 260ms ease-out, opacity 260ms ease-out';
    state.ring.style.transform = 'scale(1.7)';
    state.ring.style.opacity = '0';
  }, true);

  window[KEY] = { highlight, clearHighlight };
}

/**
 * @param {import('playwright').BrowserContext} context
 * @param {Partial<typeof DEFAULTS>} [options]
 */
export async function installOverlay(context, options = {}) {
  await context.addInitScript(overlayMain, { ...DEFAULTS, ...options });
}

/**
 * Vykreslí rámeček a vrátí jeho `rect` v CSS px — jedním roundtripem,
 * ať se nemusí souřadnice tahat ze stránky podruhé.
 *
 * @param {import('playwright').Page} page
 * @param {string} selector
 * @param {number} seconds
 * @returns {Promise<[number,number,number,number]|null>}
 */
export function highlight(page, selector, seconds) {
  return page.evaluate(
    ([sel, sec]) => window.__shpdVideoOverlay?.highlight(sel, sec) ?? null,
    [selector, seconds],
  );
}
