<?php
/**
 * Taktung der Online-Erkennung ("Presence").
 *
 * Beide Werte gehoeren zusammen und stehen deshalb an einer Stelle:
 *
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
 */
return [
    'heartbeat_interval' => 10, // Sekunden
    'offline_timeout'    => 45, // Sekunden
];
