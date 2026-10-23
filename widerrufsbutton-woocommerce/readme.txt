=== Widerrufsbutton für WooCommerce ===
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later

Elektronische Widerrufsfunktion nach Art. 11a der Verbraucherrechte-Richtlinie (i. d. F. der RL (EU) 2023/2673) bzw. § 356a BGB – gilt ab 19. Juni 2026.

== Beschreibung ==

* Button „Vertrag widerrufen“ – dauerhaft im Footer jeder Seite, im Kundenkonto je Bestellung und in den Bestell-E-Mails.
* Formular mit den gesetzlich vorgesehenen Angaben: Name, Angabe des Vertrags (Bestellnummer, optional einzelne Artikel), E-Mail-Adresse für die Bestätigung. Keine Begründung, kein Login, kein Captcha erforderlich.
* Zweiter Schritt mit Zusammenfassung und Bestätigungsfunktion „Widerruf bestätigen“.
* Sofortige Eingangsbestätigung per E-Mail (dauerhafter Datenträger) mit Inhalt des Widerrufs sowie Datum und Uhrzeit des Eingangs.
* Speicherung aller Widerrufe unter WooCommerce → Widerrufe, Benachrichtigung an den Shop, automatische Zuordnung zur Bestellung (Bestellnotiz).
* Bearbeitung im Backend: Status (Neu / In Bearbeitung / Erledigt) mit Filter, manuelle Zuordnung zu einer Bestellung, interne Notiz. Zähler für neue Widerrufe im Menü.
* HPOS-kompatibel.

== Installation ==

1. Ordner `widerrufsbutton-woocommerce` nach `wp-content/plugins/` hochladen (oder ZIP über Plugins → Installieren → Plugin hochladen).
2. Aktivieren. Die Seite „Vertrag widerrufen“ mit dem Shortcode `[widerrufsformular]` wird automatisch angelegt.
3. Unter WooCommerce → Widerrufsbutton die Einstellungen prüfen.
4. Widerrufsbelehrung in den AGB/Rechtstexten um den Hinweis auf die Widerrufsfunktion ergänzen.

== Hinweise ==

* Die Widerrufsfunktion muss leicht zugänglich und während der gesamten Widerrufsfrist verfügbar sein. Das öffentliche Formular nimmt daher jeden Widerruf an – auch ohne Treffer bei der Bestellnummer oder nach Ablauf von Fristen; die Prüfung erfolgt durch den Shop.
* Stellen Sie sicher, dass Ihr Shop zuverlässig E-Mails versendet (SMTP-Plugin empfohlen). Fehlgeschlagene Sendungen werden im WooCommerce-Log (Quelle „widerrufsbutton“) protokolliert.
* Für eigene Bestellnummern-Plugins steht der Filter `wcwb_order_candidates` zur Verfügung.
* Dieses Plugin ist keine Rechtsberatung. Lassen Sie Ihre Umsetzung und Rechtstexte ggf. anwaltlich prüfen.

== Filter & Actions ==

* `wcwb_withdrawal_created` (action) – nach dem Speichern eines Widerrufs.
* `wcwb_order_candidates` (filter) – Bestellungen zu einer eingegebenen Bestellnummer liefern.
* `wcwb_order_is_eligible` (filter) – Anzeige des Buttons im Kundenkonto steuern.
* `wcwb_email_ids` (filter) – E-Mails, in die der Link eingefügt wird.
* `wcwb_rate_limit` (filter) – max. Widerrufe pro IP und Stunde (Standard 10).

== Changelog ==

= 1.0.2 =
* Neu: Status pro Widerruf (Neu, In Bearbeitung, Erledigt) inkl. Filter in der Übersicht.
* Neu: Widerruf nachträglich manuell einer Bestellung zuordnen (mit Bestellnotiz).
* Neu: Interne Notiz pro Widerruf.
* Neu: Zähler neuer Widerrufe im Menü.
* Menü: „Einstellungen“ steht jetzt direkt unter „Widerrufe“.

= 1.0.1 =
* Bestellzuordnung funktioniert jetzt auch mit Nummerierungs-Plugins (Suche über E-Mail-Adresse + angezeigte Bestellnummer).
* Honeypot-Spamschutz greift jetzt auch beim Bestätigen.
* Schlägt die Eingangsbestätigung per E-Mail fehl, wird der Kunde darauf hingewiesen und der Shop gewarnt.
* Footer-Button auf Mobilgeräten nicht mehr von fixierten Fußleisten (z. B. Storefront) verdeckt.
* Übersetzungen werden erst bei „init“ geladen (WordPress 6.7+).

= 1.0.0 =
* Erste Version.
