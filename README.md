# WebRTC Remote-Guidance & Location Platform

Diese Web-Applikation ist ein interaktives **Remote-Guidance-System**. Es ermöglicht Guides, Standorte für Führungen anzubieten, bei denen der Zuschauer die Regie übernimmt. Über eine Peer-to-Peer-Verbindung steuert der User den Guide vor Ort in Echtzeit per Tastaturbefehl.

---

## 💡 Das Konzept
* **Interaktive Steuerung:** Zuschauer navigieren den Guide via Pfeiltasten über WebRTC-Datenkanäle.
* **Geo-Präsenz:** Guides hinterlegen Standorte in der Datenbank, die für User sichtbar sind.
* **Echtzeit-Kommunikation:** P2P-Video/Audio mit minimaler Latenz.

---

## 🚀 Key Features & Sicherheit
* **WebRTC Signalling:** PHP-basiertes Handshake-System zum Austausch von SDP-Daten und ICE-Kandidaten.
* **NAT Traversal:** Integration von **TURN-Servern** (Metered.ca) für stabile Verbindungen.
* **High-Security:** * Passwort-Hashing mit individuellem **Pepper**.
    * **Zwei-Faktor-Authentifizierung (2FA/TOTP)** inklusive QR-Code-Generierung.
    * E-Mail-Verifizierung (`email_verified`) und Passwort-Reset via SMTP.
* **Rollen-System:** Berechtigungsmodell (Admin, Guide, User, Trial).

---

## ⚙️ Installation & Konfiguration

### 1. Voraussetzungen
* **PHP 8.x** mit aktivierter **GD-Extension** (in der `php.ini` bei `extension=gd` das Semikolon entfernen).
* **Composer** für das Abhängigkeitsmanagement.
* **HTTPS** (erforderlich für den Kamerazugriff).

### 2. Composer Setup
Installiere die benötigten Libraries:
```bash
composer install

### 1. Datenbank
Importiere die mitgelieferte `database.sql` in deine MySQL-Instanz. Diese erstellt alle notwendigen Tabellen wie `user`, `location`, `rtc_signal` und `usertype`.

### 2. Umgebungsvariablen (`.env`)
Erstelle eine `.env`-Datei im Root-Verzeichnis und hinterlege deine Zugangsdaten:

```env
# Datenbank-Zugang
DB_HOST=localhost
DB_PORT=3306
DB_USER=dein_benutzer
DB_PW=dein_passwort
DB_NAME=webrtc_guidance

# Sicherheit
APP_ENV=production
PEPPER=dein_geheimer_pepper_string

# WebRTC TURN-Server (Metered.ca)
METERED_API_KEY=dein_api_key
METERED_APP_NAME=dein_api_name

# E-Mail (SMTP)
SMTP_SERVER=dein.smtp-server.com
SMTP_PORT=587
SMTP_USERNAME=dein_login
SMTP_PASSWORD=dein_passwort

## 👤 Autor
**Dominik Kusber** *Angehender Fachinformatiker für Anwendungsentwicklung* [GitHub Profile](https://github.com/dominik8891) | [Portfolio/Kontakt](mailto:deine@email.de)

---
*Dieses Projekt entstand im Rahmen eines Praktikums zur Dokumentation der Kompetenzen in PHP, WebRTC und relationalem Datenbankdesign.*

---

## ⚠️ Hinweis zur Projekthistorie
Da die ursprünglichen Datenbank-Dumps des Projekts (Stand vor 6 Monaten) nicht mehr verfügbar waren, wurde das SQL-Schema für diese Version auf Basis der bestehenden Models und Logik rekonstruiert. 

Aufgrund dieser nachträglichen Erstellung können Abweichungen zwischen der ursprünglichen Testumgebung und dem aktuellen Schema bestehen. Die aktuelle `database.sql` wurde jedoch nach bestem Wissen auf die aktuelle Code-Basis (PHP-Models) optimiert.

---
