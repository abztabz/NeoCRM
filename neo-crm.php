<?php
/**
 * Plugin Name: NeoCRM
 * Plugin URI:  https://108media.ae/
 * Description: First-party visitor intelligence and relationship management for WordPress.
 * Version:     0.1.0
 * Author:      108 Media
 * License:     GPL-2.0-or-later
 * Text Domain: neo-crm
 * Requires at least: 6.4
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NEOCRM_VERSION', '0.1.0' );
define( 'NEOCRM_FILE', __FILE__ );
define( 'NEOCRM_PATH', plugin_dir_path( __FILE__ ) );
define( 'NEOCRM_URL', plugin_dir_url( __FILE__ ) );

require_once NEOCRM_PATH . 'includes/class-neocrm-activator.php';
require_once NEOCRM_PATH . 'includes/class-neocrm-db.php';
require_once NEOCRM_PATH . 'includes/class-neocrm-tracker.php';
require_once NEOCRM_PATH . 'includes/class-neocrm-admin.php';
require_once NEOCRM_PATH . 'includes/class-neocrm-privacy.php';
require_once NEOCRM_PATH . 'includes/class-neocrm.php';

register_activation_hook( __FILE__, array( 'NeoCRM_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'NeoCRM_Activator', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		NeoCRM::instance();
	}
);

