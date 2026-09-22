import { randomBytes } from 'node:crypto';
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import path from 'node:path';
import { startWordPress, projectRoot } from './wordpress.mjs';

const local = path.join(projectRoot, '.local');
await mkdir(local, { recursive: true });
const credentialsPath = path.join(local, 'credentials.json');
let credentials;
try { credentials = JSON.parse(await readFile(credentialsPath, 'utf8')); }
catch {
  credentials = { admin: { username: 'admin', password: randomBytes(18).toString('hex') }, preview: { username: 'moviebuff', password: 'movie-night' } };
  await writeFile(credentialsPath, `${JSON.stringify(credentials, null, 2)}\n`, { mode: 0o600 });
}
const encoded = Buffer.from(JSON.stringify(credentials)).toString('base64');
const instance = await startWordPress({
  storage: path.join(local, 'wordpress'), port: Number(process.env.REEL_PORT || 9400),
  setup: `
    if (!get_option('rt_local_initialized')) {
      $credentials = json_decode(base64_decode('${encoded}'), true);
      $admin = get_user_by('login', 'admin');
      if ($admin) { wp_set_password($credentials['admin']['password'], $admin->ID); }
      if (!username_exists('moviebuff')) {
        $id = wp_insert_user(array('user_login'=>'moviebuff', 'user_pass'=>$credentials['preview']['password'], 'user_email'=>'moviebuff@example.test', 'display_name'=>'Movie lover', 'role'=>'subscriber'));
        if (is_wp_error($id)) { throw new Exception($id->get_error_message()); }
      }
      update_option('blogname', 'Reel Together');
      update_option('blog_public', 0);
      update_option('users_can_register', 0);
      update_option('timezone_string', 'Europe/Vilnius');
      update_option('show_on_front', 'page');
      update_option('page_on_front', get_option('rt_page_id'));
      update_option('rt_local_initialized', 1);
    }
  `,
});
console.log(`\nReel Together is ready at ${instance.serverUrl}/`);
console.log(`Local preview login: ${credentials.preview.username} / ${credentials.preview.password}`);
console.log('Admin credentials: .local/credentials.json');
console.log('Data is saved in .local/wordpress. Press Ctrl+C to stop.\n');
let stopping = false;
async function stop() {
  if (stopping) return;
  stopping = true;
  await instance[Symbol.asyncDispose]();
  process.exit(0);
}
process.on('SIGINT', stop);
process.on('SIGTERM', stop);
