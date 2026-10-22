<?php
/**
 * Button „Vertrag widerrufen“ im WooCommerce-Kundenkonto.
 *
 * @package WCWB
 */

defined( 'ABSPATH' ) || exit;

/**
 * Integration in „Mein Konto“.
 */
class WCWB_Account {

	/**
	 * Hooks registrieren.
	 */
	public static function init() {
		add_filter( 'woocommerce_my_account_my_orders_actions', array( __CLASS__, 'order_action' ), 10, 2 );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'order_details_button' ) );
	}

	/**
	 * Darf für diese Bestellung der Button angezeigt werden?
	 *
	 * @param WC_Order $order Bestellung.
	 * @return bool
	 */
	public static function is_eligible( $order ) {
		if ( ! $order instanceof WC_Order || $order->get_meta( '_wcwb_withdrawn' ) ) {
			return false;
		}
		if ( in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed', 'pending', 'checkout-draft' ), true ) ) {
			return false;
		}
		$start = $order->get_date_completed() ? $order->get_date_completed() : $order->get_date_created();
		if ( ! $start ) {
			return false;
		}
		$days     = (int) WCWB_Settings::get( 'account_days' );
		$eligible = time() <= $start->getTimestamp() + $days * DAY_IN_SECONDS;

		/**
		 * Anzeige des Buttons je Bestellung anpassen.
		 *
		 * @param bool     $eligible Anzeigen?
		 * @param WC_Order $order    Bestellung.
		 */
		return (bool) apply_filters( 'wcwb_order_is_eligible', $eligible, $order );
	}

	/**
	 * Aktion in der Bestellübersicht.
	 *
	 * @param array    $actions Aktionen.
	 * @param WC_Order $order   Bestellung.
	 * @return array
	 */
	public static function order_action( $actions, $order ) {
		$url = WCWB_Plugin::form_url( array( 'wcwb_order' => $order->get_id() ) );
		if ( $url && self::is_eligible( $order ) ) {
			$actions['wcwb_withdraw'] = array(
				'url'  => $url,
				'name' => WCWB_Plugin::button_label(),
			);
		}
		return $actions;
	}

	/**
	 * Button in der Bestelldetailansicht.
	 *
	 * @param WC_Order $order Bestellung.
	 */
	public static function order_details_button( $order ) {
		if ( ! is_account_page() ) {
			return;
		}
		$url = WCWB_Plugin::form_url( array( 'wcwb_order' => $order->get_id() ) );
		if ( $url && self::is_eligible( $order ) ) {
			printf( '<p><a class="button wcwb-button" href="%1$s">%2$s</a></p>', esc_url( $url ), esc_html( WCWB_Plugin::button_label() ) );
		} elseif ( $order->get_meta( '_wcwb_withdrawn' ) ) {
			/* translators: %s: Referenz */
			echo '<p class="wcwb-note">' . esc_html( sprintf( __( 'Für diese Bestellung ist ein Widerruf eingegangen (Referenz %s).', 'widerrufsbutton-woocommerce' ), $order->get_meta( '_wcwb_withdrawn' ) ) ) . '</p>';
		}
	}
}
