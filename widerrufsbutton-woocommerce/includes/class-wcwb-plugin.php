<?php
/**
 * Bootstrap des Plugins.
 *
 * @package WCWB
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hauptklasse: verbindet die einzelnen Module.
 */
final class WCWB_Plugin {

	/**
	 * Singleton-Instanz.
	 *
	 * @var WCWB_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Liefert die Instanz und initialisiert das Plugin beim ersten Aufruf.
	 *
	 * @return WCWB_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Konstruktor.
	 */
	private function __construct() {
		add_action(
			'init',
			function () {
				load_plugin_textdomain( 'widerrufsbutton-woocommerce', false, dirname( plugin_basename( WCWB_FILE ) ) . '/languages' );
			}
		);

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'notice_missing_woocommerce' ) );
			return;
		}

		WCWB_Withdrawals::init();
		WCWB_Settings::init();
		WCWB_Form::init();
		WCWB_Account::init();
		WCWB_Emails::init();

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_footer_link' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WCWB_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Aktivierung: legt die Widerrufsseite an, falls noch keine existiert.
	 */
	public static function activate() {
		WCWB_Withdrawals::register_post_type();

		$page_id = (int) get_option( 'wcwb_page_id' );
		if ( ! $page_id || 'publish' !== get_post_status( $page_id ) ) {
			$page_id = wp_insert_post(
				array(
					'post_title'   => __( 'Vertrag widerrufen', 'widerrufsbutton-woocommerce' ),
					'post_name'    => 'vertrag-widerrufen',
					'post_content' => '<!-- wp:shortcode -->[widerrufsformular]<!-- /wp:shortcode -->',
					'post_status'  => 'publish',
					'post_type'    => 'page',
				)
			);
			if ( $page_id && ! is_wp_error( $page_id ) ) {
				update_option( 'wcwb_page_id', (int) $page_id );
			}
		}

		add_option( 'wcwb_settings', WCWB_Settings::defaults() );
		flush_rewrite_rules();
	}

	/**
	 * Hinweis, wenn WooCommerce fehlt.
	 */
	public function notice_missing_woocommerce() {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Widerrufsbutton für WooCommerce benötigt ein aktives WooCommerce.', 'widerrufsbutton-woocommerce' ) . '</p></div>';
	}

	/**
	 * CSS einbinden.
	 */
	public function enqueue_assets() {
		wp_enqueue_style( 'wcwb-frontend', WCWB_URL . 'assets/css/frontend.css', array(), WCWB_VERSION );
	}

	/**
	 * Dauerhaft sichtbarer Link „Vertrag widerrufen“ im Footer jeder Seite.
	 */
	public function render_footer_link() {
		if ( ! WCWB_Settings::get( 'footer_link' ) ) {
			return;
		}
		$url = self::form_url();
		if ( ! $url ) {
			return;
		}
		printf(
			'<div class="wcwb-footer-link"><a class="wcwb-button" href="%1$s">%2$s</a></div>',
			esc_url( $url ),
			esc_html( self::button_label() )
		);
	}

	/**
	 * Link zu den Einstellungen in der Pluginliste.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=wcwb-settings' ) ) . '">' . esc_html__( 'Einstellungen', 'widerrufsbutton-woocommerce' ) . '</a>'
		);
		return $links;
	}

	/**
	 * URL der Seite mit dem Widerrufsformular.
	 *
	 * @param array $args Zusätzliche Query-Parameter.
	 * @return string Leer, wenn keine Seite konfiguriert ist.
	 */
	public static function form_url( $args = array() ) {
		$page_id = (int) get_option( 'wcwb_page_id' );
		if ( ! $page_id || 'publish' !== get_post_status( $page_id ) ) {
			return '';
		}
		return add_query_arg( $args, get_permalink( $page_id ) );
	}

	/**
	 * Gesetzlich vorgegebene Beschriftung der Widerrufsfunktion (§ 356a Abs. 1 BGB).
	 *
	 * @return string
	 */
	public static function button_label() {
		return __( 'Vertrag widerrufen', 'widerrufsbutton-woocommerce' );
	}

	/**
	 * Gesetzlich vorgegebene Beschriftung der Bestätigungsfunktion (§ 356a Abs. 2 BGB).
	 *
	 * @return string
	 */
	public static function confirm_label() {
		return __( 'Widerruf bestätigen', 'widerrufsbutton-woocommerce' );
	}
}
