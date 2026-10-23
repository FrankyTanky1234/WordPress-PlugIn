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
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_admin' ), 10, 2 );
		add_filter( 'views_edit-' . self::POST_TYPE, array( __CLASS__, 'status_views' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_list' ) );
		add_action( 'admin_menu', array( __CLASS__, 'arrange_menu' ), 9999 );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
		add_filter( 'post_updated_messages', array( __CLASS__, 'updated_messages' ) );
	}

	/**
	 * Bearbeitungsstatus.
	 *
	 * @return array Schlüssel => Bezeichnung.
	 */
	public static function statuses() {
		return array(
			'new'      => __( 'Neu', 'widerrufsbutton-woocommerce' ),
			'progress' => __( 'In Bearbeitung', 'widerrufsbutton-woocommerce' ),
			'done'     => __( 'Erledigt', 'widerrufsbutton-woocommerce' ),
		);
	}

	/**
	 * Status eines Widerrufs (ältere Einträge ohne Status gelten als „Neu“).
	 *
	 * @param int $post_id ID.
	 * @return string
	 */
	public static function get_status( $post_id ) {
		$status = get_post_meta( $post_id, '_wcwb_status', true );
		return isset( self::statuses()[ $status ] ) ? $status : 'new';
	}

	/**
	 * Meta-Query für einen Status.
	 *
	 * @param string $status Status.
	 * @return array
	 */
	private static function status_meta_query( $status ) {
		if ( 'new' === $status ) {
			return array(
				'relation' => 'OR',
				array(
					'key'   => '_wcwb_status',
					'value' => 'new',
				),
				array(
					'key'     => '_wcwb_status',
					'compare' => 'NOT EXISTS',
				),
			);
		}
		return array(
			array(
				'key'   => '_wcwb_status',
				'value' => $status,
			),
		);
	}

	/**
	 * Anzahl Widerrufe mit einem Status.
	 *
	 * @param string $status Status.
	 * @return int
	 */
	public static function count_status( $status ) {
		$q = new WP_Query(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => array( 'private', 'publish' ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'meta_query'             => self::status_meta_query( $status ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		return (int) $q->found_posts;
	}

	/**
	 * Menü: „Einstellungen“ direkt unter „Widerrufe“ einsortieren und
	 * Anzahl neuer Widerrufe als Zähler anzeigen.
	 */
	public static function arrange_menu() {
		global $submenu;
		if ( empty( $submenu['woocommerce'] ) || ! is_array( $submenu['woocommerce'] ) ) {
			return;
		}
		$list_slug = 'edit.php?post_type=' . self::POST_TYPE;
		$items     = array_values( $submenu['woocommerce'] );
		$list      = null;
		$settings  = null;
		foreach ( $items as $i => $item ) {
			if ( isset( $item[2] ) && $list_slug === $item[2] ) {
				$list = $i;
			} elseif ( isset( $item[2] ) && 'wcwb-settings' === $item[2] ) {
				$settings = $i;
			}
		}
		if ( null === $list ) {
			return;
		}

		$new = self::count_status( 'new' );
		if ( $new > 0 ) {
			$items[ $list ][0] .= sprintf(
				' <span class="awaiting-mod count-%1$d"><span class="pending-count">%1$d</span></span>',
				$new
			);
		}

		if ( null !== $settings ) {
			$settings_item = $items[ $settings ];
			unset( $items[ $settings ] );
			$items = array_values( $items );
			$pos   = array_search( $list_slug, array_column( $items, 2 ), true );
			array_splice( $items, (int) $pos + 1, 0, array( $settings_item ) );
		}
		$submenu['woocommerce'] = $items; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Filter-Links „Neu | In Bearbeitung | Erledigt“ über der Liste.
	 *
	 * @param array $views Vorhandene Links.
	 * @return array
	 */
	public static function status_views( $views ) {
		$base    = admin_url( 'edit.php?post_type=' . self::POST_TYPE );
		$current = isset( $_GET['wcwb_status'] ) ? sanitize_key( wp_unslash( $_GET['wcwb_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$out     = array();
		if ( isset( $views['all'] ) ) {
			$out['all'] = '' === $current ? $views['all'] : preg_replace( '/ class="current"| aria-current="page"/', '', $views['all'] );
		}
		foreach ( self::statuses() as $key => $label ) {
			$out[ 'wcwb_' . $key ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a>',
				esc_url( add_query_arg( 'wcwb_status', $key, $base ) ),
				$current === $key ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				self::count_status( $key )
			);
		}
		if ( isset( $views['trash'] ) ) {
			$out['trash'] = $views['trash'];
		}
		return $out;
	}

	/**
	 * Liste nach Status filtern.
	 *
	 * @param WP_Query $query Abfrage.
	 */
	public static function filter_list( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || self::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}
		$status = isset( $_GET['wcwb_status'] ) ? sanitize_key( wp_unslash( $_GET['wcwb_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( self::statuses()[ $status ] ) ) {
			$query->set( 'meta_query', self::status_meta_query( $status ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
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
				'supports'        => false,
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
		update_post_meta( $post_id, '_wcwb_status', 'new' );

		if ( $order && WCWB_Settings::get( 'mark_order' ) ) {
			self::mark_order( $order, $reference, $received );
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
	 * Bestellung mit Notiz und Kennzeichen versehen.
	 *
	 * @param WC_Order $order     Bestellung.
	 * @param string   $reference Referenz.
	 * @param int      $received  Eingang (UTC-Timestamp).
	 * @param bool     $manual    Nachträglich manuell zugeordnet?
	 */
	private static function mark_order( $order, $reference, $received, $manual = false ) {
		$note = sprintf(
			/* translators: 1: Referenz, 2: Datum/Uhrzeit */
			__( 'Widerruf über die Widerrufsfunktion eingegangen (Referenz %1$s, %2$s).', 'widerrufsbutton-woocommerce' ),
			$reference,
			self::format_time( $received )
		);
		if ( $manual ) {
			$note .= ' ' . __( 'Nachträglich manuell zugeordnet.', 'widerrufsbutton-woocommerce' );
		}
		$order->add_order_note( $note );
		$order->update_meta_data( '_wcwb_withdrawn', $reference );
		$order->save();
	}

	/**
	 * Kennzeichen von einer Bestellung entfernen, wenn der Widerruf einer anderen Bestellung zugeordnet wird.
	 *
	 * @param WC_Order|false $order     Bisher zugeordnete Bestellung.
	 * @param string         $reference Referenz.
	 * @param WC_Order       $new_order Neu zugeordnete Bestellung.
	 */
	private static function unmark_order( $order, $reference, $new_order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		// Nur das eigene Kennzeichen entfernen – ein weiterer Widerruf zur selben Bestellung bleibt bestehen.
		if ( $order->get_meta( '_wcwb_withdrawn' ) === $reference ) {
			$order->delete_meta_data( '_wcwb_withdrawn' );
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: Referenz, 2: neue Bestellnummer */
				__( 'Widerruf %1$s betrifft nicht diese Bestellung und wurde der Bestellung #%2$s zugeordnet.', 'widerrufsbutton-woocommerce' ),
				$reference,
				$new_order->get_order_number()
			)
		);
		$order->save();
	}

	/**
	 * Bestellung nur anhand der angezeigten Bestellnummer suchen (für die manuelle Zuordnung).
	 *
	 * @param string $order_number Bestellnummer.
	 * @return WC_Order|null
	 */
	public static function find_order_by_number( $order_number ) {
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
		// Nummerierungs-Plugins speichern die angezeigte Nummer meist in „_order_number“.
		$by_meta = wc_get_orders(
			array(
				'limit'      => 5,
				'type'       => 'shop_order',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_order_number',
						'value' => $order_number,
					),
				),
			)
		);
		if ( is_array( $by_meta ) ) {
			$candidates = array_merge( $candidates, $by_meta );
		}
		/** This filter is documented in find_order(). */
		$candidates = apply_filters( 'wcwb_order_candidates', $candidates, $order_number );
		foreach ( $candidates as $order ) {
			if ( $order instanceof WC_Order && (string) $order->get_order_number() === $order_number ) {
				return $order;
			}
		}
		return null;
	}

	/**
	 * Datensatz laden.
	 *
	 * @param int $post_id ID.
	 * @return array
	 */
	public static function get( $post_id ) {
		$record = array( 'id' => (int) $post_id );
		foreach ( array( 'reference', 'received', 'name', 'email', 'order_number', 'items', 'message', 'order_id', 'note' ) as $key ) {
			$record[ $key ] = get_post_meta( $post_id, '_wcwb_' . $key, true );
		}
		$record['status'] = self::get_status( $post_id );
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
			'wcwb_status'   => __( 'Status', 'widerrufsbutton-woocommerce' ),
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
			case 'wcwb_status':
				echo self::status_badge( $r['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;
			case 'wcwb_received':
				echo esc_html( self::format_time( $r['received'] ) );
				break;
			case 'wcwb_email':
				echo esc_html( $r['email'] );
				if ( '0' === (string) get_post_meta( $post_id, '_wcwb_mail_sent', true ) ) {
					echo '<br><strong style="color:#d63638">' . esc_html__( 'Eingangsbestätigung nicht versendet – bitte manuell bestätigen', 'widerrufsbutton-woocommerce' ) . '</strong>';
				}
				break;
			case 'wcwb_order':
				echo wp_kses_post( self::order_html( $r ) );
				break;
		}
	}

	/**
	 * Farbiges Status-Label.
	 *
	 * @param string $status Status.
	 * @return string HTML.
	 */
	private static function status_badge( $status ) {
		$colors = array(
			'new'      => array( '#f0f6fc', '#2271b1' ),
			'progress' => array( '#fcf9e8', '#996800' ),
			'done'     => array( '#edfaef', '#008a20' ),
		);
		$labels = self::statuses();
		$c      = isset( $colors[ $status ] ) ? $colors[ $status ] : $colors['new'];
		return sprintf(
			'<span style="display:inline-block;padding:2px 8px;border-radius:3px;background:%1$s;color:%2$s;font-weight:600">%3$s</span>',
			esc_attr( $c[0] ),
			esc_attr( $c[1] ),
			esc_html( isset( $labels[ $status ] ) ? $labels[ $status ] : $labels['new'] )
		);
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
	 * Detailansicht und Bearbeitung als Meta-Boxen.
	 */
	public static function add_meta_box() {
		add_meta_box( 'wcwb_details', __( 'Inhalt des Widerrufs', 'widerrufsbutton-woocommerce' ), array( __CLASS__, 'render_meta_box' ), self::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'wcwb_manage', __( 'Bearbeitung', 'widerrufsbutton-woocommerce' ), array( __CLASS__, 'render_manage_box' ), self::POST_TYPE, 'side', 'high' );
		remove_meta_box( 'submitdiv', self::POST_TYPE, 'side' );
		remove_meta_box( 'slugdiv', self::POST_TYPE, 'normal' );
	}

	/**
	 * Meta-Box „Inhalt des Widerrufs“ ausgeben (unveränderlich).
	 *
	 * @param WP_Post $post Beitrag.
	 */
	public static function render_meta_box( $post ) {
		$r    = self::get( $post->ID );
		$rows = array(
			__( 'Referenz', 'widerrufsbutton-woocommerce' )           => esc_html( $r['reference'] ),
			__( 'Eingang', 'widerrufsbutton-woocommerce' )            => esc_html( self::format_time( $r['received'] ) ),
			__( 'Name', 'widerrufsbutton-woocommerce' )               => esc_html( $r['name'] ),
			__( 'E-Mail', 'widerrufsbutton-woocommerce' )             => '<a href="mailto:' . esc_attr( $r['email'] ) . '">' . esc_html( $r['email'] ) . '</a>',
			__( 'Angegebene Bestellnummer / -datum', 'widerrufsbutton-woocommerce' ) => esc_html( $r['order_number'] ),
			__( 'Zugeordnete Bestellung', 'widerrufsbutton-woocommerce' ) => self::order_html( $r ),
			__( 'Betroffene Artikel', 'widerrufsbutton-woocommerce' ) => nl2br( esc_html( $r['items'] ? $r['items'] : __( 'Gesamter Vertrag', 'widerrufsbutton-woocommerce' ) ) ),
			__( 'Nachricht', 'widerrufsbutton-woocommerce' )          => nl2br( esc_html( $r['message'] ? $r['message'] : '–' ) ),
		);
		if ( '0' === (string) get_post_meta( $post->ID, '_wcwb_mail_sent', true ) ) {
			$rows[ __( 'Eingangsbestätigung', 'widerrufsbutton-woocommerce' ) ] = '<strong style="color:#d63638">' . esc_html__( 'Versand fehlgeschlagen – bitte manuell bestätigen.', 'widerrufsbutton-woocommerce' ) . '</strong>';
		}
		echo '<p class="description">' . esc_html__( 'Diese Angaben sind der Nachweis des Widerrufs und können nicht geändert werden.', 'widerrufsbutton-woocommerce' ) . '</p>';
		echo '<table class="widefat striped"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th style="width:200px">' . esc_html( $label ) . '</th><td>' . wp_kses_post( $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Meta-Box „Bearbeitung“: Status, manuelle Zuordnung, interne Notiz.
	 *
	 * @param WP_Post $post Beitrag.
	 */
	public static function render_manage_box( $post ) {
		$r = self::get( $post->ID );
		wp_nonce_field( 'wcwb_manage_' . $post->ID, '_wcwb_manage_nonce' );
		?>
		<input type="hidden" name="post_status" value="private">
		<input type="hidden" name="visibility" value="private">
		<p>
			<label for="wcwb_status"><strong><?php esc_html_e( 'Status', 'widerrufsbutton-woocommerce' ); ?></strong></label><br>
			<select name="wcwb_status" id="wcwb_status" style="width:100%">
				<?php foreach ( self::statuses() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $r['status'], $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="wcwb_assign_order"><strong><?php echo $r['order_id'] ? esc_html__( 'Andere Bestellung zuordnen', 'widerrufsbutton-woocommerce' ) : esc_html__( 'Bestellung zuordnen', 'widerrufsbutton-woocommerce' ); ?></strong></label><br>
			<input type="text" name="wcwb_assign_order" id="wcwb_assign_order" value="" placeholder="<?php esc_attr_e( 'Bestellnummer, z. B. 1234', 'widerrufsbutton-woocommerce' ); ?>" style="width:100%">
			<span class="description"><?php esc_html_e( 'Die Bestellung erhält automatisch eine Notiz.', 'widerrufsbutton-woocommerce' ); ?></span>
		</p>
		<p>
			<label for="wcwb_note"><strong><?php esc_html_e( 'Interne Notiz', 'widerrufsbutton-woocommerce' ); ?></strong></label><br>
			<textarea name="wcwb_note" id="wcwb_note" rows="5" style="width:100%" placeholder="<?php esc_attr_e( 'z. B. Ware am 28.10. zurück, Betrag erstattet', 'widerrufsbutton-woocommerce' ); ?>"><?php echo esc_textarea( $r['note'] ); ?></textarea>
			<span class="description"><?php esc_html_e( 'Nur für Sie sichtbar, wird nicht an den Kunden gesendet.', 'widerrufsbutton-woocommerce' ); ?></span>
		</p>
		<p><?php submit_button( __( 'Speichern', 'widerrufsbutton-woocommerce' ), 'primary', 'save', false ); ?></p>
		<?php
	}

	/**
	 * Änderungen aus der Meta-Box „Bearbeitung“ speichern.
	 *
	 * @param int     $post_id ID.
	 * @param WP_Post $post    Beitrag.
	 */
	public static function save_admin( $post_id, $post ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$nonce = isset( $_POST['_wcwb_manage_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wcwb_manage_nonce'] ) ) : '';
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wcwb_manage_' . $post_id ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$status = isset( $_POST['wcwb_status'] ) ? sanitize_key( wp_unslash( $_POST['wcwb_status'] ) ) : '';
		if ( isset( self::statuses()[ $status ] ) ) {
			update_post_meta( $post_id, '_wcwb_status', $status );
		}

		if ( isset( $_POST['wcwb_note'] ) ) {
			update_post_meta( $post_id, '_wcwb_note', sanitize_textarea_field( wp_unslash( $_POST['wcwb_note'] ) ) );
		}

		$assign = isset( $_POST['wcwb_assign_order'] ) ? sanitize_text_field( wp_unslash( $_POST['wcwb_assign_order'] ) ) : '';
		if ( '' !== $assign ) {
			$order     = self::find_order_by_number( $assign );
			$reference = get_post_meta( $post_id, '_wcwb_reference', true );
			$previous  = (int) get_post_meta( $post_id, '_wcwb_order_id', true );
			if ( $order && $order->get_id() === $previous ) {
				/* translators: %s: Bestellnummer */
				self::flash( 'success', sprintf( __( 'Der Widerruf ist der Bestellung #%s bereits zugeordnet.', 'widerrufsbutton-woocommerce' ), $order->get_order_number() ) );
			} elseif ( $order ) {
				if ( $previous ) {
					self::unmark_order( wc_get_order( $previous ), $reference, $order );
				}
				update_post_meta( $post_id, '_wcwb_order_id', $order->get_id() );
				self::mark_order( $order, $reference, (int) get_post_meta( $post_id, '_wcwb_received', true ), true );
				/* translators: %s: Bestellnummer */
				self::flash( 'success', sprintf( __( 'Widerruf der Bestellung #%s zugeordnet.', 'widerrufsbutton-woocommerce' ), $order->get_order_number() ) );
			} else {
				/* translators: %s: eingegebene Bestellnummer */
				self::flash( 'error', sprintf( __( 'Keine Bestellung mit der Nummer „%s“ gefunden. Die Zuordnung wurde nicht geändert.', 'widerrufsbutton-woocommerce' ), $assign ) );
			}
		}
	}

	/**
	 * Hinweis für die nächste Seitenansicht merken.
	 *
	 * @param string $type    success|error.
	 * @param string $message Text.
	 */
	private static function flash( $type, $message ) {
		set_transient( 'wcwb_notice_' . get_current_user_id(), array( $type, $message ), 60 );
	}

	/**
	 * Gemerkten Hinweis ausgeben.
	 */
	public static function admin_notice() {
		$key    = 'wcwb_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			'error' === $notice[0] ? 'error' : 'success',
			esc_html( $notice[1] )
		);
	}

	/**
	 * Standardmeldung „Beitrag aktualisiert“ passend formulieren.
	 *
	 * @param array $messages Meldungen.
	 * @return array
	 */
	public static function updated_messages( $messages ) {
		$text                         = __( 'Widerruf gespeichert.', 'widerrufsbutton-woocommerce' );
		$messages[ self::POST_TYPE ] = array_fill( 0, 11, $text );
		$messages[ self::POST_TYPE ][0] = '';
		return $messages;
	}
}
