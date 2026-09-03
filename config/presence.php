<?php
/**
 * Taktung der Online-Erkennung ("Presence") und Dauer der Bereitschaft.
 *
 * ZWEI VERSCHIEDENE FRAGEN
 * ------------------------
 * Diese Datei beantwortet beide, weil sie sich gegenseitig bedingen - aber
 * sie meinen NICHT dasselbe:
 *
 *   "Ist ein Browser dieses Kontos erreichbar?"  -> user.user_status
 *   "Will dieser Guide gerade fuehren?"          -> user.available_until
 *
 * Frueher gab es nur die erste Frage, und die zweite wurde stillschweigend
 * mit ihr beantwortet: Wer die Seite offen liess, galt als verfuegbar. Wer
 * ueber Nacht einen Tab offen liess, wurde nachts angerufen.
 *
 * TAKT UND TIMEOUT (die erste Frage)
 * ----------------------------------
 *   heartbeat_interval  Abstand, in dem der eingeloggte Browser
 *                       index.php?act=heartbeat aufruft (assets/js/main.js
 *                       liest den Wert ueber window.heartbeatIntervalMs).
 *   offline_timeout     Alter von user.updated_at, ab dem der Cronjob
 *                       cron/check_online_status.php einen Nutzer offline
 *                       setzt.
 *
 * Der Timeout muss ein Vielfaches des Takts sein. Vorher standen sich 15 s
 * Takt und 20 s Timeout gegenueber: Ein einziger verzoegerter oder verlorener
 * Heartbeat - eine kurze Funkluecke, ein ausgebremster Hintergrund-Tab, eine
 * langsame Antwort - reichte aus, um einen aktiven Guide offline zu setzen und
 * damit aus der Standortuebersicht zu nehmen.
 *
 * 10 s Takt gegen 45 s Timeout vertraegt vier ausgefallene Heartbeats in
 * Folge. Die Kosten sind ueberschaubar: sechs sehr kleine Requests pro Minute
 * und eingeloggtem Nutzer. Ein abgestuerzter oder geschlossener Client
 * verschwindet dafuer spaetestens 45 s spaeter aus der Liste; beim regulaeren
 * Logout sofort, weil LoginController::handleLogout den Status direkt setzt.
 *
 * Wer die Werte aendert, aendert beide: Der Timeout sollte mindestens das
 * Dreifache des Takts betragen.
 *
 * DAUER DER BEREITSCHAFT (die zweite Frage)
 * -----------------------------------------
 *   availability_timeout  Wie lange eine eingeschaltete Bereitschaft OHNE
 *                         Bedienung stehen bleibt. Danach faellt der Guide
 *                         von selbst auf "nicht bereit" zurueck.
 *
 * DIES IST DIE EINE STELLE, an der die Frist steht. Sie geht von hier aus an
 * drei Verbraucher:
 *
 *   - App\Controller\UserController setzt und verlaengert damit
 *     user.available_until,
 *   - App\Helper\ViewHelper gibt sie als window.availabilityTimeoutMs an den
 *     Browser, der daraus die Restzeit im Schalter anzeigt,
 *   - cron/check_online_status.php raeumt abgelaufene Werte auf.
 *
 * VERLAENGERT wird die Frist nur von ECHTER Bedienung: Klick, Tastendruck,
 * Beruehrung - oder einem laufenden Gespraech. Der Heartbeat allein
 * verlaengert nichts. Genau das war der Fehler des alten Verhaltens: Ein
 * offener Tab ist keine Aussage darueber, ob jemand fuehren will.
 *
 * Zwei Stunden sind lang genug fuer eine Schicht, in der zwischen zwei
 * Fuehrungen laengere Pausen liegen, und kurz genug, dass ein vergessener Tab
 * noch am selben Vormittag von der Karte verschwindet. Wer den Wert aendert,
 * aendert ihn nur hier - im uebrigen Code steht keine zweite Zahl.
 */
return [
    'heartbeat_interval'   => 10,   // Sekunden
    'offline_timeout'      => 45,   // Sekunden
    'availability_timeout' => 7200, // Sekunden (2 Stunden)
];
