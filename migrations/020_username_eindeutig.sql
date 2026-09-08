-- ===========================================================================
-- Migration 020: Der Benutzername wird eindeutig
-- ===========================================================================
--
-- DER BEFUND (S-14 der Bestandsaufnahme)
--   Auf `user`.`username` lag kein eindeutiger Index. Die Eindeutigkeit hing
--   allein an einer Abfrage im Anwendungscode - und die fragte zusaetzlich
--   nach "AND deleted = 0". Beides zusammen ergab zwei Loecher:
--
--   1. DER BENUTZERNAME EINES GELOESCHTEN KONTOS liess sich neu vergeben.
--      Die Pruefung sah die Zeile nicht, ein Index, der sie gesehen haette,
--      gab es nicht - es entstand einfach ein zweites Konto mit demselben
--      Namen. Aufgefallen ist das nicht, weil auch login() auf
--      "deleted = 0" filtert und deshalb weiterhin nur eine Zeile findet.
--
--   2. DAS FENSTER ZWISCHEN PRUEFUNG UND ANLAGE. Zwei gleichzeitige
--      Registrierungen sehen beide "frei" und legen beide an. Das Ergebnis
--      sind ZWEI LEBENDE Konten mit demselben Namen - und dann entscheidet
--      das fetch() in User::login(), welches von beiden man bekommt, wenn man
--      sich anmeldet. Der Benutzername ist die Anmeldekennung; er darf nicht
--      zweideutig sein.
--
--   Bei der E-Mail-Adresse gab es den Index laengst (UNIQUE KEY `email`).
--   Genau deshalb fiel dort Loch 1 auf: Der INSERT scheiterte, und der Nutzer
--   bekam "Ein unbekannter Fehler ist aufgetreten." zu sehen. Beim
--   Benutzernamen scheiterte nichts - es ging still durch. Das ist der
--   unangenehmere der beiden Faelle.
--
-- WAS DIESE MIGRATION TUT
--   Sie zieht den fehlenden Index nach. Der Anwendungscode fragt seit
--   derselben Aenderung ohne "deleted"-Filter (App\Model\User::usernameStand),
--   damit in Code und Datenbank dieselbe Regel gilt - vorher waren es zwei
--   verschiedene, und die Datenbank hatte recht.
--
-- REIHENFOLGE: ERST SCHRITT 1 LESEN, DANN 2 UND 3 AUSFUEHREN.
--   Schritt 1 ist eine reine Abfrage und muss LEER sein. Ist sie es nicht,
--   scheitert Schritt 3 ohnehin - dann aber mit einer Fehlermeldung des
--   Servers statt mit der Liste, die man braucht.
-- ===========================================================================


-- ---------------------------------------------------------------------------
-- SCHRITT 1  (nur lesen)  Gibt es zwei LEBENDE Konten mit demselben Namen?
--
-- Diese Zeilen kann keine Migration reparieren, und zwar aus einem Grund, der
-- sich nicht wegprogrammieren laesst: Beide Konten koennen echt sein, beide
-- koennen Standorte, Anfragen und Bewertungen tragen, und welches den Namen
-- behalten darf, ist eine Entscheidung ueber Menschen und nicht ueber Daten.
-- Sie gehoert dem Betreiber.
--
-- IST DAS ERGEBNIS LEER, ist alles in Ordnung - weiter mit Schritt 2.
--
-- Ist es das nicht: Die betroffenen Konten von Hand ansehen, eines davon
-- umbenennen (UPDATE `user` SET `username` = '...' WHERE `id` = ...) und die
-- Betroffenen darueber unterrichten - der Name ist ihre Anmeldekennung.
-- ---------------------------------------------------------------------------
SELECT `username`,
       COUNT(*)                      AS konten,
       GROUP_CONCAT(`id` ORDER BY `id`) AS kennungen
  FROM `user`
 WHERE `deleted` = 0
 GROUP BY `username`
HAVING COUNT(*) > 1;


-- ---------------------------------------------------------------------------
-- SCHRITT 2  Namen GELOESCHTER Konten aus dem Weg raeumen
--
-- Betroffen ist nur, wessen Name noch ein zweites Mal vorkommt - ein
-- geloeschtes Konto mit einem Namen, den sonst niemand traegt, behaelt ihn.
--
-- WARUM DAS UNBEDENKLICH IST: Der Benutzername eines geloeschten Kontos wird
-- nirgends mehr angezeigt. Wo ein Name stehen muesste, steht die Konstante
-- App\Model\User::NAME_GELOESCHT ("Gelöschtes Konto"), und anmelden kann sich
-- das Konto ohnehin nicht mehr (login() filtert auf deleted = 0). Der Wert in
-- der Spalte ist damit nur noch ein Platzhalter, der einen Namen blockiert.
--
-- WARUM MIT DER KENNUNG DAHINTER: Sie ist eindeutig, also kann das Ergebnis
-- nicht selbst wieder kollidieren - auch dann nicht, wenn gleich mehrere
-- geloeschte Konten denselben Namen tragen. LEFT(...,200) haelt Abstand zum
-- Spaltenende (varchar(255)); ein Benutzername ist ohnehin auf 20 Zeichen
-- begrenzt, aber die Spalte weiss das nicht.
--
-- Die abgeleitete Tabelle ist Absicht: MySQL laesst in einem UPDATE keine
-- Unterabfrage auf dieselbe Tabelle zu, ueber einen JOIN aber schon.
-- ---------------------------------------------------------------------------
UPDATE `user` AS u
  JOIN (SELECT `username`
          FROM `user`
         GROUP BY `username`
        HAVING COUNT(*) > 1) AS doppelt
    ON doppelt.`username` = u.`username`
   SET u.`username` = CONCAT(LEFT(u.`username`, 200), '_geloescht_', u.`id`)
 WHERE u.`deleted` = 1;


-- ---------------------------------------------------------------------------
-- SCHRITT 3  Der Index
--
-- Ab hier gilt die Regel in der Datenbank und nicht mehr nur im Code. Sie ist
-- damit auch dann noch da, wenn zwei Registrierungen gleichzeitig ankommen -
-- die Vorabpruefung im Controller ist die Bequemlichkeit, dieser Index ist die
-- Zusage. App\Model\User::register() behandelt sein Zuschlagen (SQLSTATE
-- 23000) ausdruecklich als Regelfall und nicht als Stoerung.
--
-- ER FILTERT NICHT AUF `deleted`, und das kann er auch nicht: MySQL kennt
-- keine Teilindizes mit Bedingung. Der Name eines geloeschten Kontos bleibt
-- deshalb dauerhaft belegt - genau wie die E-Mail-Adresse, bei der der Index
-- schon immer so wirkte. Der Anwendungscode sagt das dem Nutzer jetzt auch
-- (SignupController, Meldung "username_geloescht").
-- ---------------------------------------------------------------------------
ALTER TABLE `user`
  ADD UNIQUE KEY `username` (`username`);


-- ---------------------------------------------------------------------------
-- Ergebnis zur Kontrolle
--
-- `konten` und `namen` muessen gleich sein - das ist die Aussage des Index,
-- noch einmal aus den Daten gelesen. `umbenannt` zaehlt, was Schritt 2
-- angefasst hat; im Regelfall ist das 0.
-- ---------------------------------------------------------------------------
SELECT COUNT(*)                                        AS konten,
       COUNT(DISTINCT `username`)                      AS namen,
       SUM(`username` LIKE '%\_geloescht\_%')          AS umbenannt
  FROM `user`;
