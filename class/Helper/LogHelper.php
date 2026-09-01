<?php

namespace App\Helper;

/**
 * Hilfsklasse fuer das sichere Protokollieren personenbezogener Daten.
 *
 * Logdateien werden haeufig von mehreren Personen gelesen, archiviert und
 * gesichert. Vollstaendige E-Mail-Adressen haben dort nichts verloren.
 * Fuer die Fehlersuche genuegt es, die Domain und den ersten Buchstaben
 * zu sehen - damit laesst sich ein Vorgang wiedererkennen, ohne die
 * Adresse preiszugeben.
 */
class LogHelper
{
    /**
     * Maskiert eine E-Mail-Adresse fuer die Ausgabe im Log.
     *
     * Regel: Das erste Zeichen des Local Part bleibt stehen, der Rest wird
     * durch drei Sterne ersetzt. Die Domain bleibt vollstaendig erhalten.
     *
     * Beispiele:
     *   dominik@example.com  ->  d***@example.com
     *   ab@example.com       ->  a***@example.com
     *   a@example.com        ->  ***@example.com     (siehe Sonderfaelle)
     *   @example.com         ->  ***@example.com
     *   nichtvalide          ->  n***
     *   ""                   ->  ***
     *
     * Sonderfaelle:
     * - Local Part mit nur EINEM Zeichen: Wuerde man hier "das erste Zeichen
     *   behalten", waere die Adresse vollstaendig sichtbar - die Maskierung
     *   liefe ins Leere. Deshalb wird der Local Part in diesem Fall komplett
     *   durch Sterne ersetzt.
     * - Fehlendes @: Der Wert wird wie ein reiner Local Part behandelt und
     *   nach derselben Regel maskiert. Eine Domain wird nicht erfunden.
     *   Das kommt vor allem bei Validierungsfehlern vor, wo der Eingabewert
     *   gar keine gueltige Adresse ist.
     * - Mehrere @: Getrennt wird am LETZTEN @, weil nach RFC 5321 alles
     *   danach die Domain ist.
     * - Leerer Wert oder null: Rueckgabe "***", damit im Log nie eine leere
     *   Stelle steht, die man fuer einen Fehler der Maskierung halten koennte.
     *
     * @param  string|null $email Die zu maskierende Adresse
     * @return string             Maskierte Fassung, niemals leer
     */
    public static function maskEmail(?string $email): string
    {
        // Leere oder nicht gesetzte Werte gar nicht erst zerlegen
        $email = $email === null ? '' : trim($email);
        if ($email === '') {
            return '***';
        }

        // Am letzten @ trennen: alles danach ist laut RFC die Domain
        $atPos = strrpos($email, '@');

        if ($atPos === false) {
            // Kein @ vorhanden: kompletten Wert als Local Part behandeln
            return self::maskLocalPart($email);
        }

        $localPart = substr($email, 0, $atPos);
        // Domain inklusive @ uebernehmen, damit auch "user@" sauber bleibt
        $domain    = substr($email, $atPos);

        return self::maskLocalPart($localPart) . $domain;
    }

    /**
     * Maskiert den Local Part einer Adresse.
     *
     * Ab zwei Zeichen bleibt das erste stehen, sonst wird alles ersetzt.
     * Die Anzahl der Sterne ist bewusst fest (immer drei) und spiegelt
     * NICHT die echte Laenge wider - sonst liesse sich aus dem Log auf
     * die Laenge des Local Part schliessen.
     *
     * @param  string $localPart Teil vor dem @
     * @return string            Maskierter Local Part
     */
    private static function maskLocalPart(string $localPart): string
    {
        // Multibyte-sicher, falls die Adresse Umlaute enthaelt (IDN/SMTPUTF8)
        $length = function_exists('mb_strlen')
            ? mb_strlen($localPart, 'UTF-8')
            : strlen($localPart);

        if ($length < 2) {
            // Leer oder nur ein Zeichen: nichts stehen lassen, sonst waere
            // die Adresse trotz "Maskierung" vollstaendig lesbar.
            return '***';
        }

        $firstChar = function_exists('mb_substr')
            ? mb_substr($localPart, 0, 1, 'UTF-8')
            : substr($localPart, 0, 1);

        return $firstChar . '***';
    }
}
