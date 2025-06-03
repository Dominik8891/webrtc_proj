<?php

function act_set_location_page() {
    if (!empty($_SESSION['user_id'])) {
        $out = file_get_contents('assets/html/set_location.html');
        output($out);
    } else {
        home();
    }
}

function act_set_location() {
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $country_id  = g('country');
        $city        = g('city');
        $longitude   = g('longitude');
        $latitude    = g('latitude');
        $description = g('description');
        $user_id     = $_SESSION['user_id'];

        // Dein Model-Konstruktor braucht User-ID
        $user = new User($user_id);
        if ($user->get_usertype() === 'tourist') {
            $user->set_usertype('guide');
            $user->save();
        }

        // Location-Modell ggf. auch ohne Parameter
        $location = new Location();
        $location->set_country($country_id);
        $location->set_city($city);
        $location->set_longitude($longitude);
        $location->set_latitude($latitude);
        $location->set_description($description);
        $location->set_new_location($user_id, $country_id);

        header("Location: index.php?success=1");
        exit;
    }
}

function act_get_country() {
    $location = new Location();
    $data = $location->select_all_countries();
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

function act_get_locations() {
    $location = new Location();
    $data = $location->select_all_locations($_SESSION['user_id']);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

function act_show_locations_page() {
    if (!empty($_SESSION['user_id'])) {
        $out = file_get_contents('assets/html/locations_table.html');
        output($out);
    } else {
        home();
    }
}
