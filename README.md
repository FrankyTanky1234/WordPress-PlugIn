# Widerrufsbutton für WooCommerce

Elektronische Widerrufsfunktion („Vertrag widerrufen“ / „Widerruf bestätigen“) nach
§ 356a BGB bzw. Art. 11a RL 2011/83/EU i. d. F. der RL (EU) 2023/2673 – Pflicht ab 19. Juni 2026.

## Funktionen

- Button „Vertrag widerrufen“ im Footer jeder Seite, im Kundenkonto je Bestellung und in den Bestell-E-Mails
- Formular ohne Login: Name, Bestellnummer (oder Bestelldatum), E-Mail, optional Artikel und Nachricht
- Zweiter Schritt mit Zusammenfassung und Button „Widerruf bestätigen“
- Sofortige Eingangsbestätigung per E-Mail mit Inhalt, Datum und Uhrzeit
- Benachrichtigung an den Shop, automatische Zuordnung zur Bestellung (auch mit Nummerierungs-Plugins)
- Übersicht unter WooCommerce → Widerrufe mit Status, Filter, manueller Zuordnung und interner Notiz
- Spamschutz (Honeypot, Rate-Limit), HPOS-kompatibel

## Installation

1. Neueste ZIP unter [„Releases“](../../releases/latest) herunterladen
2. WordPress → Plugins → Installieren → Plugin hochladen → ZIP auswählen → aktivieren
3. Die Seite „Vertrag widerrufen“ wird automatisch angelegt
4. Einstellungen unter WooCommerce → Widerrufe → Einstellungen prüfen
5. Widerrufsbelehrung um den Hinweis auf die elektronische Widerrufsfunktion ergänzen
6. Testwiderruf durchführen und prüfen, ob die Bestätigungs-E-Mail ankommt (ggf. SMTP-Plugin nutzen)

## Updates

Neue Version über „Plugin hochladen“ → „Aktuelle Version durch hochgeladene ersetzen“.
Eingegangene Widerrufe und Einstellungen bleiben erhalten.

## Hinweis

Dieses Plugin ist keine Rechtsberatung. Umsetzung und Rechtstexte ggf. anwaltlich prüfen lassen.

## Lizenz

GPL-2.0-or-later
