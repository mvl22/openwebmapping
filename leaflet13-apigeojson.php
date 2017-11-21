<?php

// Define a list of points
$data = array (

    array (
        'id' => 80,
        'caption' => 'Cycle parking',
        'categoryId' => 'cycleparking',
        'iconUrl' => "https://www.cyclestreets.net/images/categories/iconsets/cyclestreets/svg/cycleparking_good.svg",
        'username' => 'martin',
        'latitude' => 52.20173,
        'longitude' => 0.12111,
    ),

    array (
        'id' => 2772,
        'caption' => 'Nice bike',
        'categoryId' => 'general',
        'iconUrl' => "https://www.cyclestreets.net/images/categories/iconsets/cyclestreets/svg/general_neutral.svg",
        'username' => 'simon',
        'latitude' => 52.20203,
        'longitude' => 0.12466,
    ),

    array (
        'id' => 2818,
        'caption' => 'This cycle parking needs to be improved',
        'categoryId' => 'cycleparking',
        'iconUrl' => "https://www.cyclestreets.net/images/categories/iconsets/cyclestreets/svg/cycleparking_bad.svg",
        'username' => 'martin',
        'latitude' => 52.20055,
        'longitude' => 0.12136,
    ),

);

/*

{
  "type": "FeatureCollection",
  "features": [
    {
      "type": "Feature",
      "properties": {},
      "geometry": {
        "type": "Point",
        "coordinates": [
          0.12484,
          52.202
        ]
      }
    },
    {
      "type": "Feature",

*/

// Assemble the features
$features = array ();
foreach ($data as $record) {
    $features[] = array (
        "type" => "Point",
        "properties" => array (
            'id'           => $record['id'],
            'caption'      => $record['caption'],
            'categoryId'   => $record['categoryId'],
            'categoryName' => $record['categoryId'],
            'iconUrl'      => $record['iconUrl'],
            'username'     => $record['username'],
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
