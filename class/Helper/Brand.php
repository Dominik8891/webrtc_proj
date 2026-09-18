<?php
namespace App\Helper;

/**
 * Der Produktname - AN GENAU EINER STELLE.
 *
 * WOZU DIESE KLASSE
 * -----------------
 * Der Name stand woertlich in assets/html/index.html, und zwar dreimal: im
 * <title>, in der Kopfleiste und in der Fusszeile. Drei Literale heissen drei
 * Handgriffe, sobald ein Name feststeht - und beim vierten Vorkommen wird
 * eines davon vergessen. Seit es die Landingpage gibt, waeren es ohnehin
 * mehr geworden: Eine Werbeseite nennt das Produkt mehrfach.
 *
 * DER NAME STEHT NOCH NICHT FEST. Was hier steht, ist ein Platzhalter, und er
 * ist mit Absicht als solcher zu erkennen: Ein huebscher Arbeitstitel bleibt
 * stehen, weil er niemandem auffaellt.
 *
 * ---------------------------------------------------------------------------
 * HIER WIRD DER NAME GEAENDERT - UND SONST NIRGENDS:
 *
 *     const NAME  der ausgeschriebene Name. Er erscheint in der Kopfleiste,
 *                 in der Fusszeile, im Titel des Browserfensters und auf der
 *                 Landingpage.
 *     const MARK  das Zeichen im farbigen Kaestchen daneben. Ein bis zwei
 *                 Zeichen - mehr passt nicht in das Quadrat
 *                 (.app-topbar__mark in assets/css/theme.css).
 * ---------------------------------------------------------------------------
 *
 * WARUM NICHT IM SPRACHKATALOG
 * ----------------------------
 * Weil ein Produktname keine Uebersetzung hat. Er ist in jeder Sprache
 * derselbe - stuende er in lang/de.php und lang/en.php, waeren es wieder zwei
 * Stellen, und die zweite waere die, die beim Umbenennen vergessen wird. Aus
 * demselben Grund steht er nicht in der .env: Er gehoert zum Produkt und
 * nicht zu dieser Installation.
 *
 * WARUM NICHT EINFACH EIN PLATZHALTER IN DER VORLAGE
 * --------------------------------------------------
 * Den gibt es zusaetzlich - ###BRAND### und ###BRAND_MARK### in
 * assets/html/index.html, gefuellt von App\Helper\ViewHelper::output(). Der
 * WERT muss aber trotzdem irgendwo stehen, und "irgendwo" waere sonst mitten
 * in einer 900-Zeilen-Methode.
 */
class Brand
{
    /**
     * Der Produktname.
     *
     * PLATZHALTER. Siehe den Kommentar oben - hier wird er ersetzt, sobald
     * der Name feststeht.
     */
    public const NAME = 'PRODUKTNAME';

    /**
     * Das Zeichen im Kaestchen der Kopfleiste.
     *
     * Ein bis zwei Zeichen. Ueblicherweise der Anfangsbuchstabe des Namens -
     * wenn der Name wechselt, wechselt auch dieses Zeichen.
     */
    public const MARK = 'P';
}
