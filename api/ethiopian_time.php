<?php
/**
 * JSON: current Ethiopia (Addis Ababa) civil time + Ethiopic date string (server clock).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ethiopian_datetime.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$info = qb_ethiopian_now_server();
$nav = qb_navbar_ethiopian_clock_strings();
echo json_encode([
    'utc_ms'    => $info['utc_ms'],
    'iso_addis' => $info['iso_addis'],
    'date_en'   => $info['date_en'],
    'time_hms'  => $info['time_hms'],
    'day_am'    => $nav['day_am'],
    'time_hm'   => $nav['time_hm'],
], JSON_UNESCAPED_UNICODE);
