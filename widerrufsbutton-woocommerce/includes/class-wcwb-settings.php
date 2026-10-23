<?php
/**
 * Einstellungsseite.
 *
 * @package WCWB
 */

defined( 'ABSPATH' ) || exit;

/**
 * Einstellungen unter WooCommerce → Widerrufsbutton.
 */
class WCWB_Settings {

	const OPTION = 'wcwb_settings';

	/**
	 * Hooks registrieren.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 60 );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Standardwerte.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'footer_link'  => 1,
			'email_link'   => 1,
			'account_days' => 30,
			'notify_email' => get_option( 'admin_email' ),
			'mark_order'   => 1,
		);
	}

	/**
	 * Einzelne Einstellung lesen.
	 *
	 * @param string $key Schlüssel.
	 * @return mixed
	 */
	public static function get( $key ) {
		$settings = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Menüpunkt anlegen.
	 */
	public static function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Widerrufsbutton – Einstellungen', 'widerrufsbutton-woocommerce' ),
			'↳ ' . __( 'Einstellungen', 'widerrufsbutton-woocommerce' ),
			'manage_woocommerce',
			'wcwb-settings',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Settings API registrieren.
	 */
	public static function register() {
		register_setting(
			'wcwb',
			self::OPTION,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) )
		);
		register_setting(
			'wcwb',
			'wcwb_page_id',
			array( 'sanitize_callback' => 'absint' )
		);
	}

	/**
	 * Eingaben bereinigen.
	 *
	 * @param array $input Rohwerte.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input = (array) $input;
		$email = isset( $input['notify_email'] ) ? sanitize_email( $input['notify_email'] ) : '';
		return array(
			'footer_link'  => empty( $input['footer_link'] ) ? 0 : 1,
			'email_link'   => empty( $input['email_link'] ) ? 0 : 1,
			'account_days' => isset( $input['account_days'] ) ? max( 14, absint( $input['account_days'] ) ) : 30,
			'notify_email' => is_email( $email ) ? $email : get_option( 'admin_email' ),
			'mark_order'   => empty( $input['mark_order'] ) ? 0 : 1,
		);
	}

	/**
	 * Einstellungsseite ausgeben.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s       = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		$page_id = (int) get_option( 'wcwb_page_id' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Widerrufsbutton – Einstellungen', 'widerrufsbutton-woocommerce' ); ?></h1>
			<p><?php esc_html_e( 'Die Widerrufsfunktion muss während der gesamten Widerrufsfrist gut sichtbar und leicht zugänglich sein. Lassen Sie den Footer-Link daher aktiviert, sofern Sie die Seite nicht anderweitig prominent verlinken.', 'widerrufsbutton-woocommerce' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'wcwb' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wcwb_page_id"><?php esc_html_e( 'Seite mit Widerrufsformular', 'widerrufsbutton-woocommerce' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_pages(
								array(
									'name'              => 'wcwb_page_id',
									'id'                => 'wcwb_page_id',
									'selected'          => $page_id, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
									'show_option_none'  => esc_html__( '— Seite wählen —', 'widerrufsbutton-woocommerce' ),
									'option_none_value' => 0,
								)
							);
							?>
							<p class="description"><?php esc_html_e( 'Die Seite muss den Shortcode [widerrufsformular] enthalten.', 'widerrufsbutton-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Sichtbarkeit', 'widerrufsbutton-woocommerce' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[footer_link]" value="1" <?php checked( $s['footer_link'] ); ?>> <?php esc_html_e( 'Button „Vertrag widerrufen“ im Footer jeder Seite anzeigen', 'widerrufsbutton-woocommerce' ); ?></label><br>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[email_link]" value="1" <?php checked( $s['email_link'] ); ?>> <?php esc_html_e( 'Link „Vertrag widerrufen“ in Bestell-E-Mails an Kunden einfügen', 'widerrufsbutton-woocommerce' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcwb_account_days"><?php esc_html_e( 'Button im Kundenkonto (Tage)', 'widerrufsbutton-woocommerce' ); ?></label></th>
						<td>
							<input type="number" min="14" id="wcwb_account_days" name="<?php echo esc_attr( self::OPTION ); ?>[account_days]" value="<?php echo esc_attr( $s['account_days'] ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( 'Wie viele Tage nach Abschluss bzw. Erstellung einer Bestellung der Button bei dieser Bestellung im Kundenkonto angezeigt wird. Die Frist beginnt i. d. R. erst mit Erhalt der Ware – wählen Sie den Wert großzügig (mind. 14). Das allgemeine Formular ist unabhängig davon immer erreichbar.', 'widerrufsbutton-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcwb_notify_email"><?php esc_html_e( 'Benachrichtigung an', 'widerrufsbutton-woocommerce' ); ?></label></th>
						<td><input type="email" id="wcwb_notify_email" name="<?php echo esc_attr( self::OPTION ); ?>[notify_email]" value="<?php echo esc_attr( $s['notify_email'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Bestellungen', 'widerrufsbutton-woocommerce' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[mark_order]" value="1" <?php checked( $s['mark_order'] ); ?>> <?php esc_html_e( 'Zugeordnete Bestellung mit einer Bestellnotiz markieren', 'widerrufsbutton-woocommerce' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
