/**
 * Tenké CLI nad verby runneru. Parsování přes `node:util` `parseArgs`,
 * žádná závislost.
 *
 * Verb si sám neřeší konfiguraci — co potřebuje, deklaruje v registru níže
 * a `run()` mu předá hotový `Config`. Díky tomu `compose` nepadá na
 * chybějící adrese instance a `check` na chybějícím hesle.
 */

import { parseArgs } from 'node:util';

import { loadConfig } from './config.mjs';
import { UserError } from './errors.mjs';

const CAPTURES = ['cdp', 'x11'];
const DEFAULT_CAPTURE = 'cdp';

/**
 * @typedef {object} VerbSpec
 * @property {string} module Cesta k modulu s implementací.
 * @property {boolean} scenario Vyžaduje cestu ke scénáři.
 * @property {boolean} baseUrl Potřebuje adresu instance.
 * @property {boolean} credentials Potřebuje login a heslo.
 * @property {boolean} capture Bere v úvahu `--capture`.
 * @property {string} help Jednořádkový popis do nápovědy.
 */

/** @type {Record<string, VerbSpec>} */
const VERBS = {
  login: {
    module: './verbs/login.mjs',
    scenario: false, baseUrl: true, credentials: true, capture: false,
    help: 'Přihlásí se formulářem a uloží session do SHPD_STORAGE_STATE.',
  },
  check: {
    module: './verbs/check.mjs',
    scenario: true, baseUrl: true, credentials: false, capture: false,
    help: 'Projede scénář bez záznamu. Nenulový exit = scénář nesedí na aplikaci.',
  },
  record: {
    module: './verbs/record.mjs',
    scenario: true, baseUrl: true, credentials: false, capture: true,
    help: 'Natočí raw.mp4 a timeline.json do VIDEO_WORK_DIR.',
  },
  compose: {
    module: './verbs/compose.mjs',
    scenario: true, baseUrl: false, credentials: false, capture: false,
    help: 'Složí výsledné video z existujícího raw.mp4 a timeline.json.',
  },
  build: {
    module: './verbs/build.mjs',
    scenario: true, baseUrl: true, credentials: false, capture: true,
    help: 'record + compose v jednom běhu.',
  },
};

const OPTIONS = {
  capture: { type: 'string' },
  help: { type: 'boolean', short: 'h' },
};

function usage() {
  const rows = Object.entries(VERBS)
    .map(([name, spec]) => `  ${name.padEnd(9)} ${spec.help}`)
    .join('\n');

  return `video-runner — výroba videí z UI podle scénáře

Použití:
  video-runner <verb> [scénář] [volby]

Verby:
${rows}

Volby:
  --capture=cdp|x11   Varianta záznamu (výchozí ${DEFAULT_CAPTURE}).
                      cdp = CDP screencast, headless, bez systémových závislostí
                      x11 = headed Chromium v Xvfb + ffmpeg x11grab
  -h, --help          Tato nápověda.

Konfigurace se čte z tools/video-runner/.env (vzor v .env.example).
Podrobnosti v README.md, instalace prostředí v INSTALL.md.`;
}

/**
 * @param {string[]} argv
 * @returns {Promise<number>} Exit kód.
 */
export async function run(argv) {
  let parsed;
  try {
    parsed = parseArgs({ args: argv, options: OPTIONS, allowPositionals: true, strict: true });
  } catch (error) {
    // parseArgs hlásí neznámou volbu výjimkou — pro uživatele je to ale
    // obyčejná překlep-chyba, ne pád runneru.
    throw new UserError(error.message, 'Seznam voleb: video-runner --help');
  }

  const [verbName, ...rest] = parsed.positionals;

  if (parsed.values.help || !verbName) {
    console.log(usage());
    return parsed.values.help ? 0 : 1;
  }

  const spec = VERBS[verbName];
  if (!spec) {
    throw new UserError(
      `Neznámý verb: ${verbName}`,
      `Známé verby: ${Object.keys(VERBS).join(', ')}`,
    );
  }

  if (parsed.values.capture !== undefined && !spec.capture) {
    throw new UserError(`Verb ${verbName} volbu --capture nepoužívá.`);
  }

  const capture = parsed.values.capture ?? DEFAULT_CAPTURE;
  if (!CAPTURES.includes(capture)) {
    throw new UserError(
      `Neznámá varianta záznamu: ${capture}`,
      `Možnosti: ${CAPTURES.join(', ')}`,
    );
  }

  let scenarioPath = null;
  if (spec.scenario) {
    if (rest.length === 0) {
      throw new UserError(
        `Verb ${verbName} potřebuje cestu ke scénáři.`,
        'Např. video-runner check demo/scenarios/spike-dashboard.jsonc',
      );
    }
    scenarioPath = rest[0];
  }
  if (rest.length > (spec.scenario ? 1 : 0)) {
    throw new UserError(`Přebývající argument: ${rest[spec.scenario ? 1 : 0]}`);
  }

  const config = loadConfig({ baseUrl: spec.baseUrl, credentials: spec.credentials });

  const { default: verb } = await import(spec.module);
  await verb({ config, scenarioPath, capture });

  return 0;
}

/** Vypíše chybu tak, aby se dala přečíst, a vrátí exit kód. */
export function reportError(error) {
  if (error instanceof UserError) {
    console.error(`Chyba: ${error.message}`);
    if (error.hint) console.error(error.hint);
    return 1;
  }
  console.error(error);
  return 1;
}
