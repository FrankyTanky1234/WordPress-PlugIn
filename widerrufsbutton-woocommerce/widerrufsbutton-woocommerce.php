<?php
/**
 * Plugin Name:       Widerrufsbutton für WooCommerce
 * Description:       Elektronische Widerrufsfunktion („Vertrag widerrufen“ / „Widerruf bestätigen“) nach Art. 11a RL 2011/83/EU i. d. F. der RL (EU) 2023/2673 bzw. § 356a BGB – inkl. Eingangsbestätigung per E-Mail.
 * Version:           1.0.2
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.1
 * WC tested up to:   11.2
 * Author:            Frank Rapp
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       widerrufsbutton-woocommerce
 * Domain Path:       /languages
 *
 * @package WCWB
 */

defined( 'ABSPATH' ) || exit;

define( 'WCWB_VERSION', '1.0.2' );
define( 'WCWB_FILE', __FILE__ );
define( 'WCWB_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCWB_URL', plugin_dir_url( __FILE__ ) );

require_once WCWB_DIR . 'includes/class-wcwb-settings.php';
require_once WCWB_DIR . 'includes/class-wcwb-withdrawals.php';
require_once WCWB_DIR . 'includes/class-wcwb-emails.php';
require_once WCWB_DIR . 'includes/class-wcwb-form.php';
require_once WCWB_DIR . 'includes/class-wcwb-account.php';
require_once WCWB_DIR . 'includes/class-wcwb-plugin.php';

// Kompatibilität mit WooCommerce High-Performance Order Storage (HPOS).
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WCWB_FILE, true );
		}
	}
);

register_activation_hook( WCWB_FILE, array( 'WCWB_Plugin', 'activate' ) );

add_action( 'plugins_loaded', array( 'WCWB_Plugin', 'instance' ) );
