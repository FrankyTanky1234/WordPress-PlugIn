<?php
/**
 * Deinstallation: entfernt nur die Einstellungen.
 *
 * Eingegangene Widerrufe bleiben bewusst erhalten, da sie als Nachweis
 * aufbewahrungspflichtig sein können. Sie lassen sich unter
 * WooCommerce → Widerrufe vor der Deinstallation manuell löschen.
 *
 * @package WCWB
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wcwb_settings' );
delete_option( 'wcwb_page_id' );
