/**
 * Odvozování sidebar tokenů pro custom téma z jedné vybrané barvy.
 *
 * Vstupem je #RRGGBB barva pozadí sidebaru, výstupem kompletní mapa
 * sidebar tokenů: elevated plocha (o stupeň světlejší v OKLCH) a sada
 * text/hover/border/active tokenů podle luminance — světlé barvy
 * sidebaru dostanou tmavý text, tmavé světlý.
 *
 * Žádné závislosti. Seznam SIDEBAR_TOKEN_NAMES sdílí theme store
 * (čištění inline tokenů při přepnutí na built-in téma) — drž ho
 * v synchronu s klíči, které vrací deriveSidebarTokens().
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
];

/* Hranice luminance: nad ní je sidebar "světlý" a dostane tmavý text. */
const LIGHT_SIDEBAR_THRESHOLD = 0.65;

/* sRGB kanál (0–1) → linear (gamma expand). */
function srgbToLinear(c) {
  return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
}

/**
 * #RRGGBB → {l, c, h} v OKLCH (l 0–1, h ve stupních 0–360).
 * Řetězec: sRGB → linear → LMS (OKLab matice) → OKLab → LCH.
 */
export function hexToOklch(hex) {
  const r = srgbToLinear(parseInt(hex.slice(1, 3), 16) / 255);
  const g = srgbToLinear(parseInt(hex.slice(3, 5), 16) / 255);
  const b = srgbToLinear(parseInt(hex.slice(5, 7), 16) / 255);

  const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
  const m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
  const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);

  const L = 0.2104542553 * l + 0.7936177850 * m - 0.0040720468 * s;
  const a = 1.9779984951 * l - 2.4285922050 * m + 0.4505937099 * s;
  const bb = 0.0259040371 * l + 0.7827717662 * m - 0.8086757660 * s;

  const c = Math.sqrt(a * a + bb * bb);
  let h = (Math.atan2(bb, a) * 180) / Math.PI;
  if (h < 0) h += 360;

  return { l: L, c, h };
}

const round3 = (n) => Math.round(n * 1000) / 1000;

/**
 * Z vybrané barvy sidebaru odvodí kompletní mapu sidebar tokenů
 * `{tokenName: cssValue}` — klíče přesně odpovídají SIDEBAR_TOKEN_NAMES.
 */
export function deriveSidebarTokens(hex) {
  const { l, c, h } = hexToOklch(hex);

  /* Elevated plocha (dropdown/popover nad sidebarem) — o stupeň
     světlejší stejná barva, cap u téměř bílé. */
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

  return {
    '--shpd-color-bg-sidebar': hex,
    '--shpd-color-bg-sidebar-elevated': elevated,
    ...textTokens,
  };
}
