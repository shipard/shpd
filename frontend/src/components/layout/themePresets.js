// Kurátorovaná paleta pro custom téma sidebaru. Tmavé tóny drží
// lightness pásmo brand modré (~L 0.30 v OKLCH), aby bílý text
// a white-alpha zvýraznění měly všude srovnatelný kontrast.
// Dvě světlé barvy na konci ověřují odvozování tmavého textu.
export const THEME_PRESETS = [
  { id: 'steel-blue',   color: '#2E6494', nameKey: 'theme.preset.steelBlue' },
  { id: 'petrol',       color: '#0E4F5C', nameKey: 'theme.preset.petrol' },
  { id: 'emerald',      color: '#115E4B', nameKey: 'theme.preset.emerald' },
  { id: 'bottle-green', color: '#2F5D3A', nameKey: 'theme.preset.bottleGreen' },
  { id: 'terracotta',   color: '#9A3B26', nameKey: 'theme.preset.terracotta' },
  { id: 'wine',         color: '#6D1F2C', nameKey: 'theme.preset.wine' },
  { id: 'magenta',      color: '#8C2F5D', nameKey: 'theme.preset.magenta' },
  { id: 'plum',         color: '#4A2C66', nameKey: 'theme.preset.plum' },
  { id: 'indigo',       color: '#34307D', nameKey: 'theme.preset.indigo' },
  { id: 'graphite',     color: '#2F343D', nameKey: 'theme.preset.graphite' },
  { id: 'sand',         color: '#E3D5B8', nameKey: 'theme.preset.sand' },
  { id: 'mist',         color: '#DBE4EE', nameKey: 'theme.preset.mist' },
];
