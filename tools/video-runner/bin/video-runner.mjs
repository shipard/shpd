#!/usr/bin/env node
/**
 * Vstupní bod CLI. Veškerá logika je v src/cli.mjs — tady zůstává jen
 * převod výjimky na exit kód, aby se čitelná hláška nikdy neutopila
 * v stack trace.
 */

import { reportError, run } from '../src/cli.mjs';

try {
  process.exitCode = await run(process.argv.slice(2));
} catch (error) {
  process.exitCode = reportError(error);
}
