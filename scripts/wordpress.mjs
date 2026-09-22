import { runCLI } from '@wp-playground/cli';
import { mkdir, access } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
export const pluginPath = path.join(projectRoot, 'plugin/reel-together');

export async function startWordPress({ port = 9400, storage, setup = '' } = {}) {
  let existing = false;
  if (storage) {
    await mkdir(storage, { recursive: true });
    try { await access(path.join(storage, 'wp-load.php')); existing = true; } catch {}
  }
  const instance = await runCLI({
    command: 'server', php: '8.3', wp: 'latest', port, workers: 1,
    login: false, internalCookieStore: false,
    wordpressInstallMode: existing ? 'install-from-existing-files-if-needed' : 'download-and-install',
    'mount-before-install': storage ? [{ hostPath: storage, vfsPath: '/wordpress' }] : [],
    mount: [{ hostPath: pluginPath, vfsPath: '/wordpress/wp-content/plugins/reel-together' }],
    blueprint: {
      steps: [
        { step: 'activatePlugin', pluginPath: 'reel-together/reel-together.php' },
        { step: 'runPHP', code: `<?php require '/wordpress/wp-load.php'; ${setup}` },
      ],
    },
  });
  // Playground currently binds to all interfaces. Restrict the finished preview to loopback.
  const address = instance.server.address();
  await new Promise((resolve, reject) => instance.server.close(error => error ? reject(error) : resolve()));
  await new Promise((resolve, reject) => {
    instance.server.once('error', reject);
    instance.server.listen(address.port, '127.0.0.1', resolve);
  });
  return instance;
}
