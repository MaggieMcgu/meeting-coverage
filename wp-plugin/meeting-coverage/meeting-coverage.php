<?php
/**
 * Plugin Name:       Meeting Coverage
 * Plugin URI:        https://github.com/maggiemcgu/meeting-coverage
 * Description:       Automates local government meeting coverage drafts using AI. Watches YouTube channels, fetches public agendas, and generates "Meeting at a Glance" drafts with VERIFY flags for human review.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Maggie McGuire
 * Author URI:        https://maggie-mcguire.com
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       meeting-coverage
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MAAG_VERSION',         '1.0.0' );
define( 'MAAG_PLUGIN_DIR',      plugin_dir_path( __FILE__ ) );
define( 'MAAG_PLUGIN_URL',      plugin_dir_url( __FILE__ ) );
// Default relay URL — update this after deploying your relay to Vercel
define( 'MAAG_DEFAULT_RELAY',   'https://transcript.maggie-mcguire.com' );

require_once MAAG_PLUGIN_DIR . 'includes/class-youtube.php';
require_once MAAG_PLUGIN_DIR . 'includes/class-pipeline.php';
require_once MAAG_PLUGIN_DIR . 'includes/class-settings.php';
require_once MAAG_PLUGIN_DIR . 'includes/class-body-cpt.php';
require_once MAAG_PLUGIN_DIR . 'includes/class-rest-api.php';

function maag_init() {
    new MAAG_Settings();
    new MAAG_Body_CPT();
    new MAAG_Rest_API();
}
add_action( 'plugins_loaded', 'maag_init' );

register_activation_hook( __FILE__, 'maag_activate' );
register_deactivation_hook( __FILE__, 'maag_deactivate' );

function maag_activate() {
    MAAG_Body_CPT::register_cpt();
    if ( ! wp_next_scheduled( 'maag_check_feeds' ) ) {
        wp_schedule_event( time(), 'maag_30min', 'maag_check_feeds' );
    }
    flush_rewrite_rules();
}

function maag_deactivate() {
    $ts = wp_next_scheduled( 'maag_check_feeds' );
    if ( $ts ) {
        wp_unschedule_event( $ts, 'maag_check_feeds' );
    }
    flush_rewrite_rules();
}
