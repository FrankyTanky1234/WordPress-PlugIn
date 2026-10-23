<?php
/**
 * Speicherung und Verwaltung eingegangener Widerrufe.
 *
 * @package WCWB
 */

defined( 'ABSPATH' ) || exit;

/**
 * Eingegangene Widerrufe als interner Beitragstyp.
 */
class WCWB_Withdrawals {

	const POST_TYPE = 'wcwb_withdrawal';

	/**
	 * Hooks registrieren.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'add_meta_box' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
	}

	/**
	 * Beitragstyp registrieren (nur im Backend sichtbar).
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Widerrufe', 'widerrufsbutton-woocommerce' ),
					'singular_name' => __( 'Widerruf', 'widerrufsbutton-woocommerce' ),
					'menu_name'     => __( 'Widerrufe', 'widerrufsbutton-woocommerce' ),
					'all_items'     => __( 'Widerrufe', 'widerrufsbutton-woocommerce' ),
					'edit_item'     => __( 'Widerruf ansehen', 'widerrufsbutton-woocommerce' ),
					'search_items'  => __( 'Widerrufe durchsuchen', 'widerrufsbutton-woocommerce' ),
					'not_found'     => __( 'Keine Widerrufe vorhanden.', 'widerrufsbutton-woocommerce' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'woocommerce',
				'show_in_rest'    => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'capabilities'    => array(
					'create_posts'       => 'do_not_allow',
					'edit_post'          => 'manage_woocommerce',
					'read_post'          => 'manage_woocommerce',
					'delete_post'        => 'manage_woocommerce',
					'edit_posts'         => 'manage_woocommerce',
					'edit_others_posts'  => 'manage_woocommerce',
					'delete_posts'       => 'manage_woocommerce',
					'publish_posts'      => 'manage_woocommerce',
					'read_private_posts' => 'manage_woocommerce',
				),
				'map_meta_cap'    => false,
			)
		);
	}

	/**
	 * Widerruf speichern.
	 *
	 * @param array $data Bereinigte Formulardaten (name, email, order_number, items, message).
	 * @return array|WP_Error Gespeicherter Datensatz inkl. id, reference, received (UTC-Timestamp), order_id.
	 */
	public static function create( array $data ) {
		$received = time();
		$order    = self::find_order( $data['order_number'], $data['email'] );

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'private',
				'post_title'  => __( 'Widerruf', 'widerrufsbutton-woocommerce' ),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$reference = sprintf( 'WR-%s-%05d', gmdate( 'Ymd', $received ), $post_id );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => $reference . ' – ' . $data['name'],
			)
		);

		$record = array(
			'id'           => $post_id,
			'reference'    => $reference,
			'received'     => $received,
			'name'         => $data['name'],
			'email'        => $data['email'],
			'order_number' => $data['order_number'],
			'items'        => $data['items'],
			'message'      => $data['message'],
			'order_id'     => $order ? $order->get_id() : 0,
		);

		foreach ( $record as $key => $value ) {
			if ( 'id' !== $key ) {
				update_post_meta( $post_id, '_wcwb_' . $key, $value );
			}
		}

		if ( $order && WCWB_Settings::get( 'mark_order' ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: Referenz, 2: Datum/Uhrzeit */
					__( 'Widerruf über die Widerrufsfunktion eingegangen (Referenz %1$s, %2$s).', 'widerrufsbutton-woocommerce' ),
					$reference,
					self::format_time( $received )
				)
			);
			$order->update_meta_data( '_wcwb_withdrawn', $reference );
			$order->save();
		}

		/**
		 * Wird ausgelöst, nachdem ein Widerruf gespeichert wurde.
		 *
		 * @param array         $record Datensatz.
		 * @param WC_Order|null $order  Zugeordnete Bestellung.
		 */
		do_action( 'wcwb_withdrawal_created', $record, $order );

		return $record;
	}

	/**
	 * Datensatz laden.
	 *
	 * @param int $post_id ID.
	 * @return array
	 */
	public static function get( $post_id ) {
		$record = array( 'id' => (int) $post_id );
		foreach ( array( 'reference', 'received', 'name', 'email', 'order_number', 'items', 'message', 'order_id' ) as $key ) {
			$record[ $key ] = get_post_meta( $post_id, '_wcwb_' . $key, true );
		}
		return $record;
	}

	/**
	 * Bestellung anhand Bestellnummer und E-Mail-Adresse suchen.
	 *
	 * Ein Widerruf wird auch ohne Treffer angenommen – die Zuordnung
	 * dient nur der Bearbeitung im Shop.
	 *
	 * @param string $order_number Bestellnummer, wie sie der Kunde sieht.
	 * @param string $email        E-Mail-Adresse.
	 * @return WC_Order|null
	 */
	public static function find_order( $order_number, $email ) {
		$order_number = ltrim( trim( (string) $order_number ), '#' );
		if ( '' === $order_number ) {
			return null;
		}

		$candidates = array();
		if ( ctype_digit( $order_number ) ) {
			$order = wc_get_order( (int) $order_number );
			if ( $order instanceof WC_Order ) {
				$candidates[] = $order;
			}
		}

		// Unabhängig von Nummerierungs-Plugins (z. B. „Sequential Order Numbers“):
		// Bestellungen zur E-Mail-Adresse laden und über die angezeigte Nummer abgleichen.
		if ( is_email( $email ) ) {
			$by_email = wc_get_orders(
				array(
					'billing_email' => $email,
					'limit'         => 100,
					'orderby'       => 'date',
					'order'         => 'DESC',
					'type'          => 'shop_order',
				)
			);
			if ( is_array( $by_email ) ) {
				$candidates = array_merge( $candidates, $by_email );
			}
		}

		/**
		 * Erlaubt Plugins für eigene Bestellnummern (z. B. „Sequential Order Numbers“),
		 * die Bestellung zu einer Nummer zu liefern.
		 *
		 * @param WC_Order[] $candidates   Gefundene Bestellungen.
		 * @param string     $order_number Eingegebene Nummer.
		 */
		$candidates = apply_filters( 'wcwb_order_candidates', $candidates, $order_number );

		foreach ( $candidates as $order ) {
			if ( $order instanceof WC_Order
				&& (string) $order->get_order_number() === $order_number
				&& 0 === strcasecmp( (string) $order->get_billing_email(), $email ) ) {
				return $order;
			}
		}
		return null;
	}

	/**
	 * Zeitpunkt in Shop-Zeitzone formatieren.
	 *
	 * @param int $timestamp UTC-Timestamp.
	 * @return string
	 */
	public static function format_time( $timestamp ) {
		return wp_date( 'd.m.Y, H:i:s T', (int) $timestamp );
	}

	/**
	 * Spalten der Übersicht.
	 *
	 * @param array $columns Spalten.
	 * @return array
	 */
	public static function columns( $columns ) {
		return array(
			'cb'            => $columns['cb'],
			'title'         => __( 'Referenz / Name', 'widerrufsbutton-woocommerce' ),
			'wcwb_received' => __( 'Eingang', 'widerrufsbutton-woocommerce' ),
			'wcwb_email'    => __( 'E-Mail', 'widerrufsbutton-woocommerce' ),
			'wcwb_order'    => __( 'Bestellung', 'widerrufsbutton-woocommerce' ),
		);
	}

	/**
	 * Spalteninhalt.
	 *
	 * @param string $column  Spalte.
	 * @param int    $post_id ID.
	 */
	public static function column_content( $column, $post_id ) {
		$r = self::get( $post_id );
		switch ( $column ) {
			case 'wcwb_received':
				echo esc_html( self::format_time( $r['received'] ) );
				break;
			case 'wcwb_email':
				echo esc_html( $r['email'] );
				break;
			case 'wcwb_order':
				echo wp_kses_post( self::order_html( $r ) );
				break;
		}
	}

	/**
	 * Darstellung der Bestellzuordnung.
	 *
	 * @param array $r Datensatz.
	 * @return string HTML.
	 */
	private static function order_html( array $r ) {
		$order = $r['order_id'] ? wc_get_order( $r['order_id'] ) : null;
		if ( $order ) {
			return sprintf( '<a href="%s">#%s</a>', esc_url( $order->get_edit_order_url() ), esc_html( $order->get_order_number() ) );
		}
		/* translators: %s: eingegebene Bestellnummer */
		return esc_html( sprintf( __( '„%s“ – nicht zugeordnet, bitte manuell prüfen', 'widerrufsbutton-woocommerce' ), $r['order_number'] ) );
	}

	/**
	 * Zeilenaktionen: Schnellbearbeitung entfernen.
	 *
	 * @param array   $actions Aktionen.
	 * @param WP_Post $post    Beitrag.
	 * @return array
	 */
	public static function row_actions( $actions, $post ) {
		if ( self::POST_TYPE === $post->post_type ) {
			unset( $actions['inline hide-if-no-js'] );
		}
		return $actions;
	}

	/**
	 * Detailansicht als Meta-Box.
	 */
	public static function add_meta_box() {
		add_meta_box( 'wcwb_details', __( 'Inhalt des Widerrufs', 'widerrufsbutton-woocommerce' ), array( __CLASS__, 'render_meta_box' ), self::POST_TYPE, 'normal', 'high' );
		remove_meta_box( 'submitdiv', self::POST_TYPE, 'side' );
	}

	/**
	 * Meta-Box ausgeben.
	 *
	 * @param WP_Post $post Beitrag.
	 */
	public static function render_meta_box( $post ) {
		$r    = self::get( $post->ID );
		$rows = array(
			__( 'Referenz', 'widerrufsbutton-woocommerce' )          => esc_html( $r['reference'] ),
			__( 'Eingang', 'widerrufsbutton-woocommerce' )           => esc_html( self::format_time( $r['received'] ) ),
			__( 'Name', 'widerrufsbutton-woocommerce' )              => esc_html( $r['name'] ),
			__( 'E-Mail', 'widerrufsbutton-woocommerce' )            => esc_html( $r['email'] ),
			__( 'Bestellung', 'widerrufsbutton-woocommerce' )        => self::order_html( $r ),
			__( 'Betroffene Artikel', 'widerrufsbutton-woocommerce' ) => nl2br( esc_html( $r['items'] ? $r['items'] : __( 'Gesamter Vertrag', 'widerrufsbutton-woocommerce' ) ) ),
			__( 'Nachricht', 'widerrufsbutton-woocommerce' )         => nl2br( esc_html( $r['message'] ? $r['message'] : '–' ) ),
		);
		echo '<table class="widefat striped"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th style="width:200px">' . esc_html( $label ) . '</th><td>' . wp_kses_post( $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
