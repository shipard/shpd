/**
 * Načtení a validace konfigurace z `.env`.
 *
 * Žádná závislost — `process.loadEnvFile()` umí Node 24 nativně. Hodnoty
 * už přítomné v prostředí mají přednost před souborem, takže jednorázové
 * přebití jde udělat prefixem příkazu (`VIDEO_HEADFUL=1 node bin/…`).
 */

import { existsSync } from 'node:fs';
import { dirname, isAbsolute, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { UserError } from './errors.mjs';

/** Kořen podprojektu (`tools/video-runner/`). Relativní cesty z `.env` se řeší vůči němu. */
export const PROJECT_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');

/** Kořen repozitáře — kvůli cestám ke scénářům v `demo/scenarios/`. */
export const REPO_ROOT = resolve(PROJECT_ROOT, '..', '..');

const ENV_FILE = resolve(PROJECT_ROOT, '.env');

let envLoaded = false;

function loadEnvFile() {
  if (envLoaded) return;
  envLoaded = true;

  if (!existsSync(ENV_FILE)) {
    throw new UserError(
      'Chybí konfigurace: tools/video-runner/.env neexistuje.',
      'Zkopíruj vzor a vyplň: cp .env.example .env',
    );
  }
  process.loadEnvFile(ENV_FILE);
}

/**
 * @param {string} name
 * @param {string} what Lidský popis položky do hlášky.
 */
function required(name, what) {
  const value = process.env[name]?.trim();
  if (!value) {
    throw new UserError(
      `Chybí povinná položka ${name} v .env (${what}).`,
      'Vzor všech položek je v .env.example.',
    );
  }
  return value;
}

function optional(name, fallback) {
  const value = process.env[name]?.trim();
  return value ? value : fallback;
}

/** Relativní cesta z `.env` se počítá od kořene podprojektu, ne od CWD. */
function projectPath(value) {
  return isAbsolute(value) ? value : resolve(PROJECT_ROOT, value);
}

/**
 * @typedef {object} Config
 * @property {string} baseUrl Bez koncového lomítka.
 * @property {string} [login]
 * @property {string} [password]
 * @property {string} storageState Absolutní cesta.
 * @property {string} workDir Absolutní cesta.
 * @property {string} outDir Absolutní cesta.
 * @property {boolean} headful
 */

/**
 * @param {object} [need] Co daný verb skutečně potřebuje — aby `compose`
 *   nepadal na chybějící adrese instance a `check` na chybějícím hesle.
 * @param {boolean} [need.baseUrl]
 * @param {boolean} [need.credentials]
 * @returns {Config}
 */
export function loadConfig(need = {}) {
  loadEnvFile();

  /** @type {Config} */
  const config = {
    baseUrl: '',
    storageState: projectPath(optional('SHPD_STORAGE_STATE', '.storage-state.json')),
    workDir: projectPath(optional('VIDEO_WORK_DIR', './.work')),
    outDir: projectPath(optional('VIDEO_OUT_DIR', './out')),
    headful: optional('VIDEO_HEADFUL', '0') === '1',
  };

  if (need.baseUrl) {
    const raw = required('SHPD_BASE_URL', 'adresa cílové instance včetně ID datového zdroje');
    let parsed;
    try {
      parsed = new URL(raw);
    } catch {
      throw new UserError(
        `SHPD_BASE_URL není platná adresa: ${raw}`,
        'Očekává se např. https://demo.example.dev/a3f2-b8c1-d4e7-f9a0',
      );
    }
    if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
      throw new UserError(`SHPD_BASE_URL musí být http nebo https, ne ${parsed.protocol}`);
    }
    config.baseUrl = raw.replace(/\/+$/, '');
  }

  if (need.credentials) {
    config.login = required('SHPD_LOGIN', 'uživatel v cílovém datovém zdroji');
    config.password = required('SHPD_PASSWORD', 'heslo uživatele');
  }

  return config;
}

/** Cesta relativní k `baseUrl` → absolutní URL. */
export function pageUrl(config, path) {
  return config.baseUrl + (path.startsWith('/') ? path : `/${path}`);
}
