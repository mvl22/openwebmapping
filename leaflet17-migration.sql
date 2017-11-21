
ALTER TABLE `locations` ADD `lonLat` POINT NULL COMMENT 'Geometry' AFTER `longitude`;
UPDATE locations SET lonLat = POINT(longitude, latitude);
