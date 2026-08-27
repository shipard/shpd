/** Verb `build` — `record` a hned `compose` nad jedním načtením scénáře. */

import { loadScenario } from '../scenario.mjs';
import { composeScenario } from './compose.mjs';
import { recordScenario } from './record.mjs';

export default async function build({ config, scenarioPath, capture }) {
  const scenario = await loadScenario(scenarioPath);
  await recordScenario(config, scenario, capture);
  await composeScenario(config, scenario);
}
