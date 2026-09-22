<?php
/**
 * Plugin Name: Reel Together
 * Description: A private movie diary and shared household watchlist.
 * Version: 0.5.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: Reel Together
 * License: GPL-2.0-or-later
 * Text Domain: reel-together
 */

defined( 'ABSPATH' ) || exit;
define( 'RT_VERSION', '0.5.0' );
define( 'RT_PATH', plugin_dir_path( __FILE__ ) );
define( 'RT_URL', plugin_dir_url( __FILE__ ) );

require_once RT_PATH . 'includes/class-rt-store.php';
require_once RT_PATH . 'includes/class-rt-catalog.php';
require_once RT_PATH . 'includes/class-rt-api.php';
require_once RT_PATH . 'includes/class-rt-app.php';
require_once RT_PATH . 'includes/class-rt-invitations.php';
require_once RT_PATH . 'includes/class-rt-companions.php';
require_once RT_PATH . 'includes/class-rt-sharing.php';

register_activation_hook( __FILE__, array( 'RT_App', 'activate' ) );
add_action( 'plugins_loaded', function () {
    if ( get_option( 'rt_schema_version' ) !== RT_VERSION ) {
        RT_Store::install();
    }
    RT_App::init();
    RT_Invitations::init();
    add_action( 'rest_api_init', array( 'RT_API', 'register' ) );
} );
