<?php
/**
 * E-Mails: Eingangsbestätigung an den Verbraucher, Benachrichtigung an den Shop.
 *
 * @package WCWB
 */

defined( 'ABSPATH' ) || exit;

/**
 * E-Mail-Versand.
 */
class WCWB_Emails {

	/**
	 * Hooks registrieren.
	 */
	public static function init() {
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'order_email_link' ), 20, 4 );
	}

	/**
	 * Eingangsbestätigung auf dauerhaftem Datenträger (E-Mail) mit Inhalt
	 * des Widerrufs sowie Datum und Uhrzeit des Eingangs (§ 356a Abs. 3 BGB).
	 *
	 * @param array $r Datensatz.
	 * @return bool
	 */
	public static function send_confirmation( array $r ) {
		$shop    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: 1: Shopname, 2: Referenz */
		$subject = sprintf( __( '[%1$s] Eingangsbestätigung Ihres Widerrufs (%2$s)', 'widerrufsbutton-woocommerce' ), $shop, $r['reference'] );
		$heading = __( 'Eingangsbestätigung Ihres Widerrufs', 'widerrufsbutton-woocommerce' );

		$body  = '<p>' . sprintf(
			/* translators: %s: Name */
			esc_html__( 'Guten Tag %s,', 'widerrufsbutton-woocommerce' ),
			esc_html( $r['name'] )
		) . '</p>';
		$body .= '<p>' . sprintf(
			/* translators: %s: Datum und Uhrzeit */
			esc_html__( 'hiermit bestätigen wir den Eingang Ihres Widerrufs am %s mit folgendem Inhalt:', 'widerrufsbutton-woocommerce' ),
			'<strong>' . esc_html( WCWB_Withdrawals::format_time( $r['received'] ) ) . '</strong>'
		) . '</p>';
		$body .= self::table( $r );
		$body .= '<p>' . esc_html__( 'Bitte bewahren Sie diese E-Mail als Nachweis auf. Informationen zur Rücksendung und Erstattung erhalten Sie gegebenenfalls gesondert.', 'widerrufsbutton-woocommerce' ) . '</p>';

		$reply_to = get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) );

		return self::send( $r['email'], $subject, $heading, $body, $reply_to );
	}

	/**
	 * Benachrichtigung an den Shop.
	 *
	 * @param array $r Datensatz.
	 * @return bool
	 */
	public static function send_admin_notification( array $r ) {
		$to = WCWB_Settings::get( 'notify_email' );
		if ( ! is_email( $to ) ) {
			return false;
		}
		/* translators: 1: Referenz, 2: Bestellnummer */
		$subject = sprintf( __( 'Neuer Widerruf %1$s zu Bestellung %2$s', 'widerrufsbutton-woocommerce' ), $r['reference'], $r['order_number'] );
		$heading = __( 'Neuer Widerruf eingegangen', 'widerrufsbutton-woocommerce' );

		$body = self::table( $r );
		if ( ! $r['order_id'] ) {
			$body .= '<p><strong>' . esc_html__( 'Die Angaben konnten keiner Bestellung automatisch zugeordnet werden (Bestellnummer und E-Mail-Adresse passen nicht zusammen). Bitte manuell prüfen.', 'widerrufsbutton-woocommerce' ) . '</strong></p>';
		}
		$body .= '<p><a href="' . esc_url( admin_url( 'post.php?post=' . (int) $r['id'] . '&action=edit' ) ) . '">' . esc_html__( 'Widerruf im Backend ansehen', 'widerrufsbutton-woocommerce' ) . '</a></p>';

		return self::send( $to, $subject, $heading, $body, $r['email'] );
	}

	/**
	 * Inhalt des Widerrufs als HTML-Tabelle.
	 *
	 * @param array $r Datensatz.
	 * @return string
	 */
	private static function table( array $r ) {
		$rows = array(
			__( 'Referenz', 'widerrufsbutton-woocommerce' )        => $r['reference'],
			__( 'Eingang', 'widerrufsbutton-woocommerce' )         => WCWB_Withdrawals::format_time( $r['received'] ),
			__( 'Name', 'widerrufsbutton-woocommerce' )            => $r['name'],
			__( 'Bestellnummer', 'widerrufsbutton-woocommerce' )   => $r['order_number'],
			__( 'E-Mail-Adresse', 'widerrufsbutton-woocommerce' )  => $r['email'],
			__( 'Widerrufen wird', 'widerrufsbutton-woocommerce' ) => $r['items'] ? $r['items'] : __( 'der gesamte Vertrag', 'widerrufsbutton-woocommerce' ),
			__( 'Nachricht', 'widerrufsbutton-woocommerce' )       => $r['message'] ? $r['message'] : '–',
		);
		$html = '<table cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;border-color:#e5e5e5;margin-bottom:20px">';
		foreach ( $rows as $label => $value ) {
			$html .= '<tr><th scope="row" style="text-align:left;width:35%">' . esc_html( $label ) . '</th><td>' . nl2br( esc_html( $value ) ) . '</td></tr>';
		}
		return $html . '</table>';
	}

	/**
	 * Versand im WooCommerce-E-Mail-Layout.
	 *
	 * @param string $to       Empfänger.
	 * @param string $subject  Betreff.
	 * @param string $heading  Überschrift.
	 * @param string $body     HTML-Inhalt.
	 * @param string $reply_to Antwortadresse.
	 * @return bool
	 */
	private static function send( $to, $subject, $heading, $body, $reply_to = '' ) {
		$mailer  = WC()->mailer();
		$message = $mailer->wrap_message( $heading, $body );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}
		$sent = $mailer->send( $to, $subject, $message, $headers );
		if ( ! $sent ) {
			wc_get_logger()->error( sprintf( 'Widerrufs-E-Mail an %s konnte nicht gesendet werden (%s).', $to, $subject ), array( 'source' => 'widerrufsbutton' ) );
		}
		return (bool) $sent;
	}

	/**
	 * Link „Vertrag widerrufen“ in Kunden-E-Mails zu Bestellungen.
	 *
	 * @param WC_Order $order         Bestellung.
	 * @param bool     $sent_to_admin An Admin?
	 * @param bool     $plain_text    Nur-Text?
	 * @param WC_Email $email         E-Mail-Objekt.
	 */
	public static function order_email_link( $order, $sent_to_admin, $plain_text, $email = null ) {
		if ( $sent_to_admin || ! WCWB_Settings::get( 'email_link' ) || ! $order instanceof WC_Order ) {
			return;
		}
		$allowed = apply_filters( 'wcwb_email_ids', array( 'customer_processing_order', 'customer_completed_order', 'customer_on_hold_order', 'customer_invoice' ) );
		if ( $email && ! in_array( $email->id, $allowed, true ) ) {
			return;
		}
		$url = WCWB_Plugin::form_url();
		if ( ! $url ) {
			return;
		}
		if ( $plain_text ) {
			echo "\n" . esc_html( WCWB_Plugin::button_label() ) . ': ' . esc_url_raw( $url ) . "\n\n";
			return;
		}
		printf(
			'<p style="margin:0 0 16px"><a href="%1$s" style="font-weight:bold">%2$s</a></p>',
			esc_url( $url ),
			esc_html( WCWB_Plugin::button_label() )
		);
	}
}
