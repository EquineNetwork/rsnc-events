<?php
/**
 * Plugin Name:       RSNC Events
 * Description:       Shows RSNC's show calendar and published results on rsnc.us, read from the RSNC admin's events feed. Shortcodes: [rsnc_calendar] and [rsnc_results].
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Equine Network
 * License:           Proprietary
 * Text Domain:       rsnc-events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RSNC_EV_VERSION', '1.0.0' );
define( 'RSNC_EV_FILE', __FILE__ );
define( 'RSNC_EV_DIR', plugin_dir_path( __FILE__ ) );
define( 'RSNC_EV_URL', plugin_dir_url( __FILE__ ) );

require_once RSNC_EV_DIR . 'includes/class-rsnc-ev-feed.php';
require_once RSNC_EV_DIR . 'includes/class-rsnc-ev-settings.php';
require_once RSNC_EV_DIR . 'includes/class-rsnc-ev-render.php';

RSNC_EV_Settings::init();
RSNC_EV_Render::init();

register_uninstall_hook( __FILE__, 'rsnc_ev_uninstall' );

/** Removes the plugin's settings and cached feed copies. */
function rsnc_ev_uninstall() {
	delete_option( RSNC_EV_Settings::OPTION );
	RSNC_EV_Feed::clear_cache( true );
}
