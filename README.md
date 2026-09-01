# WebRTC Remote-Guidance & Location Platform

Diese Web-Applikation ist ein interaktives **Remote-Guidance-System**. Es ermöglicht Guides, Standorte für Führungen anzubieten, bei denen der Zuschauer die Regie übernimmt. Über eine Peer-to-Peer-Verbindung steuert der Zuschauer den Guide vor Ort in Echtzeit über ein Steuerkreuz.

---

## 💡 Das Konzept
* **Interaktive Steuerung:** Der Zuschauer navigiert den Guide über ein Steuerkreuz. Die Befehle laufen über einen eigenen WebRTC-Datenkanal, getrennt vom Chat, als versioniertes JSON-Protokoll mit Rollen, Bestätigung und Sperre — vollständig beschrieben in [`PROTOKOLL.md`](PROTOKOLL.md).
* **Geo-Präsenz:** Guides hinterlegen Standorte in der Datenbank, die für User sichtbar sind.
* **Echtzeit-Kommunikation:** P2P-Video/Audio mit minimaler Latenz.

---

## 🚀 Key Features & Sicherheit
* **WebRTC Signalling:** PHP-basiertes Handshake-System zum Austausch von SDP-Daten und ICE-Kandidaten.
* **NAT Traversal:** Integration von **TURN-Servern** (Metered.ca) für stabile Verbindungen.
* **High-Security:** * Passwort-Hashing mit individuellem **Pepper**.
    * **Zwei-Faktor-Authentifizierung (2FA/TOTP)** inklusive QR-Code-Generierung.
    * E-Mail-Verifizierung (`email_verified`) und Passwort-Reset via SMTP.
* **Rollen-System:** Berechtigungsmodell (Admin, Guide, User, Trial). Im laufenden Call vergibt der Server zusätzlich die Rolle Guide bzw. Zuschauer — der Client kann sie sich nicht selbst geben.

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
```

### 1. Datenbank
Importiere die mitgelieferte `database.sql` in deine MySQL-Instanz. Diese erstellt alle notwendigen Tabellen wie `user`, `location`, `rtc_signal` und `usertype`.

### 2. Umgebungsvariablen (`.env`)
Erstelle eine `.env`-Datei im Root-Verzeichnis und hinterlege deine Zugangsdaten:

```
.env
# Datenbank-Zugang
DB_HOST=
DB_PORT=
DB_USER=
DB_PW=
DB_NAME=

# Sicherheit
APP_ENV=
PEPPER=dein_geheimer_pepper_string

# WebRTC TURN-Server (Metered.ca)
METERED_API_KEY=dein_api_key
METERED_APP_NAME=dein_api_name

# WebRTC STUN-Server (optional, kommagetrennt)
# Leer lassen fuer die eingebauten oeffentlichen Server. Diese Liste wird
# immer zusaetzlich zu den TURN-Zugangsdaten ausgeliefert, damit der Ausfall
# eines einzelnen Servers die Verbindung nicht verhindert.
STUN_SERVERS=

# E-Mail (SMTP)
SMTP_SERVER=dein.smtp-server.com
SMTP_PORT=587
SMTP_USERNAME=dein_login
SMTP_PASSWORD=dein_passwort
```

### 3. Logging und Logrotation

Das PHP-Fehlerlog liegt **ausserhalb des Document Root**, damit es nicht ueber
HTTP abrufbar ist. Den Pfad bestimmt `config/log_path.php`:

* Ist die Umgebungsvariable `LOG_PATH` gesetzt, gilt dieser Pfad.
* Sonst greift der Fallback `../logs/php-error.log` — also eine Ebene
  oberhalb des Webroots. Das Verzeichnis wird beim ersten Schreiben
  automatisch mit den Rechten `0750` angelegt.

`LOG_PATH` muss auf Server- oder Systemebene gesetzt werden, **nicht in der
`.env`** — die wird erst nach dem Fehler-Handler geladen:

```
Apache : SetEnv LOG_PATH /var/log/webrtc/php-error.log
nginx  : fastcgi_param LOG_PATH /var/log/webrtc/php-error.log;
Docker : environment: LOG_PATH=/var/log/webrtc/php-error.log
```

**Altlast:** Frühere Versionen schrieben nach `<Webroot>/php-error.log`.
Diese Datei kann noch existieren, ueber HTTP erreichbar sein und Secrets aus
alten Versionen enthalten. Sie wird nicht automatisch geloescht — bitte
manuell entfernen:

```
rm <Webroot>/php-error.log
```

#### Logrotation einrichten

Eine fertige Konfiguration liegt unter `deploy/logrotate/webrtc-app`. Sie wird
**nicht automatisch installiert**. Zur Einrichtung:

1. Datei oeffnen und **zwei Werte anpassen**: den Logpfad in der ersten Zeile
   und den Webserver-Benutzer (`www-data`, unter RHEL/CentOS `apache`).
2. Nach `/etc/logrotate.d/` kopieren:
   ```
   sudo cp deploy/logrotate/webrtc-app /etc/logrotate.d/webrtc-app
   sudo chown root:root /etc/logrotate.d/webrtc-app
   sudo chmod 644 /etc/logrotate.d/webrtc-app
   ```
3. Konfiguration testen, ohne etwas zu rotieren:
   ```
   sudo logrotate -d /etc/logrotate.d/webrtc-app
   ```

Voreinstellung: woechentliche Rotation, acht Generationen, komprimiert.
Ein Neustart von PHP-FPM oder Apache ist nach der Rotation nicht noetig.

---

## 🧪 Tests

Zwei Pruefskripte fuer die Verbindungsstabilitaet und das Steuerprotokoll der WebRTC-Funktion:

```bash
node tests/client_test.js     # Client-Logik (assets/js)
php  tests/server_test.php    # Serverlogik (class/)
```

Ohne Test-Framework, ohne Datenbank, ohne Netzwerk - beide sind gefahrlos
jederzeit ausfuehrbar. Details in [`tests/README.md`](tests/README.md).

---

## 👤 Autor
**Dominik Kusber** *Angehender Fachinformatiker für Anwendungsentwicklung* [GitHub Profile](https://github.com/dominik8891) | [Portfolio/Kontakt](mailto:deine@email.de)

---
*Dieses Projekt entstand im Rahmen eines Praktikums zur Dokumentation der Kompetenzen in PHP, WebRTC und relationalem Datenbankdesign.*

---

## ⚠️ Hinweis zur Projekthistorie
Da die ursprünglichen Datenbank-Dumps des Projekts (Stand vor 6 Monaten) nicht mehr verfügbar waren, wurde das SQL-Schema für diese Version auf Basis der bestehenden Models und Logik rekonstruiert. 

Aufgrund dieser nachträglichen Erstellung können Abweichungen zwischen der ursprünglichen Testumgebung und dem aktuellen Schema bestehen. Die aktuelle `database.sql` wurde jedoch nach bestem Wissen auf die aktuelle Code-Basis (PHP-Models) optimiert.

---
