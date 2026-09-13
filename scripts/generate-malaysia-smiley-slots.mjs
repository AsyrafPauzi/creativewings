/**
 * @deprecated Use scripts/build-malaysia-map.mjs (reads Natural Earth boundary data).
 * Kept as a thin wrapper for backwards compatibility.
 */
import { spawnSync } from 'child_process';
import { dirname, join } from 'path';
import { fileURLToPath } from 'url';

const script = join(dirname(fileURLToPath(import.meta.url)), 'build-malaysia-map.mjs');
const result = spawnSync(process.execPath, [script], { stdio: 'inherit' });
process.exit(result.status ?? 1);
