import { mkdir, readdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { zipSync } from 'fflate';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const plugin = path.join(root, 'plugin/reel-together');
const files = {};
async function collect(directory, prefix = 'reel-together') {
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    if (entry.name.startsWith('.')) continue;
    const full = path.join(directory, entry.name);
    const archive = `${prefix}/${entry.name}`;
    if (entry.isDirectory()) await collect(full, archive);
    else files[archive] = new Uint8Array(await readFile(full));
  }
}
await collect(plugin);
await mkdir(path.join(root, 'dist'), { recursive: true });
await writeFile(path.join(root, 'dist/reel-together.zip'), zipSync(files));
console.log(`Created dist/reel-together.zip (${Object.keys(files).length} files).`);
