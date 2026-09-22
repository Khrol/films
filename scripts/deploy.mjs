import { spawn } from 'node:child_process';
import { readFile, access, writeFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const site = 'https://films.khroliz.com';
const previousSite = 'https://domainfortest2345675.wpcomstaging.com';
const destination = 'domainfortest2345675.wordpress.com@ssh.wp.com';
const quote = text => `'${text.replaceAll("'", "'\\''")}'`;
const identity = path.join(root, '.local/wpcom-deploy');
const options = ['-T', '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'ConnectTimeout=15', '-o', 'StrictHostKeyChecking=yes', '-o', `UserKnownHostsFile=${path.join(root, '.local/wpcom_known_hosts')}`, '-i', identity, destination];

async function remotePHP(code, input = '') {
  return new Promise((resolve, reject) => {
    const child = spawn('ssh', [...options, `wp eval ${quote(code)}`], { stdio: ['pipe', 'pipe', 'pipe'] });
    let output = '';
    let errors = '';
    child.stdout.on('data', chunk => { output += chunk; });
    child.stderr.on('data', chunk => { errors += chunk; });
    child.on('error', reject);
    child.stdin.on('error', () => {});
    child.on('close', code => code === 0 ? resolve(output) : reject(new Error(`SSH/WP-CLI failed (${code}): ${errors || output}`)));
    child.stdin.end(input);
  });
}

await access(identity);
const guard = `if (!in_array(untrailingslashit(home_url()), array('${site}', '${previousSite}'), true)) { WP_CLI::error('Deployment target does not match the configured site.'); }`;
const preflight = JSON.parse(await remotePHP(`${guard}
  require_once ABSPATH . 'wp-admin/includes/plugin.php';
  $plugins = get_plugins();
  $ours = $plugins['reel-together/reel-together.php'] ?? null;
  if ($ours && $ours['Name'] !== 'Reel Together') { WP_CLI::error('A different plugin already uses this directory.'); }
  echo wp_json_encode(array('site'=>home_url(), 'wordpress'=>get_bloginfo('version'), 'php'=>PHP_VERSION, 'installed_version'=>$ours['Version'] ?? null));
`));
console.log('Target:', JSON.stringify(preflight));

if (process.argv.includes('--inspect-billing')) {
  const result = await remotePHP(`${guard}
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $plugins = array();
    foreach (get_plugins() as $file=>$plugin) {
      if (preg_match('/woocommerce|woopayments|subscriptions|stripe/i', $file . ' ' . $plugin['Name'])) {
        $plugins[] = array('file'=>$file, 'name'=>$plugin['Name'], 'version'=>$plugin['Version'], 'active'=>is_plugin_active($file));
      }
    }
    $gateways = array();
    if (function_exists('WC')) {
      foreach (WC()->payment_gateways()->payment_gateways() as $id=>$gateway) {
        $gateways[] = array('id'=>$id, 'enabled'=>$gateway->enabled === 'yes', 'subscriptions'=>$gateway->supports('subscriptions'), 'test_mode'=>$gateway->get_option('testmode') === 'yes');
      }
    }
    echo wp_json_encode(array('plugins'=>$plugins, 'gateways'=>$gateways, 'subscriptions_available'=>function_exists('wcs_get_users_subscriptions'),
      'currency'=>get_option('woocommerce_currency', ''), 'tax_enabled'=>get_option('woocommerce_calc_taxes', ''),
      'checkout_page'=>(int)get_option('woocommerce_checkout_page_id'), 'account_page'=>(int)get_option('woocommerce_myaccount_page_id')));
  `);
  console.log('Billing setup:', result.trim());
}

if (process.argv.includes('--make-homepage')) {
  const before = JSON.parse(await remotePHP(`${guard}
    if (!class_exists('RT_App')) { WP_CLI::error('Reel Together is not active.'); }
    $page = get_post((int)get_option('rt_page_id'));
    if (!$page || $page->post_type !== 'page' || $page->post_status !== 'publish' || !has_shortcode($page->post_content, 'reel_together')) {
      WP_CLI::error('The published movie diary page could not be verified.');
    }
    echo wp_json_encode(array('site'=>home_url(), 'show_on_front'=>get_option('show_on_front'), 'page_on_front'=>(int)get_option('page_on_front'), 'page_for_posts'=>(int)get_option('page_for_posts'), 'diary_page_id'=>$page->ID));
  `));
  const backup = path.join(root, '.local', `homepage-before-${new Date().toISOString().replaceAll(':', '-')}.json`);
  await writeFile(backup, JSON.stringify(before, null, 2) + '\n', { flag: 'wx', mode: 0o600 });
  const changed = await remotePHP(`${guard}
    $id = (int)get_option('rt_page_id');
    if ($id !== ${before.diary_page_id} || (int)get_option('page_on_front') !== ${before.page_on_front} || (int)get_option('page_for_posts') !== ${before.page_for_posts}) {
      WP_CLI::error('Homepage settings changed since the preflight check.');
    }
    update_option('page_on_front', $id);
    update_option('show_on_front', 'page');
    if ((int)get_option('page_for_posts') === $id) { update_option('page_for_posts', 0); }
    clean_post_cache($id);
    if ((int)get_option('page_on_front') !== $id || get_option('show_on_front') !== 'page') { WP_CLI::error('The homepage settings did not save.'); }
    echo wp_json_encode(array('homepage'=>home_url('/'), 'diary_url'=>RT_App::url(), 'page_on_front'=>$id));
  `);
  console.log('Previous homepage settings saved:', path.relative(root, backup));
  console.log('Homepage updated:', changed.trim());
}

if (process.argv.includes('--deploy')) {
  const zip = await readFile(path.join(root, 'dist/reel-together.zip'));
  const sha = createHash('sha256').update(zip).digest('hex');
  const installed = await remotePHP(`${guard}
    $zip = base64_decode(trim(stream_get_contents(STDIN)), true);
    if (!$zip || hash('sha256', $zip) !== '${sha}') { WP_CLI::error('Upload checksum does not match.'); }
    $temporary = wp_tempnam('reel-together');
    $archive = $temporary . '.zip';
    if (!rename($temporary, $archive) || file_put_contents($archive, $zip) === false) { WP_CLI::error('Could not prepare the plugin archive.'); }
    try {
      $result = WP_CLI::runcommand('plugin install ' . escapeshellarg($archive) . ' --activate${preflight.installed_version ? ' --force' : ''}', array('return'=>'all', 'exit_error'=>false));
      if ($result->return_code !== 0) { throw new Exception($result->stderr . $result->stdout); }
      echo $result->stdout;
    } finally { wp_delete_file($archive); }
  `, zip.toString('base64'));
  console.log(installed.trim());
}

if (process.argv.includes('--configure-kinopoisk')) {
  const key = (await readFile(path.join(root, '.local/kinopoisk-api-key.txt'), 'utf8')).trim();
  if (!/^[a-zA-Z0-9._-]{12,500}$/.test(key)) throw new Error('The local Kinopoisk API key file is empty or malformed.');
  await remotePHP(`${guard}
    $key = trim(stream_get_contents(STDIN));
    update_option('rt_kinopoisk_token', $key, false);
    echo 'Kinopoisk key configured.';
  `, key);
  console.log('Kinopoisk key configured securely.');
}

if (process.argv.includes('--deploy') || process.argv.includes('--verify') || process.argv.includes('--configure-kinopoisk') || process.argv.includes('--make-homepage')) {
  const result = await remotePHP(`${guard}
    if (!class_exists('RT_App')) { WP_CLI::error('Reel Together is not active.'); }
    global $wpdb;
    $tables = array();
    foreach (array('households','members','movies','entries','invitation_requests','companions','entry_shares','billing_accounts') as $name) {
      $table = RT_Store::table($name);
      $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
      if ($found !== $table) { WP_CLI::error('A required plugin table is missing.'); }
      $tables[] = $name;
    }
    $columns = $wpdb->get_col('SHOW COLUMNS FROM ' . RT_Store::table('movies'));
    foreach (array('imdb_id', 'imdb_rating', 'linked_kinopoisk_id', 'linked_imdb_id') as $column) {
      if (!in_array($column, $columns, true)) { WP_CLI::error('A required movie identity column is missing.'); }
    }
    $entry_columns = $wpdb->get_col('SHOW COLUMNS FROM ' . RT_Store::table('entries'));
    foreach (array('watch_company', 'companion_ids') as $column) {
      if (!in_array($column, $entry_columns, true)) { WP_CLI::error('A required viewing companion column is missing.'); }
    }
    $companion_columns = $wpdb->get_col('SHOW COLUMNS FROM ' . RT_Store::table('companions'));
    foreach (array('linked_user_id', 'linked_household_id') as $column) {
      if (!in_array($column, $companion_columns, true)) { WP_CLI::error('A required linked account column is missing.'); }
    }
    // Exercise the visibility queries on the hosted database without printing diary data.
    $probe_users = get_users(array('number'=>1, 'fields'=>'ID'));
    if (!$probe_users) { WP_CLI::error('No site account is available for the read check.'); }
    wp_set_current_user((int)$probe_users[0]);
    foreach (array('all', 'mine', 'personal', 'household') as $scope) {
      $wpdb->get_var('SELECT COUNT(*) FROM ' . RT_Store::table('entries') . ' e WHERE ' . RT_Store::visibility($scope));
      if ($wpdb->last_error) { WP_CLI::error('A diary visibility query failed.'); }
    }
    RT_Companions::visible();
    if ($wpdb->last_error) { WP_CLI::error('The companion visibility query failed.'); }
    wp_set_current_user(0);
    $anonymous = rest_do_request(new WP_REST_Request('GET', '/reel-together/v1/bootstrap'));
    if ($anonymous->get_status() !== 401) { WP_CLI::error('Anonymous access was not rejected.'); }
    echo wp_json_encode(array('version'=>RT_VERSION, 'page'=>RT_App::url(), 'schema'=>get_option('rt_schema_version'), 'tables'=>$tables, 'visibility_queries'=>'passed', 'anonymous_status'=>$anonymous->get_status(), 'kinopoisk_configured'=>(bool)RT_App::token('kinopoisk'), 'paid_access_enabled'=>RT_Membership::enabled(), 'stripe_test_ready'=>RT_Stripe::ready('test'), 'stripe_live_ready'=>RT_Stripe::ready('live')));
  `);
  console.log('Verified:', result.trim());
}

if (!process.argv.some(arg => ['--deploy', '--verify', '--configure-kinopoisk', '--make-homepage'].includes(arg))) {
  console.log('Read-only check complete. Use --deploy after tests and npm run package.');
}
