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

// Gradientové presety — vertikální přechody (180deg, shora dolů).
// Dvojice drží podobné OKLCH lightness pásmo (ΔL ≤ ~0.08): přechody
// jsou posuny odstínu, ne světlosti, aby text a white-alpha zvýraznění
// fungovaly po celé výšce sidebaru. Stopy z velké části recyklují
// solid paletu. Dva světlé na konci (tmavý text z efektivní barvy).
export const THEME_GRADIENT_PRESETS = [
  { id: 'ocean',      stops: ['#00345C', '#0E4F5C'], nameKey: 'theme.gradient.ocean' },
  { id: 'meadow',     stops: ['#6E6320', '#2F5D3A'], nameKey: 'theme.gradient.meadow' },
  { id: 'moss',       stops: ['#115E4B', '#46702F'], nameKey: 'theme.gradient.moss' },
  { id: 'aurora',     stops: ['#2E6494', '#115E4B'], nameKey: 'theme.gradient.aurora' },
  { id: 'midnight',   stops: ['#34307D', '#00345C'], nameKey: 'theme.gradient.midnight' },
  { id: 'heather',    stops: ['#4A2C66', '#8C2F5D'], nameKey: 'theme.gradient.heather' },
  { id: 'blackberry', stops: ['#6D1F2C', '#4A2C66'], nameKey: 'theme.gradient.blackberry' },
  { id: 'sunset',     stops: ['#9A3B26', '#6D1F2C'], nameKey: 'theme.gradient.sunset' },
  { id: 'ember',      stops: ['#9A3B26', '#8C2F5D'], nameKey: 'theme.gradient.ember' },
  { id: 'storm',      stops: ['#2F343D', '#34307D'], nameKey: 'theme.gradient.storm' },
  { id: 'dawn',       stops: ['#DBE4EE', '#E3D5B8'], nameKey: 'theme.gradient.dawn' },
  { id: 'peony',      stops: ['#EAD9E2', '#DBE4EE'], nameKey: 'theme.gradient.peony' },
];
