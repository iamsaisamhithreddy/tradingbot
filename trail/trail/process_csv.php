<?php
header('Content-Type: application/json');
date_default_timezone_set('Asia/Kolkata');

$csv_file_path = "";
if (isset($_POST['server_file'])) {
    $relative_path = $_POST['server_file'];
    $csv_file_path = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($relative_path, '/');
    if (!file_exists($csv_file_path)) $csv_file_path = $relative_path;
}

if (empty($csv_file_path) || !file_exists($csv_file_path)) die(json_encode(['error' => 'File not found.']));

$candlestickData = [];
$volumeData = [];
$patternAlerts = [];
$is_header = true;

if (($handle = fopen($csv_file_path, "r")) !== FALSE) {
    while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
        if ($is_header) { $is_header = false; continue; }
        if (count($row) < 7) continue;

        // SCIENTIFIC NOTATION FIX
        $time = (int)floatval($row[0]); 
        $open = (float)$row[1];
        $close = (float)$row[4];

        $candlestickData[] = ['time' => $time, 'open' => $open, 'high' => (float)$row[2], 'low' => (float)$row[3], 'close' => $close];
        $volumeData[] = ['time' => $time, 'value' => (float)$row[6], 'color' => ($close > $open) ? 'rgba(0,150,136,0.5)' : 'rgba(255,82,82,0.5)'];

        if (!empty($row[5]) && $row[5] !== '0') {
            $patternAlerts[] = ['time' => $time, 'position' => 'aboveBar', 'color' => '#2196F3', 'shape' => 'arrowDown', 'text' => 'Signal'];
        }
    }
    fclose($handle);
    usort($candlestickData, function($a, $b) { return $a['time'] <=> $b['time']; });
    echo json_encode(['candlesticks' => $candlestickData, 'volume' => $volumeData, 'alerts' => $patternAlerts]);
}
?>