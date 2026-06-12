/**
 * Odvozování barev pro custom téma sidebaru.
 *
 * Vstupem je custom konfigurace sidebaru (solid barva nebo gradient
 * dvou stopů) + báze aplikace + opacity, výstupem kompletní mapa
 * sidebar tokenů: pozadí (případně gradient image), elevated plocha
 * (o stupeň světlejší v OKLCH) a sada text/hover/border/active tokenů
 * podle luminance efektivní barvy — světlé sidebary dostanou tmavý
 * text, tmavé světlý.
 *
 * Opacity míchá barvu/stopy směrem k pozadí báze aplikace (BASE_BG)
 * v OKLab — lineární interpolace bez hue wraparound problémů.
 *
 * Žádné závislosti. Seznam SIDEBAR_TOKEN_NAMES sdílí theme store
 * (čištění inline tokenů při přepnutí na built-in téma) — drž ho
 * v synchronu s klíči, které může vracet deriveSidebarTokens().
 */

export const SIDEBAR_TOKEN_NAMES = [
  '--shpd-color-bg-sidebar',
  '--shpd-color-bg-sidebar-elevated',
  '--shpd-color-bg-sidebar-hover',
  '--shpd-color-bg-sidebar-border',
  '--shpd-color-text-sidebar',
  '--shpd-color-text-sidebar-muted',
  '--shpd-color-sidebar-active-bg',
  '--shpd-color-sidebar-active-bg-hover',
  '--shpd-sidebar-bg-image',
];

/* Pozadí aplikace per báze — mix targety pro opacity. Hodnoty musí
   odpovídat --shpd-color-bg ve variables.css (:root a [data-theme=dark]).
   Při změně variables.css aktualizovat i zde (sync komentář na obou
   místech). */
const BASE_BG = { light: '#ffffff', dark: '#232730' };

/* Hranice luminance: nad ní je sidebar "světlý" a dostane tmavý text. */
const LIGHT_SIDEBAR_THRESHOLD = 0.65;

/* sRGB kanál (0–1) → linear (gamma expand). */
function srgbToLinear(c) {
  return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
}

/**
 * #RRGGBB → {L, a, b} v OKLab (L 0–1).
 * Řetězec: sRGB → linear → LMS (OKLab matice) → OKLab.
 */
export function hexToOklab(hex) {
  const r = srgbToLinear(parseInt(hex.slice(1, 3), 16) / 255);
  const g = srgbToLinear(parseInt(hex.slice(3, 5), 16) / 255);
  const b = srgbToLinear(parseInt(hex.slice(5, 7), 16) / 255);

  const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
  const m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
  const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);

  return {
    L: 0.2104542553 * l + 0.7936177850 * m - 0.0040720468 * s,
    a: 1.9779984951 * l - 2.4285922050 * m + 0.4505937099 * s,
    b: 0.0259040371 * l + 0.7827717662 * m - 0.8086757660 * s,
  };
}

/* {L, a, b} → {l, c, h} (h ve stupních, normalizovaná do [0, 360)). */
function labToLch({ L, a, b }) {
  const c = Math.sqrt(a * a + b * b);
  let h = (Math.atan2(b, a) * 180) / Math.PI;
  if (h < 0) h += 360;
  return { l: L, c, h };
}

/**
 * #RRGGBB → {l, c, h} v OKLCH (l 0–1, h ve stupních 0–360).
 */
export function hexToOklch(hex) {
  return labToLch(hexToOklab(hex));
}

/**
 * Lineární interpolace dvou barev v OKLab (t 0–1; t=0 → a, t=1 → b).
 * Vrací {L, a, b}. OKLab místo OKLCH — žádný hue wraparound problém.
 */
export function mixOklab(hexA, hexB, t) {
  const labA = hexToOklab(hexA);
  const labB = hexToOklab(hexB);
  return {
    L: labA.L + (labB.L - labA.L) * t,
    a: labA.a + (labB.a - labA.a) * t,
    b: labA.b + (labB.b - labA.b) * t,
  };
}

const round3 = (n) => Math.round(n * 1000) / 1000;

/** {L, a, b} → "oklch(l c h)" CSS string. */
export function oklabToCss(lab) {
  const { l, c, h } = labToLch(lab);
  return `oklch(${round3(l)} ${round3(c)} ${round3(h)})`;
}

/**
 * Z custom konfigurace odvodí kompletní mapu sidebar tokenů.
 *
 * Pipeline: stopy se po opacity mixu (v OKLab, směrem k BASE_BG[base])
 * složí do "efektivní barvy" — u solid je to barva sama, u gradientu
 * OKLab střed obou stopů. Z efektivní barvy se odvozuje elevated
 * plocha a větvení světlý/tmavý sidebar; gradient navíc dostane token
 * --shpd-sidebar-bg-image (jinak v mapě chybí).
 *
 * @param {object} sidebar — {type:'solid', color} | {type:'gradient', stops:[a,b]}
 * @param {'light'|'dark'} base
 * @param {number} opacity — 0–100
 * @returns {object} — mapa {tokenName: cssValue}; klíč
 *   --shpd-sidebar-bg-image je přítomen JEN pro type 'gradient'
 */
export function deriveSidebarTokens(sidebar, base, opacity) {
  const t = Math.min(Math.max(opacity, 0), 100) / 100;
  const bg = BASE_BG[base] ?? BASE_BG.light;
  const isGradient = sidebar.type === 'gradient';

  /* Stopy po opacity mixu — t=1 plná barva, t=0 čisté pozadí báze. */
  const s1 = mixOklab(bg, isGradient ? sidebar.stops[0] : sidebar.color, t);
  const s2 = isGradient ? mixOklab(bg, sidebar.stops[1], t) : null;

  /* Efektivní barva pro odvozování: solid = s1, gradient = OKLab střed. */
  const effective = isGradient
    ? { L: (s1.L + s2.L) / 2, a: (s1.a + s2.a) / 2, b: (s1.b + s2.b) / 2 }
    : s1;

  /* Elevated plocha (dropdown/popover nad sidebarem) — o stupeň
     světlejší efektivní barva, cap u téměř bílé. */
  const { l, c, h } = labToLch(effective);
  const elevated = `oklch(${round3(Math.min(l + 0.06, 0.98))} ${round3(c)} ${round3(h)})`;

  const isLightSidebar = l >= LIGHT_SIDEBAR_THRESHOLD;

  const textTokens = isLightSidebar
    ? {
        /* Světlý sidebar → tmavý text (slate) + black-alpha plochy. */
        '--shpd-color-text-sidebar':           'rgb(15 23 42 / 0.88)',
        '--shpd-color-text-sidebar-muted':     'rgb(15 23 42 / 0.56)',
        '--shpd-color-bg-sidebar-hover':       'rgb(0 0 0 / 0.06)',
        '--shpd-color-bg-sidebar-border':      'rgb(0 0 0 / 0.10)',
        '--shpd-color-sidebar-active-bg':      'rgb(0 0 0 / 0.10)',
        '--shpd-color-sidebar-active-bg-hover': 'rgb(0 0 0 / 0.15)',
      }
    : {
        /* Tmavý sidebar → světlý text + white-alpha plochy
           (stejná logika jako built-in default). */
        '--shpd-color-text-sidebar':           'rgb(255 255 255 / 0.92)',
        '--shpd-color-text-sidebar-muted':     'rgb(255 255 255 / 0.58)',
        '--shpd-color-bg-sidebar-hover':       'rgb(255 255 255 / 0.08)',
        '--shpd-color-bg-sidebar-border':      'rgb(255 255 255 / 0.10)',
        '--shpd-color-sidebar-active-bg':      'rgb(255 255 255 / 0.16)',
        '--shpd-color-sidebar-active-bg-hover': 'rgb(255 255 255 / 0.22)',
      };

  const tokens = {
    /* U gradientu slouží bg-sidebar jako fallback a podklad pro plochy,
       které gradient nepoužívají. */
    '--shpd-color-bg-sidebar': oklabToCss(isGradient ? effective : s1),
    '--shpd-color-bg-sidebar-elevated': elevated,
    ...textTokens,
  };

  if (isGradient) {
    tokens['--shpd-sidebar-bg-image'] =
      `linear-gradient(180deg, ${oklabToCss(s1)}, ${oklabToCss(s2)})`;
  }

  return tokens;
}
