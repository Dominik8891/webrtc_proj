# WebRTC Remote-Guidance & Location Platform

Diese Web-Applikation ist ein interaktives **Remote-Guidance-System**. Es ermöglicht Guides, Standorte für Führungen anzubieten, die von Usern in Echtzeit per Video besucht werden können. Das Highlight: Der Zuschauer steuert den Guide vor Ort interaktiv über Fernsteuerungsbefehle.

---

## 💡 Das Konzept
Die Plattform verbindet geografische Standorte mit Echtzeit-Kommunikation:
- **Standort-Management:** Guides markieren auf einer Karte oder in Listen ihre Verfügbarkeit an bestimmten Orten.
- **Interaktive Führung:** Zuschauer bauen eine Peer-to-Peer Verbindung zum Guide auf.
- **Remote-Navigation:** Über die Tastatur (Pfeiltasten) sendet der Zuschauer Befehle an den Guide (z. B. "Links schwenken", "Vorwärts gehen"), um die Regie der Führung zu übernehmen.

---

## 🚀 Key Features
- **Echtzeit-Streaming:** P2P-Videoübertragung mit minimaler Latenz via WebRTC.
- **Signalling-System:** PHP-basiertes Handshake-System zum Austausch von SDP-Daten und ICE-Kandidaten.
- **NAT Traversal:** Integrierter **TURN-Service (Metered.ca)** für stabile Verbindungen hinter Firewalls.
- **Sicherheit:** - Passwort-Hashing mit individuellem **Pepper**.
  - **Zwei-Faktor-Authentifizierung (2FA/TOTP)** Integration.
  - E-Mail-Verifizierung und Passwort-Reset via **SMTP**.
- **Rollen-System:** Drei-Ebenen-Berechtigungsmodell (Admin, Guide, User).

---

## 🛠 Technischer Stack
- **Frontend:** HTML5, CSS3, JavaScript (WebRTC API, Canvas/Navigation)
- **Backend:** PHP (Objektorientiert mit Model-Klassen)
- **Datenbank:** MySQL / MariaDB
- **Infrastruktur:** Metered TURN-API, PHPMailer (SMTP)

---

## 🏗 Datenbank-Architektur
Die Anwendung nutzt eine normalisierte SQL-Struktur zur Verwaltung von Benutzern, Standorten und Kommunikations-Signalen.



### Benutzerrollen:
- `0`: **Admin** (Vollzugriff)
- `1`: **Guide** (Standorte erstellen, Streams anbieten)
- `2`: **User** (Standorte suchen, Streams empfangen & steuern)

---

## ⚙️ Installation & Konfiguration

### 1. Datenbank
Importiere die mitgelieferte `database.sql` in deine MySQL-Instanz. Diese erstellt alle notwendigen Tabellen wie `user`, `location`, `rtc_signal` und `user_type`.

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
