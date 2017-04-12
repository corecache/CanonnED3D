 <?php

// Caching
$cache_file = 'gs-json.cache';
if ( !isset($_GET['force']) && file_exists($cache_file) && ( time() - filemtime($cache_file) < 5 * 60 ) ) {
	echo file_get_contents($cache_file);
	return;
}

// SUPPORT: large numbers to human thousand/million
function f_human_number($num, $places = 1) {
    if ($num < 1000) $num_format = number_format($num);
    else if ($num < 1000000) $num_format = number_format($num / 1000, $places) . 'k';
    else $num_format = number_format($num / 1000000, $places) . 'm'; 
    return $num_format;
}

// Connection data 
$sql_server = "dlb.sonargaming.com";
$sql_port = 3307;
$sql_user = "edgs";
$sql_pass = "EbEn.h3#~A4MH*9U";
$db = "edgs";

// Connect to DB
$cn = new mysqli( $sql_server, $sql_user, $sql_pass, $db, $sql_port );
if ($cn->connect_error) die();

// We're going to loop through systems
$systems = array();
$categories_available = array();
if ( $rs_systems = mysqli_query( $cn, 'SELECT * FROM systems' ) ) {

	while ( $row_system = mysqli_fetch_assoc($rs_systems) ) {

		// Let's check if this system comes with a proper set of coords, query EDSM if not, ignore if name doesn't exist in EDSM
		$sys_coords = array( 'x' => NULL, 'y' => NULL, 'z' => NULL );
		if ( is_null($row_system['x']) || is_null($row_system['y']) || is_null($row_system['z']) || isset($_GET['reset']) ) {

			$esdm_coords_json = file_get_contents('https://www.edsm.net/en/api-v1/system?sysName=' . urlencode($row_system['name']) . '&coords=1'); 
			$esdm_coords_data = json_decode($esdm_json, true);

			if ( isset($esdm_coords_data['coords']) && is_array($esdm_coords_data['coords']) ) {
				$sys_coords['x'] = round($esdm_coords_data['coords']['x']);
				$sys_coords['y'] = round($esdm_coords_data['coords']['y']);
				$sys_coords['z'] = round($esdm_coords_data['coords']['z']);
				mysqli_query( $cn, 'UPDATE systems SET x=' . $sys_coords['x'] . ', y=' . $sys_coords['y'] . ', z=' . $sys_coords['z'] . ' WHERE id=' . $row_system['id'] );
			}

		// Seems like we had proper coords
		} else {
			$sys_coords['x'] = $row_system['x'];
			$sys_coords['y'] = $row_system['y'];
			$sys_coords['z'] = $row_system['z'];
		}

		// Let's check if this system has bodies info, dame deal as before
		$sys_bodies = NULL;
		if ( is_null($row_system['bodies']) || isset($_GET['reset']) ) {

			$esdm_bodies_json = file_get_contents('https://www.edsm.net/api-system-v1/bodies?systemName=' . urlencode($row_system['name'])); 
			$esdm_bodies_data = json_decode($esdm_bodies_json, true);
			
			if ( isset($esdm_bodies_data['id']) ) {
				mysqli_query( $cn, "UPDATE systems SET bodies='" . $esdm_bodies_json . "' WHERE id=" . $row_system['id'] );
				$sys_bodies = $esdm_bodies_data;
			}

		// Seems like we had bodies info
		} else
			$sys_bodies = json_decode($row_system['bodies'], true);

		// Skip this systems since we have no valid coords or bodies info for it
		if ( is_null($sys_coords['x']) || is_null($sys_coords['y']) || is_null($sys_coords['z']) || is_null($sys_bodies) ) continue;

		// We're going to loop through each site within this system, then group every site by planet 
		$sys_categories = array();
		$sys_sites = array();
		if ( $rs_sites = mysqli_query( $cn, "SELECT gs.name, gst.id AS type_id, gst.name AS type_base, gst.name AS type_name, gs.planet, gs.poi, gs.lat, gs.long FROM guardian_sites gs LEFT JOIN gs_types_sub gst ON gst.id=gs.fk_type WHERE gs.fk_system=" . $row_system['id'] . " ORDER BY gs.planet ASC, gs.name ASC" ) ) {

			while ( $row_site = mysqli_fetch_assoc($rs_sites) ) {
				
				// Here we check if this is a PoI or a planet, if the latter we check if exist in bodies info
				// Skip site if planet AND no proper response
				if ( !$row_site['poi'] ) {
					$site_body = NULL;
					$site_body_key = array_search($row_system['name'] . ' ' . $row_site['planet'], array_map('strtoupper', array_column($sys_bodies['bodies'], 'name')));
					if ( $site_body_key !== false ) $site_body = $sys_bodies['bodies'][$site_body_key];
					else continue;
				}
				
				$sys_sites[$row_site['planet'] . ($row_site['poi'] ? '' : ' (' . f_human_number($site_body['distanceToArrival'], 2) . ' Ls)')][] = array(
					'name'		=>	$row_site['name'],
					'type'		=>	$row_site['type_name'],
					'coords'	=>	!is_null($row_site['lat']) && !is_null($row_site['long']) ? $row_site['lat'] . 'º, ' . $row_site['long'] . 'º' : ''
				);
				
				if ( !in_array($row_site['type_id'], $categories_available) ) $categories_available[] = $row_site['type_id'];
				if ( !in_array($row_site['type_id'], $sys_categories) ) (int) $sys_categories[] = (int) $row_site['type_id'];
				
			}
			
			$rs_sites->close();
			
		}
			
		// Let's compose out HTML if we have site data, starting with a "system info" header
		$sys_infos = '';
		if ( !empty($sys_sites) ) {
		
			$main_star = $sys_bodies['bodies'][0];
			$sys_infos .= '<div class="system_info"><h2>' . $main_star['subType'] . '</h2><h3 class="extra">' . ($main_star['isScoopable'] ? '<span class="text_green">Scoopable</span>' : '<span class="text_orange">Not Scoopable</span>') . '</h3></div>';
		
			foreach($sys_sites as $site_planet => $sites_in_planet) {
				$sys_infos .= '<div class="site"><h2 class="site_location">' . $site_planet . '</h2><ul class="site_list">';
				foreach ( $sites_in_planet as $site ) $sys_infos .= '<li class="site"><strong class="site_name">' . $site['name'] . '</strong> <span class="site_type">' . $site['type'] . '</span> ' . (!empty($site['coords']) ? '<em class="site_coords">(' . $site['coords'] .')</em>' : '') . '</li>';
				$sys_infos .= '</ul></div><br/>';
			}
		
		}
		
		// If we have HTML that means everything went alright, let's add this star system to the star system list
		if ( !empty($sys_infos) )
			$systems[] = array(
				'name' 		=> 	$row_system['name'],
				'coords' 	=> 	array(
									'x'	=> $sys_coords['x'],
									'y'	=> $sys_coords['y'],
									'z'	=> $sys_coords['z'],
								),
				'cat'		=>	$sys_categories,
				'infos'		=>	$sys_infos
			);
	
	}	
	
	$rs_systems->close();

}

// Let's get all types/layouts now
// This query only returns types/layouts we've already went through in the previous step
$category_list = array();
if ( $rs_types = mysqli_query( $cn, 'SELECT DISTINCT t.name AS group_name, s.id AS cat_id, s.name AS cat_name, s.color AS cat_color FROM gs_types_sub s JOIN gs_types_base t ON t.id=s.fk_type_base WHERE s.id IN (' . implode(',', $categories_available) . ')' ) ) {

	while ( $row_type = mysqli_fetch_assoc($rs_types) ) {

		$category_list[$row_type['group_name']][$row_type['cat_id']] = array(
			'name'		=>	$row_type['cat_name'],
			'color'		=>	$row_type['cat_color']
		);
	
	}
	
	$rs_types->close();

}

// Let's compose the final array and let's go out (also, save contents for caching)
$final_data = array(
	'categories'	=>	$category_list,
	'systems'		=>	$systems
);
$final_json = json_encode($final_data);

file_put_contents($cache_file, $final_json);
echo $final_json;

$cn->close();

?> 