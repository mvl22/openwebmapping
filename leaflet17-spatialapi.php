<?php

// Get the BBOX from the URL
if (!isSet ($_GET['bbox'])) {return false;}
if (!preg_match ('/^([0-9.]+),([0-9.]+),([0-9.]+),([0-9.]+)$/', $_GET['bbox'], $matches)) {return false;}
// Get the data from the database

// Construct the query and assemble the parameters, using a spatial query
$query = '
    SELECT *
    FROM locations
    WHERE MBRCONTAINS(ST_LINESTRINGFROMTEXT(:linestring), lonLat)
;';
$preparedStatementValues = array (
    'linestring'  => "LINESTRING({$matches[1]} {$matches[2]}, {$matches[3]} {$matches[4]})",   // LINESTRING(W S, E N)
);

// Get the data
require_once ('database.php');
$database = new database ('localhost', $username = 'leaflet', $password = 'leaflet123', $database = 'leaflet');
$data = $database->getData ($query, false, true, $preparedStatementValues);
//var_dump ($database->error ());

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
