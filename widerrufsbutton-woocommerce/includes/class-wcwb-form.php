<?php
/**
 * Zweistufiges Widerrufsformular (Shortcode [widerrufsformular]).
 *
 * Schritt 1: Angaben (Name, Vertrag/Bestellung, E-Mail) – erreichbar über „Vertrag widerrufen“.
 * Schritt 2: Zusammenfassung mit Bestätigungsfunktion „Widerruf bestätigen“.
 * Danach:    Speicherung, Eingangsbestätigung per E-Mail, Erfolgsseite (Post/Redirect/Get).
 *
 * @package WCWB
 */

defined( 'ABSPATH' ) || exit;

/**
 * Formularlogik.
 */
class WCWB_Form {

	const NONCE = 'wcwb_confirm';

	/**
	 * Fehlermeldungen des aktuellen Requests.
	 *
	 * @var string[]
	 */
	private static $errors = array();

	/**
	 * Hooks registrieren.
	 */
	public static function init() {
		add_shortcode( 'widerrufsformular', array( __CLASS__, 'shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_confirm' ) );
	}

	/**
	 * Formulardaten aus POST bzw. Vorbelegung ermitteln.
	 *
	 * @return array
	 */
	private static function posted_data() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Schritt 1/2 haben keine Seiteneffekte; Schritt 3 prüft die Nonce.
		$items = isset( $_POST['wcwb_items'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wcwb_items'] ) ) : '';
		if ( ! empty( $_POST['wcwb_items_sel'] ) && is_array( $_POST['wcwb_items_sel'] ) ) {
			$selected = array_map( 'sanitize_text_field', wp_unslash( $_POST['wcwb_items_sel'] ) );
			$items    = trim( implode( "\n", $selected ) . "\n" . $items );
		}
		$data = array(
			'name'         => isset( $_POST['wcwb_name'] ) ? sanitize_text_field( wp_unslash( $_POST['wcwb_name'] ) ) : '',
			'email'        => isset( $_POST['wcwb_email'] ) ? sanitize_email( wp_unslash( $_POST['wcwb_email'] ) ) : '',
			'order_number' => isset( $_POST['wcwb_order_number'] ) ? sanitize_text_field( wp_unslash( $_POST['wcwb_order_number'] ) ) : '',
			'items'        => $items,
			'message'      => isset( $_POST['wcwb_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wcwb_message'] ) ) : '',
		);
		// phpcs:enable
		return $data;
	}

	/**
	 * Pflichtangaben prüfen.
	 *
	 * @param array $data Daten.
	 * @return string[] Fehlermeldungen.
	 */
	private static function validate( array $data ) {
		$errors = array();
		if ( '' === $data['name'] ) {
			$errors[] = __( 'Bitte geben Sie Ihren Namen an.', 'widerrufsbutton-woocommerce' );
		}
		if ( '' === $data['order_number'] ) {
			$errors[] = __( 'Bitte geben Sie die Bestellnummer an, damit wir den Vertrag zuordnen können.', 'widerrufsbutton-woocommerce' );
		}
		if ( ! is_email( $data['email'] ) ) {
			$errors[] = __( 'Bitte geben Sie eine gültige E-Mail-Adresse an – an diese senden wir die Eingangsbestätigung.', 'widerrufsbutton-woocommerce' );
		}
		return $errors;
	}

	/**
	 * Honeypot ausgefüllt? (Spam-Schutz ohne Captcha, um Verbraucher nicht zu behindern.)
	 *
	 * @return bool
	 */
	private static function is_spam() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return ! empty( $_POST['wcwb_website'] );
	}

	/**
	 * Schritt 3: Bestätigung verarbeiten, speichern, E-Mails senden, weiterleiten.
	 */
	public static function handle_confirm() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) || ! isset( $_POST['wcwb_step'] ) || 'confirm' !== $_POST['wcwb_step'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput
			return;
		}

		$nonce = isset( $_POST['_wcwb_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wcwb_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			// Kein harter Abbruch: Schritt 2 erneut anzeigen, mit frischer Nonce.
			self::$errors[] = __( 'Die Sitzung ist abgelaufen. Bitte prüfen Sie Ihre Angaben und klicken Sie erneut auf „Widerruf bestätigen“.', 'widerrufsbutton-woocommerce' );
			return;
		}

		$data = self::posted_data();
		if ( self::is_spam() ) {
			wp_safe_redirect( self::current_url() );
			exit;
		}

		self::$errors = self::validate( $data );
		if ( self::$errors ) {
			return;
		}

		// Doppelte Übermittlung (Doppelklick, Reload) abfangen.
		$token    = isset( $_POST['wcwb_token'] ) && is_string( $_POST['wcwb_token'] ) ? preg_replace( '/[^a-zA-Z0-9]/', '', wp_unslash( $_POST['wcwb_token'] ) ) : '';
		$existing = $token ? get_transient( 'wcwb_tok_' . $token ) : false;
		if ( $existing ) {
			self::redirect_done( (int) $existing );
		}

		if ( self::rate_limited() ) {
			self::$errors[] = __( 'Es wurden zu viele Widerrufe in kurzer Zeit übermittelt. Bitte versuchen Sie es später erneut oder kontaktieren Sie uns per E-Mail.', 'widerrufsbutton-woocommerce' );
			return;
		}

		$record = WCWB_Withdrawals::create( $data );
		if ( is_wp_error( $record ) ) {
			self::$errors[] = __( 'Der Widerruf konnte technisch nicht gespeichert werden. Bitte versuchen Sie es erneut oder erklären Sie den Widerruf per E-Mail.', 'widerrufsbutton-woocommerce' );
			return;
		}

		if ( $token ) {
			set_transient( 'wcwb_tok_' . $token, $record['id'], DAY_IN_SECONDS );
		}

		$record['mail_sent'] = WCWB_Emails::send_confirmation( $record ) ? 1 : 0;
		update_post_meta( $record['id'], '_wcwb_mail_sent', $record['mail_sent'] );
		WCWB_Emails::send_admin_notification( $record );

		self::redirect_done( $record['id'] );
	}

	/**
	 * Zur Erfolgsseite weiterleiten.
	 *
	 * @param int $id Widerrufs-ID.
	 */
	private static function redirect_done( $id ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'wcwb_done' => $id,
					'wcwb_key'  => self::done_key( $id ),
				),
				self::current_url()
			)
		);
		exit;
	}

	/**
	 * Schlüssel, damit nur der Absender die Erfolgsseite mit seinen Daten sieht.
	 *
	 * @param int $id ID.
	 * @return string
	 */
	private static function done_key( $id ) {
		return substr( wp_hash( 'wcwb_done|' . (int) $id ), 0, 20 );
	}

	/**
	 * Einfache Begrenzung: max. 10 Widerrufe pro Stunde und IP.
	 *
	 * @return bool
	 */
	private static function rate_limited() {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key   = 'wcwb_rl_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= (int) apply_filters( 'wcwb_rate_limit', 10 ) ) {
			return true;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return false;
	}

	/**
	 * URL der aktuellen Seite ohne Formularparameter.
	 *
	 * @return string
	 */
	private static function current_url() {
		$url = WCWB_Plugin::form_url();
		if ( ! $url ) {
			$url = get_permalink();
		}
		return $url ? $url : home_url( '/' );
	}

	/**
	 * Shortcode-Ausgabe.
	 *
	 * @return string
	 */
	public static function shortcode() {
		ob_start();
		echo '<div class="wcwb">';

		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['wcwb_done'], $_GET['wcwb_key'] ) ) {
			$id  = absint( $_GET['wcwb_done'] );
			$key = sanitize_text_field( wp_unslash( $_GET['wcwb_key'] ) );
			if ( $id && hash_equals( self::done_key( $id ), $key ) && WCWB_Withdrawals::POST_TYPE === get_post_type( $id ) ) {
				self::render_done( WCWB_Withdrawals::get( $id ) );
				echo '</div>';
				return ob_get_clean();
			}
		}

		$step = isset( $_POST['wcwb_step'] ) ? sanitize_key( $_POST['wcwb_step'] ) : '';
		// phpcs:enable

		if ( 'review' === $step || 'confirm' === $step ) {
			// Bei „confirm“ landen wir nur hier, wenn handle_confirm() nicht weitergeleitet hat.
			$data         = self::posted_data();
			$field_errors = self::validate( $data );
			if ( $field_errors ) {
				self::$errors = $field_errors;
				self::render_form( $data );
			} else {
				self::render_review( $data );
			}
		} elseif ( 'edit' === $step ) {
			self::render_form( self::posted_data() );
		} else {
			self::render_form( self::prefill() );
		}

		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Vorbelegung für eingeloggte Kunden (aus dem Kundenkonto / Bestellung).
	 *
	 * @return array
	 */
	private static function prefill() {
		$data = array(
			'name'         => '',
			'email'        => '',
			'order_number' => '',
			'items'        => '',
			'message'      => '',
			'order_items'  => array(),
		);

		if ( ! is_user_logged_in() ) {
			return $data;
		}

		$user          = wp_get_current_user();
		$data['name']  = trim( $user->first_name . ' ' . $user->last_name );
		$data['email'] = $user->user_email;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_id = isset( $_GET['wcwb_order'] ) ? absint( $_GET['wcwb_order'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( $order && (int) $order->get_customer_id() === get_current_user_id() ) {
			$data['name']         = trim( $order->get_formatted_billing_full_name() ) ? $order->get_formatted_billing_full_name() : $data['name'];
			$data['email']        = $order->get_billing_email() ? $order->get_billing_email() : $data['email'];
			$data['order_number'] = $order->get_order_number();
			foreach ( $order->get_items() as $item ) {
				$data['order_items'][] = sprintf( '%d × %s', $item->get_quantity(), $item->get_name() );
			}
		}
		return $data;
	}

	/**
	 * Fehler ausgeben.
	 */
	private static function render_errors() {
		if ( ! self::$errors ) {
			return;
		}
		echo '<div class="wcwb-errors" role="alert"><ul>';
		foreach ( self::$errors as $error ) {
			echo '<li>' . esc_html( $error ) . '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Schritt 1: Eingabeformular.
	 *
	 * @param array $d Daten.
	 */
	private static function render_form( array $d ) {
		$order_items = isset( $d['order_items'] ) ? $d['order_items'] : array();
		?>
		<p><?php esc_html_e( 'Hier können Sie Ihren Vertrag elektronisch widerrufen. Bitte geben Sie Ihren Namen, die Bestellnummer und Ihre E-Mail-Adresse an. Eine Begründung ist nicht erforderlich. Nach dem Absenden erhalten Sie umgehend eine Eingangsbestätigung per E-Mail.', 'widerrufsbutton-woocommerce' ); ?></p>
		<?php self::render_errors(); ?>
		<form class="wcwb-form" method="post" action="<?php echo esc_url( self::current_url() ); ?>" novalidate>
			<input type="hidden" name="wcwb_step" value="review">

			<p class="wcwb-field">
				<label for="wcwb_name"><?php esc_html_e( 'Name', 'widerrufsbutton-woocommerce' ); ?> <span aria-hidden="true">*</span></label>
				<input type="text" id="wcwb_name" name="wcwb_name" value="<?php echo esc_attr( $d['name'] ); ?>" autocomplete="name" required>
			</p>
			<p class="wcwb-field">
				<label for="wcwb_order_number"><?php esc_html_e( 'Bestellnummer', 'widerrufsbutton-woocommerce' ); ?> <span aria-hidden="true">*</span></label>
				<input type="text" id="wcwb_order_number" name="wcwb_order_number" value="<?php echo esc_attr( $d['order_number'] ); ?>" required>
				<small><?php esc_html_e( 'Sie finden die Bestellnummer in Ihrer Bestellbestätigung. Haben Sie sie nicht zur Hand, geben Sie stattdessen das Bestelldatum an.', 'widerrufsbutton-woocommerce' ); ?></small>
			</p>
			<p class="wcwb-field">
				<label for="wcwb_email"><?php esc_html_e( 'E-Mail-Adresse für die Eingangsbestätigung', 'widerrufsbutton-woocommerce' ); ?> <span aria-hidden="true">*</span></label>
				<input type="email" id="wcwb_email" name="wcwb_email" value="<?php echo esc_attr( $d['email'] ); ?>" autocomplete="email" required>
			</p>

			<?php if ( $order_items ) : ?>
				<fieldset class="wcwb-field">
					<legend><?php esc_html_e( 'Nur einzelne Artikel widerrufen? (optional – ohne Auswahl gilt der Widerruf für den gesamten Vertrag)', 'widerrufsbutton-woocommerce' ); ?></legend>
					<?php foreach ( $order_items as $line ) : ?>
						<label class="wcwb-check"><input type="checkbox" name="wcwb_items_sel[]" value="<?php echo esc_attr( $line ); ?>"> <?php echo esc_html( $line ); ?></label>
					<?php endforeach; ?>
				</fieldset>
			<?php else : ?>
				<p class="wcwb-field">
					<label for="wcwb_items"><?php esc_html_e( 'Betroffene Artikel (optional – leer lassen, um den gesamten Vertrag zu widerrufen)', 'widerrufsbutton-woocommerce' ); ?></label>
					<textarea id="wcwb_items" name="wcwb_items" rows="3"><?php echo esc_textarea( $d['items'] ); ?></textarea>
				</p>
			<?php endif; ?>

			<p class="wcwb-field">
				<label for="wcwb_message"><?php esc_html_e( 'Nachricht an uns (optional)', 'widerrufsbutton-woocommerce' ); ?></label>
				<textarea id="wcwb_message" name="wcwb_message" rows="3"><?php echo esc_textarea( $d['message'] ); ?></textarea>
			</p>

			<p class="wcwb-hp" aria-hidden="true">
				<label for="wcwb_website">Website</label>
				<input type="text" id="wcwb_website" name="wcwb_website" tabindex="-1" autocomplete="off">
			</p>

			<p><button type="submit" class="wcwb-button"><?php esc_html_e( 'Weiter zur Bestätigung', 'widerrufsbutton-woocommerce' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Schritt 2: Zusammenfassung mit Bestätigungsfunktion.
	 *
	 * @param array $d Daten.
	 */
	private static function render_review( array $d ) {
		$fields = array(
			'wcwb_name'         => $d['name'],
			'wcwb_email'        => $d['email'],
			'wcwb_order_number' => $d['order_number'],
			'wcwb_items'        => $d['items'],
			'wcwb_message'      => $d['message'],
		);
		// Honeypot-Wert in Schritt 2 mitführen, damit die Spam-Prüfung beim Bestätigen greift.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$honeypot = isset( $_POST['wcwb_website'] ) && is_string( $_POST['wcwb_website'] ) ? sanitize_text_field( wp_unslash( $_POST['wcwb_website'] ) ) : '';
		?>
		<h2><?php esc_html_e( 'Bitte prüfen Sie Ihre Angaben', 'widerrufsbutton-woocommerce' ); ?></h2>
		<?php self::render_errors(); ?>
		<?php self::render_summary( $d ); ?>
		<p><?php esc_html_e( 'Mit Klick auf „Widerruf bestätigen“ erklären Sie den Widerruf des oben genannten Vertrags.', 'widerrufsbutton-woocommerce' ); ?></p>

		<form class="wcwb-form" method="post" action="<?php echo esc_url( self::current_url() ); ?>">
			<input type="hidden" name="wcwb_step" value="confirm">
			<input type="hidden" name="wcwb_token" value="<?php echo esc_attr( wp_generate_password( 20, false ) ); ?>">
			<?php wp_nonce_field( self::NONCE, '_wcwb_nonce' ); ?>
			<input type="hidden" name="wcwb_website" value="<?php echo esc_attr( $honeypot ); ?>">
			<?php foreach ( $fields as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<?php endforeach; ?>
			<p class="wcwb-actions">
				<button type="submit" class="wcwb-button wcwb-button-confirm"><?php echo esc_html( WCWB_Plugin::confirm_label() ); ?></button>
			</p>
		</form>

		<form method="post" action="<?php echo esc_url( self::current_url() ); ?>">
			<input type="hidden" name="wcwb_step" value="edit">
			<?php foreach ( $fields as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<?php endforeach; ?>
			<p><button type="submit" class="wcwb-link"><?php esc_html_e( 'Angaben ändern', 'widerrufsbutton-woocommerce' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Erfolgsseite.
	 *
	 * @param array $r Datensatz.
	 */
	private static function render_done( array $r ) {
		?>
		<div class="wcwb-success" role="status">
			<h2><?php esc_html_e( 'Ihr Widerruf ist bei uns eingegangen', 'widerrufsbutton-woocommerce' ); ?></h2>
			<p>
				<?php
				if ( '0' === (string) get_post_meta( $r['id'], '_wcwb_mail_sent', true ) ) {
					printf(
						/* translators: 1: E-Mail-Adresse */
						esc_html__( 'Die Eingangsbestätigung per E-Mail an %1$s konnte leider nicht versendet werden. Ihr Widerruf ist trotzdem gespeichert. Bitte speichern oder drucken Sie diese Seite als Nachweis – wir melden uns bei Ihnen.', 'widerrufsbutton-woocommerce' ),
						'<strong>' . esc_html( $r['email'] ) . '</strong>'
					);
				} else {
					printf(
						/* translators: 1: E-Mail-Adresse */
						esc_html__( 'Eine Eingangsbestätigung mit dem Inhalt Ihres Widerrufs sowie Datum und Uhrzeit des Eingangs wurde an %1$s gesendet.', 'widerrufsbutton-woocommerce' ),
						'<strong>' . esc_html( $r['email'] ) . '</strong>'
					);
				}
				?>
			</p>
		</div>
		<?php
		self::render_summary( $r, true );
	}

	/**
	 * Tabelle mit dem Inhalt des Widerrufs.
	 *
	 * @param array $d         Daten.
	 * @param bool  $with_meta Referenz und Eingangszeit anzeigen.
	 */
	private static function render_summary( array $d, $with_meta = false ) {
		$rows = array();
		if ( $with_meta ) {
			$rows[ __( 'Referenz', 'widerrufsbutton-woocommerce' ) ]           = $d['reference'];
			$rows[ __( 'Eingang', 'widerrufsbutton-woocommerce' ) ]            = WCWB_Withdrawals::format_time( $d['received'] );
		}
		$rows[ __( 'Name', 'widerrufsbutton-woocommerce' ) ]                  = $d['name'];
		$rows[ __( 'Bestellnummer', 'widerrufsbutton-woocommerce' ) ]         = $d['order_number'];
		$rows[ __( 'E-Mail-Adresse', 'widerrufsbutton-woocommerce' ) ]        = $d['email'];
		$rows[ __( 'Widerrufen wird', 'widerrufsbutton-woocommerce' ) ]       = $d['items'] ? $d['items'] : __( 'der gesamte Vertrag', 'widerrufsbutton-woocommerce' );
		if ( $d['message'] ) {
			$rows[ __( 'Nachricht', 'widerrufsbutton-woocommerce' ) ] = $d['message'];
		}
		echo '<table class="wcwb-summary"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . nl2br( esc_html( $value ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
