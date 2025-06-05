<?php
require_once '../class/PdoConnect.php'; // anpassen, falls dein DB-Connect woanders liegt

new PdoConnect();

$timeout = 20; // Sekunden

// Setze offline, wenn User zu lange inaktiv war
$sql_offline = "UPDATE user SET user_status = 'offline' WHERE updated_at < (NOW() - INTERVAL $timeout SECOND)";
PdoConnect::$connection->exec($sql_offline);

//$conn->query($sql_offline);

// Setze online, wenn User kürzlich aktiv war
/*$sql_online = "UPDATE user SET user_status = 'online' WHERE updated_at >= (NOW() - INTERVAL $timeout SECOND)";
PdoConnect::$connection->exec($sql_online);*/

//$conn->query($sql_online);

?>
