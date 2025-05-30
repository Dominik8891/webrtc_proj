<?php

################################################  ADMIN BEREICH #####################################################

/**
 * Gibt den HTML-Inhalt der Admin-Seite aus und beendet die PHP-Ausführung.
 *
 * @param string $in_content Der Inhalt, der in die Seite eingefügt werden soll.
 */
function output($in_content)
{
	// Laden der Grundstruktur der Seite aus einer HTML-Datei
	$out = file_get_contents("assets/html/index.html"); 
	
	// Ersetzt den Platzhalter ###CONTENT### durch den aktuellen Inhalt
	$out = str_replace("###CONTENT###", $in_content, $out);

	// Standardmäßig wird der "Sign Up"-Link angezeigt
	$sign = "<a href='index.php?act=signup_page'>Sign Up</a>";
	$user_txt = "";

	// Standardmäßig wird der "Login"-Link angezeigt
	$text = "<a href='index.php?act=login_page'>Login</a>";

	$logged_in = 'false';
	$user_role = null;
	// Wenn der Benutzer angemeldet ist und eine Rolle größer als 2 hat (z.B. Admin oder Moderator)
	if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']))
	{	
		// Benutzerinformationen aus der Datenbank laden
		$user = new User($_SESSION['user_id']);
		
		// Überschreibt den Login-Text mit einem Logout-Text und zeigt den Benutzernamen an
		$user_txt  = "| <span> Sie sind angemeldet als: <b>". $user->get_username() ."</b> </span>";		
		$text = " <a href='index.php?act=logout'>Logout</a>";
		$sign = "<a href='index.php?act=list_user'>Benutzerliste</a>"; // Link zur Benutzerliste für Administratoren

		$logged_in = 'true';
		$user_role = $user->get_usertype();
	}

	$logged_in_script = '<script> window.isLoggedIn = ' . $logged_in . '</script>';
	$user_role_script = '<script> window.userRole = "'   . $user_role . '"</script>' . $logged_in_script;
	$out = str_replace("###USERSTATUS###", $user_role_script, $out);
	
	// Ersetzt die Platzhalter ###LOGOUT###, ###USER### und ###REGISTER### mit den entsprechenden Werten
	$out = str_replace("###LOGOUT###", $text, $out);
	$out = str_replace("###USER###", $user_txt, $out);
	$out = str_replace("###REGISTER###", $sign, $out);
		
    // Gibt das finale HTML aus und beendet die PHP-Ausführung
	die($out); 
}

/**
 * Gibt eine Admin-Seite aus, optional mit einer benutzerdefinierten Nachricht.
 *
 * @param string $in_msg Die Nachricht, die im Admin-Panel angezeigt werden soll.
 */
function act_admin($in_msg = "Willkommen im Admin Panel")
{
    output($in_msg); // Gibt die Admin-Seite aus
}

################################################  ADMIN BEREICH #####################################################

/**
 * Holt einen Wert aus dem $_REQUEST-Array, basierend auf einem übergebenen Index.
 *
 * @param string $assoc_index Der Schlüssel im $_REQUEST-Array.
 * @return mixed|null Der Wert des Schlüssels oder null, wenn er nicht existiert.
 */
function g($assoc_index)
{
	if (!isset($_REQUEST[$assoc_index]))
	{
		return null; // Gibt null zurück, wenn der Index nicht existiert
	}
	
	return $_REQUEST[$assoc_index]; // Gibt den Wert des $_REQUEST-Schlüssels zurück
}

/**
 * Generiert ein HTML-Dropdown-Menü (Select-Optionen) basierend auf einem Array.
 *
 * @param array $in_data_array Array von Werten für die Optionen.
 * @param mixed $in_selected_id Die ID des aktuell ausgewählten Elements.
 * @param bool $in_add_empty Ob eine leere Option hinzugefügt werden soll.
 * @return string Das generierte HTML für die Optionen.
 */
function gen_html_options($in_data_array, $in_selected_id, $in_add_empty)
{	
	$out_opt = "";

	// Fügt eine leere Option hinzu, wenn $in_add_empty auf true gesetzt ist
	if ($in_add_empty == true)
	{
		$out_opt .= '<option value=0>  -- KEINE --  </option>';
	}	
	
	// Generiert HTML für jede Option im Array
	foreach ($in_data_array as $key => $val)
	{		
		$sel = "";
		
		// Markiert die Option als ausgewählt, wenn die ID übereinstimmt
		if ($key == $in_selected_id)
			$sel = "selected";
		
		// Erzeugt das HTML für jede Option
		$out_opt .= '<option value="'. $key .'"  '. $sel .' > '. $val .' </option>';
	}	
	
	return $out_opt; // Gibt das HTML der Optionen zurück
}

################################################  USER BEREICH  #####################################################  

/**
 * Gibt den HTML-Inhalt der Benutzeroberfläche aus und beendet die PHP-Ausführung.
 *
 * @param string $in_content Der Inhalt, der in die Seite eingefügt werden soll.
 */
function output_fe($in_content)
{
	// Lädt die Grundstruktur der Benutzeroberfläche aus einer HTML-Datei
	$html = file_get_contents("assets/html/frontend/index.html");
	$logout = "";
	$out = $in_content;

	// Wenn der Benutzer eingeloggt ist
	if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']))
	{
		// Lädt das Logout-Template und ersetzt den Platzhalter mit dem Benutzernamen
		$logout = file_get_contents("assets/html/frontend/logout.html");
		$user = new User($_SESSION['user_id']);
		$logout = str_replace("###USERNAME###", $user->get_username(), $logout);
	}

	// Ersetzt den ###LOGOUT###-Platzhalter im HTML und den ###CONTENT###-Platzhalter
	$logout = str_replace("###LOGOUT###", $logout, $html);
	$out = str_replace("###CONTENT###", $out, $logout);
		
	// Gibt das finale HTML aus und beendet die PHP-Ausführung
	die($out); 
}

/**
 * Zeigt die Startseite oder das Chatfenster, wenn der Benutzer eingeloggt ist.
 */
function home()
{
    output(''); // Ruft die Startseite oder das Chatfenster auf
}

/**
 * Zeigt die Startseite oder das Chatfenster, abhängig vom Benutzerstatus.
 */
function act_start()
{
    // Lädt die Home-Seite, wenn der Benutzer nicht eingeloggt ist
	$html = file_get_contents("assets/html/frontend/home.html");
	
	// Wenn der Benutzer eingeloggt ist, wird das Chat-Interface geladen
	if (isset($_SESSION['user_id']))
	{
		$html = file_get_contents("assets/html/frontend/goto_chat.html");
	}
    
    output_fe($html); // Gibt die Seite mit dem entsprechenden Inhalt aus
}
