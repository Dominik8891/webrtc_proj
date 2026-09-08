#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Sicherung von Datenbank und hochgeladenen Bildern.
#
# WAS ES SICHERT
#   1. Die Datenbank als mysqldump, gzip-komprimiert.
#   2. Den Upload-Baum (Standortbilder und Avatare) als tar.gz.
#
# WAS ES NICHT SICHERT
#   Die .env. Sie enthaelt das Datenbankpasswort, den PEPPER, die SMTP- und
#   die Metered-Zugangsdaten - alles im Klartext. In einem Backup, das
#   irgendwann auf einer zweiten Platte, in einem Cloudspeicher oder auf einem
#   USB-Stick landet, haben diese Werte nichts zu suchen. Die .env gehoert an
#   die Stelle, an der auch das Zertifikat liegt: in die Obhut des Betreibers.
#   Was in ihr stehen muss, steht in .env.example.
#
#   Ebenso wenig die Logdateien - dafuer ist logrotate zustaendig
#   (deploy/logrotate/webrtc-app).
#
# DIESES SKRIPT WIRD NICHT INSTALLIERT. Es liegt hier, wird vom Betreiber
# geprueft, angepasst und in die crontab eingetragen. Die Anleitung dazu steht
# in der README, Abschnitt "Backups".
#
# AUFRUF
#   bash deploy/backup/backup.sh
#
# Es gibt im Erfolgsfall eine Zeile je gesicherter Datei aus und endet mit
# Status 0. Jeder Fehler geht nach stderr und endet mit Status != 0 - cron
# verschickt das dann als Mail, und genau das ist gewollt: Ein Backup, das
# stillschweigend nicht laeuft, ist schlimmer als keines, weil sich niemand
# mehr darum kuemmert.
# ---------------------------------------------------------------------------

# -e  bricht bei jedem Fehler ab, -u bei einer nicht gesetzten Variablen,
# pipefail auch dann, wenn der Fehler im linken Teil einer Pipe steckt
# (mysqldump | gzip - ohne pipefail waere ein abgebrochener Dump ein
# erfolgreicher gzip-Aufruf und damit ein "erfolgreiches" leeres Backup).
set -euo pipefail

# Das Projektverzeichnis - zwei Ebenen ueber dieser Datei.
PROJEKT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENV_DATEI="$PROJEKT/.env"

fehler() { echo "backup.sh: $*" >&2; exit 1; }
hinweis() { echo "backup.sh: $*" >&2; }

# ---------------------------------------------------------------------------
# Konfiguration lesen.
#
# REIHENFOLGE WIE IN DER ANWENDUNG (siehe class/Helper/Env.php): Was in der
# Umgebung steht, gewinnt gegen die .env. Damit laesst sich ein einzelner Lauf
# umlenken, ohne die Datei anzufassen:
#   BACKUP_PATH=/mnt/usb bash deploy/backup/backup.sh
#
# Gelesen wird die .env mit sed und nicht mit "source": Die Datei ist eine
# Konfigurationsdatei und kein Shell-Skript. Ein Wert mit einem Leerzeichen,
# einem Dollarzeichen oder einem Semikolon wuerde bei "source" ausgefuehrt
# statt gelesen - ein Datenbankpasswort ist genau der Ort, an dem solche
# Zeichen vorkommen.
# ---------------------------------------------------------------------------
env_wert() {
    [ -r "$ENV_DATEI" ] || return 0
    sed -n "s/^[[:space:]]*$1[[:space:]]*=[[:space:]]*//p" "$ENV_DATEI" \
        | tail -n 1 \
        | sed -e 's/[[:space:]]*$//' -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/"
}

# $1 = Name, $2 = Vorgabe (leer = Pflichtwert)
konfig() {
    local name="$1" vorgabe="${2-}" wert
    wert="${!name-}"
    [ -n "$wert" ] || wert="$(env_wert "$name")"
    [ -n "$wert" ] || wert="$vorgabe"
    [ -n "$wert" ] || fehler "$name ist weder in der Umgebung noch in $ENV_DATEI gesetzt."
    printf '%s' "$wert"
}

DB_HOST="$(konfig DB_HOST localhost)"
DB_PORT="$(konfig DB_PORT 3306)"
DB_NAME="$(konfig DB_NAME)"
DB_USER="$(konfig DB_USER)"
DB_PW="$(konfig DB_PW)"

# Die Vorgaben sind dieselben wie in der Anwendung: eine Ebene OBERHALB des
# Webroots. Was unter dem Webroot liegt, ist ueber HTTP abrufbar - und ein
# Datenbankdump, den man sich herunterladen kann, ist der Ernstfall selbst.
UPLOAD_PATH="$(konfig UPLOAD_PATH "$PROJEKT/../uploads")"
BACKUP_PATH="$(konfig BACKUP_PATH "$PROJEKT/../backups")"
KEEP_DAYS="$(konfig BACKUP_KEEP_DAYS 14)"

case "$KEEP_DAYS" in
    ''|*[!0-9]*) fehler "BACKUP_KEEP_DAYS muss eine Zahl sein, ist aber '$KEEP_DAYS'." ;;
esac

# ---------------------------------------------------------------------------
# Werkzeuge suchen. MariaDB liefert seit 11.x nur noch "mariadb-dump" aus;
# "mysqldump" ist dort ein Symlink, der irgendwann entfaellt.
# ---------------------------------------------------------------------------
if command -v mysqldump >/dev/null 2>&1; then
    DUMP_BEFEHL="mysqldump"
elif command -v mariadb-dump >/dev/null 2>&1; then
    DUMP_BEFEHL="mariadb-dump"
else
    fehler "Weder mysqldump noch mariadb-dump gefunden."
fi

# ---------------------------------------------------------------------------
# Zielverzeichnis. 0700, weil ein Dump ALLES enthaelt: Adressen, Passworthashes,
# 2FA-Geheimnisse, Chatverlaeufe. Ein Backup ist nicht weniger schutzbeduerftig
# als die Datenbank - es ist dieselbe Datenbank, nur ohne Rechtepruefung.
# ---------------------------------------------------------------------------
mkdir -p "$BACKUP_PATH" || fehler "Zielverzeichnis $BACKUP_PATH laesst sich nicht anlegen."
chmod 700 "$BACKUP_PATH"
[ -w "$BACKUP_PATH" ] || fehler "Zielverzeichnis $BACKUP_PATH ist nicht beschreibbar."

# Alles, was ab hier entsteht, gehoert nur dem Eigentuemer.
umask 077

STEMPEL="$(date +%Y-%m-%d_%H%M%S)"
DB_ZIEL="$BACKUP_PATH/db_$STEMPEL.sql.gz"
UP_ZIEL="$BACKUP_PATH/uploads_$STEMPEL.tar.gz"

# ---------------------------------------------------------------------------
# Das Passwort geht NICHT als Argument an mysqldump.
#
# Argumente stehen in der Prozessliste: Ein "ps aux" waehrend des Laufs zeigte
# jedem angemeldeten Benutzer des Servers das Datenbankpasswort. Deshalb eine
# temporaere Optionsdatei, die nur der Eigentuemer lesen darf und die in jedem
# Fall wieder verschwindet - auch bei Abbruch (trap).
# ---------------------------------------------------------------------------
CNF="$(mktemp)"
UNFERTIG=""
aufraeumen() {
    rm -f "$CNF"
    # Eine halb geschriebene Sicherung ist gefaehrlicher als gar keine: Sie
    # sieht im Verzeichnis aus wie ein Backup. Beim Abbruch fliegt sie raus.
    [ -z "$UNFERTIG" ] || rm -f "$UNFERTIG"
}
trap aufraeumen EXIT

chmod 600 "$CNF"
{
    echo "[client]"
    echo "host=$DB_HOST"
    echo "port=$DB_PORT"
    echo "user=$DB_USER"
    echo "password=$DB_PW"
} > "$CNF"

# ---------------------------------------------------------------------------
# 1. Die Datenbank.
#
#   --defaults-extra-file  MUSS das erste Argument sein (Vorgabe von mysqldump)
#   --single-transaction   sichert konsistent, ohne die Tabellen zu sperren -
#                          die Anwendung laeuft waehrend des Backups weiter
#   --quick                Zeile fuer Zeile statt alles in den Speicher
#   --routines --events    was sonst stillschweigend fehlt und beim
#                          Wiederherstellen niemandem auffaellt
#   --no-tablespaces       sonst braucht der Backup-Benutzer das Recht PROCESS
# ---------------------------------------------------------------------------
UNFERTIG="$DB_ZIEL"
"$DUMP_BEFEHL" --defaults-extra-file="$CNF" \
    --single-transaction --quick --routines --events --no-tablespaces \
    --default-character-set=utf8mb4 \
    "$DB_NAME" | gzip -c > "$DB_ZIEL"

# Ein Dump, den gzip nicht wieder auspacken kann, ist kein Backup. Der Test
# kostet Sekunden und ist der einzige Beweis, den dieses Skript ueberhaupt
# fuehren kann.
gzip -t "$DB_ZIEL" || fehler "Die Sicherung der Datenbank ist unbrauchbar ($DB_ZIEL)."
UNFERTIG=""
echo "Datenbank gesichert: $DB_ZIEL ($(du -h "$DB_ZIEL" | cut -f1))"

# ---------------------------------------------------------------------------
# 2. Die hochgeladenen Bilder.
#
# Fehlt das Verzeichnis, ist das KEIN Fehler: Eine frische Installation hat
# noch keinen Upload gesehen, und das Verzeichnis entsteht erst beim ersten.
# Ein Abbruch an dieser Stelle wuerde die eben erstellte Datenbanksicherung
# entwerten - gemeldet wird es trotzdem.
# ---------------------------------------------------------------------------
if [ -d "$UPLOAD_PATH" ]; then
    UPLOAD_ABS="$(cd "$UPLOAD_PATH" && pwd)"
    UNFERTIG="$UP_ZIEL"
    tar -czf "$UP_ZIEL" -C "$(dirname "$UPLOAD_ABS")" "$(basename "$UPLOAD_ABS")"
    gzip -t "$UP_ZIEL" || fehler "Die Sicherung der Bilder ist unbrauchbar ($UP_ZIEL)."
    UNFERTIG=""
    echo "Bilder gesichert:   $UP_ZIEL ($(du -h "$UP_ZIEL" | cut -f1))"
else
    hinweis "Upload-Verzeichnis $UPLOAD_PATH gibt es nicht - nichts zu sichern."
fi

# ---------------------------------------------------------------------------
# 3. Aufraeumen.
#
# GELOESCHT WIRD NUR, WAS DIESES SKRIPT SELBST ANGELEGT HAT: -maxdepth 1 und
# zwei ausgeschriebene Namensmuster. Ein Zielverzeichnis, in dem noch etwas
# anderes liegt, verliert nichts - auch dann nicht, wenn jemand BACKUP_PATH
# versehentlich auf sein Heimatverzeichnis stellt.
#
# BACKUP_KEEP_DAYS=0 heisst "nichts loeschen". Wer die Aufbewahrung woanders
# regelt (Bandlaufwerk, Snapshot, rsync auf einen zweiten Server), traegt die
# Null ein und behaelt hier alles.
# ---------------------------------------------------------------------------
if [ "$KEEP_DAYS" -gt 0 ]; then
    find "$BACKUP_PATH" -maxdepth 1 -type f \
        \( -name 'db_*.sql.gz' -o -name 'uploads_*.tar.gz' \) \
        -mtime "+$KEEP_DAYS" -print -delete \
        | sed 's/^/Entfernt (aelter als '"$KEEP_DAYS"' Tage): /' >&2
fi

exit 0
