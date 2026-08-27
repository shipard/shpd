/**
 * Názvy artefaktů v pracovním adresáři scénáře. Jedno místo, aby se
 * `record` a `compose` nemohly rozejít.
 */

export const RAW_NAME = 'raw.mp4';

export const TIMELINE_NAME = 'timeline.json';

/** @param {string} id */
export const outName = (id) => `${id}.mp4`;
