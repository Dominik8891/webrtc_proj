-- ===========================================================================
-- Migration 005: Rollennummern neu vergeben
-- ===========================================================================
--
-- Bringt eine bestehende Datenbank auf die Nummern, mit denen das
-- Berechtigungssystem arbeitet (class/Helper/Role.php).
--
--   ALT                     NEU
--    0  Admin       ---->   10  Admin
--    1  Guide       ---->    2  Guide
--    2  User        ---->    1  User
--    3  Trial       ---->    0  Trial
--
-- WARUM UEBERHAUPT UMNUMMERIEREN
--   Die alten Nummern legten eine Rangfolge nahe: Admin war 0, und im Code
--   standen Vergleiche wie `role_id > 1` und `role_id <= 1`. Eine Rangfolge
--   gibt es aber nicht, und jeder dieser Vergleiche war falsch. Die neue
--   Vergabe laesst zwischen 2 (Guide) und 10 (Admin) bewusst Platz fuer
--   weitere Rollen, die nicht gleich Admin sein sollen. Wer die Luecke fuellt,
--   traegt die Rolle hier und in class/Helper/Permission.php ein - und
--   nirgends sonst.
--
-- WARUM DER UMWEG UEBER ZWISCHENNUMMERN
--   Aus 1 wird 2 und aus 2 wird 1. Ein direktes UPDATE wuerde je nach
--   Reihenfolge die eine Rolle in die andere kippen. Ausserdem haengt an
--   user.type_id ein Fremdschluessel auf usertype(id): Man kann eine Rolle
--   nicht umnummerieren, solange Benutzer auf sie zeigen. Deshalb in drei
--   Schritten - Zwischenzeilen anlegen, Benutzer umhaengen, Endzustand
--   herstellen. Der Fremdschluessel bleibt dabei die ganze Zeit aktiv; er
--   wird nicht abgeschaltet.
--
-- EIGENSCHAFTEN
--   * Idempotent: Ein zweiter Durchlauf findet nichts mehr zu tun und
--     aendert nichts.
--   * Kein Datenverlust: Es wird kein Benutzer geloescht und keine Rolle
--     verworfen. Ein Benutzer mit einer Nummer, die es in usertype nie gab,
--     kann wegen des Fremdschluessels nicht existieren.
--   * Laeuft unter MariaDB und MySQL 8.
--
-- NACH DER MIGRATION
--   Alle offenen Sitzungen sind ungueltig und muessen neu angemeldet werden.
--   Das erzwingt die Anwendung selbst (App\Helper\Auth::SESSION_SCHEME): Eine
--   Sitzung von vorher truege sonst stillschweigend die falsche Rolle - und
--   im Fall User(2) -> Guide(2) sogar mehr Rechte als vorher.
--
-- AUSFUEHREN
--   mariadb -u <user> -p <datenbank> < migrations/005_rollen_neu_nummeriert.sql
-- ===========================================================================

-- ---------------------------------------------------------------------------
-- Schritt 0: Laeuft diese Migration ueberhaupt noch auf einer alten Datenbank?
--
-- Erkennungsmerkmal ist die Zeile (0, 'Admin'). Steht dort schon 'Trial', ist
-- die Migration gelaufen; alle folgenden Anweisungen finden dann nichts mehr
-- und laufen ins Leere, ohne etwas zu veraendern.
-- ---------------------------------------------------------------------------
SELECT CONCAT('Ausgangslage: usertype.id 0 heisst derzeit "',
              COALESCE((SELECT name FROM usertype WHERE id = 0), '<nicht vorhanden>'),
              '"') AS hinweis;

-- ---------------------------------------------------------------------------
-- Schritt 1: Zwischenzeilen anlegen.
--
-- Die Nummern 900-903 sind frei gewaehlt und existieren nur waehrend dieser
-- Migration. Sie muessen in usertype stehen, bevor Benutzer auf sie zeigen
-- duerfen - sonst schlaegt der Fremdschluessel zu.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `usertype` (`id`, `name`) VALUES
(900, 'Trial'),
(901, 'User'),
(902, 'Guide'),
(910, 'Admin');

-- ---------------------------------------------------------------------------
-- Schritt 2: Benutzer auf die Zwischennummern umhaengen.
--
-- Zugeordnet wird ueber den NAMEN der bisherigen Rolle, nicht ueber deren
-- Nummer. Damit ist die Migration auch dann richtig, wenn eine Installation
-- die Nummern schon einmal von Hand verschoben hat. Die Bedingung auf die
-- alten Namen schuetzt zugleich vor einem zweiten Durchlauf.
-- ---------------------------------------------------------------------------
UPDATE `user` u
  JOIN `usertype` t ON u.type_id = t.id
   SET u.type_id = CASE t.name
                     WHEN 'Trial' THEN 900
                     WHEN 'User'  THEN 901
                     WHEN 'Guide' THEN 902
                     WHEN 'Admin' THEN 910
                   END
 WHERE t.id < 900
   AND t.name IN ('Trial', 'User', 'Guide', 'Admin');

-- ---------------------------------------------------------------------------
-- Schritt 3: Die alten Rollenzeilen entfernen.
--
-- Jetzt zeigt kein Benutzer mehr auf sie. Zeilen mit einem Namen, den es in
-- der Anwendung nicht gibt, bleiben absichtlich stehen: Sie sind entweder
-- unbenutzt oder tragen Daten, ueber die der Betreiber entscheiden muss.
-- ---------------------------------------------------------------------------
DELETE FROM `usertype`
 WHERE id < 900
   AND name IN ('Trial', 'User', 'Guide', 'Admin');

-- ---------------------------------------------------------------------------
-- Schritt 4: Endgueltige Nummern anlegen und Benutzer zurueckhaengen.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `usertype` (`id`, `name`) VALUES
( 0, 'Trial'),
( 1, 'User'),
( 2, 'Guide'),
(10, 'Admin');

UPDATE `user`
   SET type_id = CASE type_id
                   WHEN 900 THEN 0
                   WHEN 901 THEN 1
                   WHEN 902 THEN 2
                   WHEN 910 THEN 10
                 END
 WHERE type_id IN (900, 901, 902, 910);

-- ---------------------------------------------------------------------------
-- Schritt 5: Zwischenzeilen wieder entfernen.
-- ---------------------------------------------------------------------------
DELETE FROM `usertype` WHERE id IN (900, 901, 902, 910);

-- ---------------------------------------------------------------------------
-- Schritt 6: Vorgabewert der Spalte nachziehen.
--
-- Stand bisher auf 2 - das war die Rolle User und ist jetzt Guide. Ein
-- INSERT ohne type_id haette damit ein Konto angelegt, das sofort Standorte
-- anbieten darf. Neu ist die Vorgabe Trial (0), dieselbe Rolle, die
-- User::create() setzt.
-- ---------------------------------------------------------------------------
ALTER TABLE `user` MODIFY `type_id` int(11) DEFAULT 0;

-- ---------------------------------------------------------------------------
-- Ergebnis zur Kontrolle
-- ---------------------------------------------------------------------------
SELECT t.id, t.name, COUNT(u.id) AS benutzer
  FROM `usertype` t
  LEFT JOIN `user` u ON u.type_id = t.id
 GROUP BY t.id, t.name
 ORDER BY t.id;
