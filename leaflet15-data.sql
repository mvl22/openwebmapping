USE leaflet;

CREATE TABLE `locations` (
  `id` int(11) NOT NULL PRIMARY KEY,
  `caption` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `categoryId` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `iconUrl` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `username` varchar(20) COLLATE utf8_unicode_ci NOT NULL,
  `latitude` float NOT NULL,
  `longitude` float NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

INSERT INTO `locations` (`id`, `caption`, `categoryId`, `iconUrl`, `username`, `latitude`, `longitude`) VALUES
(80, 'Cycle parking', 'cycleparking', 'https://www.cyclestreets.net/images/categories/iconsets/cyclestreets/svg/cycleparking_good.svg', 'martin', 52.2017, 0.12111),
(2772, 'Nice bike', 'general', 'https://www.cyclestreets.net/images/categories/iconsets/cyclestreets/svg/general_neutral.svg', 'simon', 52.202, 0.12466),
(2818, 'This cycle parking needs to be improved', 'cycleparking', 'https://www.cyclestreets.net/images/categories/iconsets/cyclestreets/svg/cycleparking_bad.svg', 'martin', 52.2006, 0.12136);
