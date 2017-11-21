<?php

// Get the data from the database
require_once ('database.php');
$database = new database ('localhost', $username = 'leaflet', $password = 'leaflet123', $database = 'leaflet');
$data = $database->getData ("SELECT * FROM locations;");

// Assemble the features
$features = array ();
foreach ($data as $record) {
    $features[] = array (
        "type" => "Point",
        "properties" => array (
            'id' => $record['id'],
            'caption' => $record['caption'],
            'categoryId' => $record['categoryId'],
            'iconUrl' => $record['iconUrl'],
            'username' => $record['username'],
        ),
        "coordinates" => array ($record['longitude'], $record['latitude']),
    );
}

// Assemble the GeoJSON
$geojson = array (
    "type" => "FeatureCollection",
    "features" => $features,
);

// Serve as GeoJSON
header('Content-Type: application/json');
echo json_encode ($geojson, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);

?>
