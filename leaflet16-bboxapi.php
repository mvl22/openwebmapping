<?php

// Get the BBOX from the URL
if (!isSet ($_GET['bbox'])) {return false;}
if (!preg_match ('/^([0-9.]+),([0-9.]+),([0-9.]+),([0-9.]+)$/', $_GET['bbox'], $matches)) {return false;}
// Get the data from the database

// Construct the query and assemble the parameters
$query = '
    SELECT *
    FROM locations
    WHERE
            longitude > :west
        AND latitude  > :south
        AND longitude < :east
        AND latitude  < :north
;';
$preparedStatementValues = array (
    'west'  => $matches[1],
    'south' => $matches[2],
    'east'  => $matches[3],
    'north' => $matches[4],
);

// Get the data
require_once ('database.php');
$database = new database ('localhost', $username = 'leaflet', $password = 'leaflet123', $database = 'leaflet');
$data = $database->getData ($query, false, true, $preparedStatementValues);

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
