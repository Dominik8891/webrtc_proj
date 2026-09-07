-- ===========================================================================
-- Migration 018: Die Bremse - serverseitige Versuchszaehler
-- ===========================================================================
--
-- DER BEFUND
--   Drei Stellen nahmen unbegrenzt viele Versuche an.
--
--   1. DER LOGIN hatte eine Bremse, aber sie lag in $_SESSION
--      (LoginController). Eine Session steht im Cookie des Aufrufers: Wer es
--      verwirft, faengt bei null an; ein Skript, das gar keine Cookies
--      annimmt, hatte nie ein Limit. Gebremst wurde damit ausschliesslich
--      der ehrliche Nutzer, der sein Passwort dreimal falsch tippt.
--   2. DIE ZWEI-FAKTOR-PRUEFUNG hatte gar keinen Zaehler
--      (TwoFactorController::handle2FAVerify). Sechs Stellen sind
--      durchprobierbar, wenn man beliebig oft probieren darf.
--   3. DIE REGISTRIERUNG hatte keine Bremse. Konten waren unbegrenzt und
--      kostenlos - und jeder spaetere Missbrauch faengt mit einem Konto an.
--
--   Ein Zaehler, der beim Aufrufer liegt, ist kein Zaehler. Er gehoert
--   hierher.
--
-- WAS EINE ZEILE IST
--   Eine Zeile ist EIN ZAEHLER: die Antwort auf "wie oft hat DIESER
--   Schluessel DIESE Aktion in diesem Zeitfenster versucht". Der Schluessel
--   ist kein Konto und keine IP, sondern was die Schranke daraus baut
--   (config/limits.php) - beim Login zum Beispiel das PAAR aus beidem.
--
--   Der eindeutige Schluessel ueber (aktion, schranke, schluessel) macht
--   daraus die Regel: JE SCHRANKE UND SCHLUESSEL GENAU EIN ZAEHLER. Sie
--   steht in der Tabelle und nicht nur im Code, weil zwei gleichzeitige
--   Anmeldeversuche sonst zwei Zeilen mit je einem Versuch ergaeben - und
--   damit gar keine Bremse. Hochgezaehlt wird mit einem einzigen
--   INSERT ... ON DUPLICATE KEY UPDATE (App\Model\RateLimit::verbuchen), also
--   in einem Schritt und ohne Lesen-Rechnen-Schreiben dazwischen.
--
-- WARUM gesperrt_bis UND KEINE JA/NEIN-MARKE
--   Dieselbe Ueberlegung wie bei der Bereitschaft (Migration 010) und bei der
--   Anfrage (Migration 013): Steht der Ablauf in der Zeile, ist "gesperrt"
--   allein aus der Zeile ablesbar. Jede Pruefung vergleicht selbst mit NOW();
--   der Cronjob ist AUFRAEUMEN und keine Pruefung. Die Bremse wirkt deshalb
--   auch dann, wenn der Job gar nicht eingerichtet ist - und eine Sperre
--   endet auch dann von selbst, wenn er nie laeuft.
--
-- WARUM EIN FESTES FENSTER UND KEINE ZEILE JE VERSUCH
--   Ein gleitendes Fenster waere genauer: eine Zeile je Versuch, gezaehlt
--   wird ueber die letzten n Sekunden. Es kostet aber eine Zeile je
--   Anmeldeversuch - und genau die Menge, die eine Bremse abwehren soll, ist
--   die, die diese Tabelle dann vollschreibt. Der Angriff wuerde die Kosten
--   seiner eigenen Abwehr bestimmen.
--
--   Ein fester Zaehler je Schluessel kostet eine Zeile, egal wie viele
--   Versuche kommen. Der Preis ist eine Unschaerfe am Fensterrand: Wer den
--   Zeitpunkt kennt, an dem das Fenster umspringt, bekommt kurz das Doppelte
--   der Versuche. Bei fuenf Versuchen je Viertelstunde sind das zehn - fuer
--   die Frage, um die es hier geht, ohne Bedeutung.
--
-- WARUM KEIN FREMDSCHLUESSEL AUF user
--   Weil der Schluessel meistens gar kein Konto ist, sondern eine IP, ein
--   Paar aus Name und IP oder ein Benutzername, den es gar nicht gibt - das
--   sind die interessanten Faelle. Die Spalte traegt Text, nicht eine
--   Kennung, und `user` ist nicht die Wahrheit darueber, wer hier anklopft.
--
-- WAS HIER NICHT STEHT
--   Keine Passwoerter, keine Codes, keine Zeitstempel einzelner Versuche -
--   nur ein Zaehler je Schluessel. Die Zeile beantwortet "wie oft", nicht
--   "was". Was der Betrieb zum Nachvollziehen braucht, steht im Log
--   (error_log), und dort maskiert.
--
-- EIGENSCHAFTEN
--   * Idempotent: CREATE TABLE IF NOT EXISTS. Ein zweiter Lauf aendert
--     nichts.
--   * Kein Datenverlust: Es kommt eine Tabelle hinzu, keine bestehende wird
--     angefasst.
--   * Der Bestand bekommt nichts: Es gibt keine vergangenen Versuche, die
--     sich nachtragen liessen - die bisherigen lagen in Sessions und sind
--     mit ihnen weg. Gezaehlt wird ab dem ersten Versuch nach dem
--     Einspielen.
--   * KEIN NEUSTART NOETIG, aber die Anwendung braucht die Tabelle: Wird der
--     Code ohne diese Wanderung ausgeliefert, schlaegt jede Anmeldung fehl
--     (App\Model\RateLimit laesst einen Fehler durch, statt die Bremse still
--     zu ueberspringen - siehe dort). Erst wandern, dann ausliefern.
--
-- AUFRAEUMEN
--   cron/check_online_status.php ruft App\Model\RateLimit::aufraeumen() auf
--   und loescht Zeilen, deren Fenster UND deren Sperre abgelaufen sind. Ohne
--   Cron waechst die Tabelle - fachlich falsch wird nichts.
--
-- AUSFUEHREN
--   mariadb -u <user> -p <datenbank> < migrations/018_bremse.sql
-- ===========================================================================

CREATE TABLE IF NOT EXISTS `rate_limit` (
  `id` int(11) NOT NULL AUTO_INCREMENT,

  -- Die Aktion, um die es geht: 'login', '2fa', 'signup', ... Die erlaubten
  -- Werte stehen in config/limits.php und nirgends sonst.
  `aktion` varchar(32) NOT NULL,

  -- Welche der Schranken dieser Aktion. Sie steht als eigene Spalte, weil
  -- zwei Schranken denselben Schluessel haben koennen: 'ip_stunde' und
  -- 'ip_tag' der Registrierung zaehlen beide je IP, aber getrennt.
  `schranke` varchar(32) NOT NULL,

  -- Woran der Zaehler haengt - gebaut aus den Teilen, die die Schranke
  -- verlangt (App\Model\RateLimit::schluessel). Ein Benutzername, eine IP,
  -- beides zusammengesetzt. Zu lange Werte kommen als Kurzfassung an, damit
  -- der eindeutige Schluessel nicht an der Zeilenlaenge scheitert; die
  -- Laenge hier und die Kappungsgrenze dort gehoeren zusammen.
  `schluessel` varchar(190) NOT NULL,

  -- Der Stand im laufenden Fenster.
  `versuche` int(11) NOT NULL DEFAULT 0,

  -- Wann das laufende Zaehlfenster endet. Ein Versuch nach diesem Zeitpunkt
  -- setzt den Zaehler auf 1 und beginnt ein neues Fenster - der Ablauf steht
  -- in der Zeile, es braucht dafuer keinen Cronjob.
  `fenster_bis` datetime NOT NULL,

  -- Bis wann abgewiesen wird. NULL heisst "nicht gesperrt", und das ist der
  -- Regelfall - deshalb ist es der Vorgabewert. Ein Zeitpunkt in der
  -- VERGANGENHEIT heisst dasselbe: Jede Pruefung vergleicht mit NOW()
  -- (App\Model\RateLimit::restsperre), damit eine Sperre auch ohne Cron
  -- endet.
  `gesperrt_bis` datetime DEFAULT NULL,

  -- Wann zuletzt gezaehlt wurde. Nicht fuer die Bremse - die braucht ihn
  -- nicht -, sondern fuer den Menschen, der nachsieht, warum jemand nicht
  -- hereinkommt.
  `letzter_versuch` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),

  -- JE SCHRANKE UND SCHLUESSEL GENAU EIN ZAEHLER. Die Regel steht hier und
  -- nicht nur im Code: Zwei gleichzeitige Versuche sollen nicht zwei Zeilen
  -- mit je einem Versuch ergeben. Auf diesen Schluessel stuetzt sich das
  -- ON DUPLICATE KEY UPDATE beim Hochzaehlen.
  UNIQUE KEY `ein_zaehler` (`aktion`, `schranke`, `schluessel`),

  -- Der Cronjob sucht nach Zeilen, deren Fenster abgelaufen ist.
  KEY `aufraeumen` (`fenster_bis`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Ergebnis zur Kontrolle
--
-- Direkt nach dem Einspielen sind alle Zahlen 0 - gezaehlt wird ab dem ersten
-- Versuch danach. "gesperrt" ist die Zahl der Schluessel, die gerade
-- abgewiesen werden; sie ist im Normalbetrieb klein und dauerhaft grosse
-- Werte sind ein Hinweis, dass jemand systematisch anklopft.
-- ---------------------------------------------------------------------------
SELECT COUNT(*)                                  AS zaehler,
       SUM(`gesperrt_bis` > NOW())               AS gesperrt,
       COUNT(DISTINCT `aktion`)                  AS aktionen
  FROM `rate_limit`;
