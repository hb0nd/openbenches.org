<?php
// src/Twig/AppExtension.php
namespace App\Twig;

use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

class AppExtension extends AbstractExtension
{	
	public function getFunctions()
	{
		return [
			new TwigFunction('number_of_benches', [$this, 'get_number_of_benches']),
			new TwigFunction('number_of_benches_raw', [$this, 'get_number_of_benches_raw']),
			new TwigFunction('cached_date',       [$this, 'get_date']),
			new TwigFunction('map_javascript',    [$this, 'map_javascript']),
			new TwigFunction('media_types',       [$this, 'get_media_types']),
		];
	}

	public function get_number_of_benches() {

		$cache = new FilesystemAdapter($_ENV["CACHE"] . "count_cache");

		$value = $cache->get('number_of_benches', function (ItemInterface $item) {
			$item->expiresAfter(300);
			$dsnParser = new DsnParser();
			$connectionParams = $dsnParser->parse( $_ENV['DATABASE_URL'] );

			$conn = DriverManager::getConnection($connectionParams);

			$count = $conn->fetchOne("SELECT COUNT(*) FROM `benches` WHERE `published` = true");

			return $count;
		});

		return number_format($value);
	}

	public function get_number_of_benches_raw() {

		$cache = new FilesystemAdapter($_ENV["CACHE"] . "count_raw_cache");

		$value = $cache->get('number_of_benches_raw', function (ItemInterface $item) {
			$item->expiresAfter(300);
			$dsnParser = new DsnParser();
			$connectionParams = $dsnParser->parse( $_ENV['DATABASE_URL'] );

			$conn = DriverManager::getConnection($connectionParams);

			$count = $conn->fetchOne("SELECT COUNT(*) FROM `benches` WHERE `published` = true");

			return $count;
		});

		return $value;
	}

	public function get_media_types() {
		
		$cache = new FilesystemAdapter($_ENV["CACHE"] . "cache_media_types" );
		$cache_name = "media_types";
		$cachedResult = $cache->get( $cache_name, function (ItemInterface $item) { 
			//	Cache length in seconds
			$item->expiresAfter(600);

			$dsnParser = new DsnParser();
			$connectionParams = $dsnParser->parse( $_ENV['DATABASE_URL'] );
			$conn = DriverManager::getConnection($connectionParams);
			$queryBuilder = $conn->createQueryBuilder();

			$queryBuilder
				->select("shortName", "longName")
				->from("media_types")
				->orderBy("displayOrder", 'ASC');
			$results = $queryBuilder->executeQuery();
			
			$media_types = array();
			while ( ( $row = $results->fetchAssociative() ) !== false) {
				//	Add the details to the array
				$media_types[] = array(
					"shortName" => $row["shortName"],
					"longName"  => $row["longName"],
				);
			}
			return $media_types;

		});

		return $cachedResult;
	}

	public function get_date() {
		$cache = new FilesystemAdapter("date_cache");
		$value = $cache->get('cached_date', function (ItemInterface $item) {
			$item->expiresAfter(5);
			$date = date("Y-m-d H:i:s");

			return $date;
		});

		return $value;
	}

	public function map_javascript( 
			$api ="", 
			$api_query ="", 
			$lat  = "16.3", 
			$long =    "0",
			$zoom = "2", 
			$draggable = false, 
			$bb_n = null, 
			$bb_e = null, 
			$bb_s = null, 
			$bb_w = null,  ) {
		$esri_api     = $_ENV['ESRI_API_KEY'];
		$thunder_api  = $_ENV['THUNDERFOREST_API_KEY'];
		$api_url = $api . $api_query;
		if ( null == $lat ) {
			$lat  = "16.3";
			$long = "0";
			$zoom = "2";
		}
		$mapJavaScript = <<<EOT
<script>

	var api_url = '$api_url';

	// Set up tile layers
	var Stadia_Outdoors = L.tileLayer('https://tiles-eu.stadiamaps.com/tiles/outdoors/{z}/{x}/{y}{r}.png', {
		minZoom: 2,
		maxNativeZoom: 19,
		maxZoom: 22,
		attribution: 'Map data © <a href="https://stadiamaps.com/">Stadia Maps</a>, © <a href="https://openmaptiles.org/">OpenMapTiles</a> © <a href="http://openstreetmap.org">OpenStreetMap</a> contributors',
		id: 'stadia.outdoors'
	});

	var OpenStreetMap_Mapnik = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
		minZoom: 2,
		maxNativeZoom: 19,
		maxZoom: 22,
		attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
		id: 'osm.mapnik'
	});

	var ESRI_Satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}.jpeg?token=$esri_api', {
		minZoom: 2,
		maxNativeZoom: 19,
		maxZoom: 22,
		attribution: '© <a href="https://www.esri.com/">i-cubed, USDA, USGS, AEX, GeoEye, Getmapping, Aerogrid, IGN, IGP, UPR-EGP, and the GIS User Community</a>',
		id: 'esri.satellite'
	});

	var Thunderforest = L.tileLayer('https://tile.thunderforest.com/outdoors/{z}/{x}/{y}.png?apikey=$thunder_api', {
		minZoom: 2,
		maxNativeZoom: 19,
		maxZoom: 22,
		attribution: '© <a href="https://www.thunderforest.com/">Thunderforest</a>',
		id: 'thunderforest'
	});

	//	Settings for map		
	var map = L.map('map', {
		sleepNote: false, 
		sleepOpacity: 1,
		minZoom: 2,
		maxZoom: 19,
		worldCopyJump: true
	})

	//	Placeholder for last (or only) marker
	var marker;
	
	//	Load benches from API
	async function load_benches() {
		if (api_url == '') {
			//	No search set - use TSV
			let url = '/api/benches.tsv';
			const response = await fetch(url)
			var benches_text = await response.text();
			var rows = benches_text.split(/\\n/);
			var benches_json = {'features':[]};
			for(let i = 1; i < rows.length; i++){
				let cols = rows[i].split(/\\t/);
				benches_json.features.push({'id':cols[0],'type':'Feature','properties':{'popupContent':cols[3]},'geometry':{'type':'Point','coordinates':[cols[1],cols[2]]}});
			}
			return benches_json;
		} else {
			let url = '$api_url';
			const response = await fetch(url)
			var benches_json = await response.json();
			return benches_json;
		}
	}

	async function main() {
		var benches = await load_benches();

		//	Set up clustering
		var markers = L.markerClusterGroup({
			maxClusterRadius: 29,
			disableClusteringAtZoom: 17
		});

		markers.on('click', function (bench) {
			//	Placeholder. Used to display images
		});

		//	Add pop-up to markers - if this isn't a single bench on display / edit
		for (var i = 0; i < benches.features.length; i++) {
			var bench = benches.features[i];
			var lat = bench.geometry.coordinates[1];
			var longt = bench.geometry.coordinates[0];
			var benchID = bench.id;
			var title = bench.properties.popupContent + "<br><a href='/bench/"+bench.id+"/'>View details</a>";
			if(typeof lat !== "undefined" && typeof longt !== "undefined"){
				//	Check for strange values in TSV
				marker = L.marker(new L.LatLng(lat, longt), {  benchID: benchID, draggable: "$draggable" });
				if ( "$api" != "/api/bench/" ) {
					marker.bindPopup(title);
				}
				markers.addLayer(marker);	
			}
		}

		//	Add the clusters to the map
		map.addLayer(markers);
		
		//	Add the tiles layers
		var baseMaps = {
			"Map View": Stadia_Outdoors,
			"Open Street Map": OpenStreetMap_Mapnik,
			"Satellite View": ESRI_Satellite,
			"Outdoors Map": Thunderforest
		};
	
		// Rotate between mapping providers depending on date
		switch (new Date().getDay()) {
			case 0:
				//	Sunday
				// Stadia_Outdoors.addTo(map);
				OpenStreetMap_Mapnik.addTo(map);
				break;
			case 1:
				OpenStreetMap_Mapnik.addTo(map);
				break;
			case 2:
				Thunderforest.addTo(map);
				break;
			case 3:
				// Stadia_Outdoors.addTo(map);
				OpenStreetMap_Mapnik.addTo(map);
				break;
			case 4:
				OpenStreetMap_Mapnik.addTo(map);
				break;
			case 5:
				Thunderforest.addTo(map);
				break;
			case 6:
				//	Saturday
				// Stadia_Outdoors.addTo(map);
				OpenStreetMap_Mapnik.addTo(map);
		 }
	
		L.control.layers(baseMaps, null, {collapsed:false}).addTo(map);
	
		//	Cluster options
		var markers = L.markerClusterGroup({
			maxClusterRadius: 29,
			disableClusteringAtZoom: 17
		});

		//	View of the map
		map.setView([{$lat}, {$long}], {$zoom});

		//	Snap to bounding box if any
		map.fitBounds([ [{$bb_n},{$bb_e}], [{$bb_s}, {$bb_w}] ]);
	}
	
	//	Change URl bar to show current location (frontpage only)
	map.on("load", function () {
		if (window.location.hash != "") {
			if(window.location.hash.indexOf("/") > -1)
			{
				var hashArray = window.location.hash.substr(1).split("/");
				if(hashArray.length >= 2)
				{
					var hashLat = hashArray[0];
					var hashLng = hashArray[1];
					var hashZoom = 16; if(hashArray[2] != void 0){hashZoom = hashArray[2];}
					map.setView([hashLat, hashLng], hashZoom);
				}
			}
		}
	});

	main();
</script>
EOT;
	
		echo $mapJavaScript;
	}
}