<?php
/**
 * Aufbewahrungsdauer der Chatnachrichten.
 *
 * Der Chat dieser Anwendung ist ein Absprachekanal vor einer Fuehrung -
 * "bin in zehn Minuten da", "welcher Eingang?". Er ist kein Archiv. Ein
 * Verlauf, der ewig steht, ist deshalb kein Gewinn, sondern eine Sammlung
 * personenbezogener Daten ohne Zweck: Er waechst, er muss gesichert werden,
 * und bei einer Auskunft oder einem Loeschbegehren muss jemand hinein.
 *
 * Deshalb loescht cron/cleanup_chat_messages.php Nachrichten, die aelter als
 * die hier eingetragene Zahl von Tagen sind. Endgueltig, nicht als
 * Soft-Delete - ein "geloescht"-Haken erfuellt den Zweck nicht.
 *
 * DIESE DATEI IST DIE EINZIGE STELLE MIT DER ZAHL.
 * Sie versorgt drei Verbraucher:
 *
 *   cron/cleanup_chat_messages.php  loescht danach
 *   class/Helper/ViewHelper.php     reicht sie als window.chatRetentionDays
 *                                   ins Frontend (assets/js/ui_chat.js zeigt
 *                                   den Hinweis im Chatfenster)
 *   class/Controller/ChatController die Verlaufsseite (show_chat.html)
 *
 * Wer die Dauer aendert, aendert sie hier - Hinweistext und Loeschlauf
 * ziehen von selbst nach. Eine im Text ausgeschriebene Zahl waere genau die
 * Art von Angabe, die spaeter nicht mehr stimmt.
 *
 * Null oder ein negativer Wert schaltet die Loeschung ab; der Cronjob bricht
 * dann ohne Aenderung ab und im Chatfenster steht kein Hinweis.
 */
return [
    'retention_days' => 30,
];
