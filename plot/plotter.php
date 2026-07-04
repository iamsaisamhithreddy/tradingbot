
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Pulls db.php from the root of your website
require_once $_SERVER['DOCUMENT_ROOT'] . '/db.php'; 
?>

// Parse CLI/CGI arguments first so they are available before checking $isBroadcastCron
if (php_sapi_name() === 'cli' || php_sapi_name() === 'cgi-fcgi' || php_sapi_name() === 'cgi' || !isset($_SERVER['REQUEST_METHOD']) || defined('STDIN')) {
    global $argv;
    if (isset($argv) && is_array($argv)) {
        foreach ($argv as $arg) {
            if (strpos($arg, '=') !== false) {
                [$key, $value] = explode('=', $arg, 2);
                $key = trim($key, "\"' ");
                $value = trim($value, "\"' ");
                $_GET[$key] = $value;
                $_REQUEST[$key] = $value;
            }
        }
    }
}

$isCli = (php_sapi_name() === 'cli' || php_sapi_name() === 'cgi-fcgi' || php_sapi_name() === 'cgi' || !isset($_SERVER['REQUEST_METHOD']) || defined('STDIN'));
$isBroadcastCron = ($isCli || isset($_GET['run_broadcast']) || isset($_REQUEST['run_broadcast']));

if (!$isBroadcastCron && session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}


// Limit execution time to 120 seconds for memory-intensive scans
set_time_limit(120);

// Disable error display for AJAX requests to prevent warning/notice HTML pollution
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    ini_set('display_errors', 0);
    error_reporting(0);
}

// ==========================================
// JSON DATABASE HELPER FUNCTIONS
// ==========================================
$jsonDbPath = __DIR__ . '/outcomes_db.json';

function getJsonDb() {
    global $jsonDbPath;
    if (!file_exists($jsonDbPath)) {
        return [];
    }
    $data = file_get_contents($jsonDbPath);
    return json_decode($data, true) ?: [];
}

function saveJsonDb($data) {
    global $jsonDbPath;
    // Open file for read/write. 'c' creates it if it doesn't exist.
    $fp = fopen($jsonDbPath, 'c'); 
    if ($fp && flock($fp, LOCK_EX)) { // Acquire an exclusive lock
        ftruncate($fp, 0); // Clear the file content
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
        fflush($fp); // Flush output before releasing the lock
        flock($fp, LOCK_UN); // Release the lock
    }
    if ($fp) {
        fclose($fp);
    }
}

// ==========================================
// DYNAMIC DATABASE CONFIGURATION LOADER
// ==========================================
$dbPath = '';
if (file_exists(__DIR__ . '/db.php')) {
    $dbPath = __DIR__ . '/db.php';
} elseif (file_exists(__DIR__ . '/../db.php')) {
    $dbPath = __DIR__ . '/../db.php';
} else {
    // Search up to 3 levels up
    $dir = __DIR__;
    for ($i = 0; $i < 3; $i++) {
        $dir = dirname($dir);
        if (file_exists($dir . '/db.php')) {
            $dbPath = $dir . '/db.php';
            break;
        }
    }
}

if ($dbPath) {
    require_once $dbPath;
} else {
    if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Database configuration file db.php not found.']);
        exit;
    } else {
        die("Error: db.php not found on server.");
    }
}

// Validate database connection
if (!isset($conn) || (isset($conn) && $conn->connect_error)) {
    if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Database connection failed: ' . ($conn->connect_error ?? 'Connection object not set')]);
        exit;
    } else {
        die("Database connection failed: " . ($conn->connect_error ?? 'Connection object not set'));
    }
}

// Bootstrap (Create table if not exists) to prevent join errors
$createTableQuery = "
CREATE TABLE IF NOT EXISTS trade_outcome_details (
  raw_trade_id INT(11) NOT NULL,
  pair_name VARCHAR(20) DEFAULT NULL,
  trade_result VARCHAR(20) DEFAULT NULL,
  win_loss_time DATETIME DEFAULT NULL,
  win_loss_price DECIMAL(10,5) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (raw_trade_id)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
";
$conn->query($createTableQuery);

// Timezones
$istTimezone = new DateTimeZone('Asia/Kolkata');
$utcTimezone = new DateTimeZone('UTC');

// ==========================================
// GD CHART RENDERER ENGINE
// ==========================================
function generateTradeChartGD($tradeId, $savePath = null) {
    global $conn, $istTimezone, $utcTimezone;
    
    // 1. Fetch trade info
    $query = "SELECT p.*, o.trade_result, o.win_loss_time, o.win_loss_price, 
                     UNIX_TIMESTAMP(r.created_at) AS trigger_unixtime
              FROM prediction_trade_data p 
              LEFT JOIN trade_outcome_details o ON p.raw_trade_id = o.raw_trade_id
              INNER JOIN raw_trade_data r ON p.raw_trade_id = r.id
              WHERE p.raw_trade_id = " . (int)$tradeId . " LIMIT 1";
    $res = $conn->query($query);
    $tradeRes = $res ? $res->fetch_assoc() : null;
    if (!$tradeRes) return false;
    
    $alertTimeUTC = $tradeRes['last_alert_time'] ?? '';
    if (empty($alertTimeUTC) || $alertTimeUTC === '0000-00-00 00:00:00') {
        return false;
    }
    
    $alertTimestamp = isset($tradeRes['trigger_unixtime']) ? (int)$tradeRes['trigger_unixtime'] : strtotime($alertTimeUTC);
    if (!$alertTimestamp) return false;
    
    $csvPath = getCsvPath($tradeRes['pair_name'], $alertTimeUTC);
    if (!file_exists($csvPath)) return false;
    
    $candlestickData = [];
    $volumeData = [];
    $patternAlerts = [];
    
    $windowStart = $alertTimestamp - 14400; // 4 hours before
    $windowEnd   = $alertTimestamp + 28800; // 8 hours after
    
    $last_day = null;
    $is_header = true;
    
    if (($handle = fopen($csvPath, "r")) !== FALSE) {
        while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if ($is_header) {
                $is_header = false;
                continue;
            }
            if (count($row) < 7) continue;
            
            $time = (int)floatval($row[0]);
            if ($time < $windowStart) continue;
            if ($time > $windowEnd) break;
            
            $open = (float)$row[1];
            $high = (float)$row[2];
            $low = (float)$row[3];
            $close = (float)$row[4];
            $pattern_alert = trim($row[5]);
            $volume = (float)$row[6];
            
            $current_day = date('Y-m-d', $time);
            if ($last_day === null) {
                $last_day = $current_day;
            }
            if ($current_day != $last_day) {
                $patternAlerts[] = [
                    'time' => $time,
                    'type' => 'new_day'
                ];
                $last_day = $current_day;
            }
            
            $candlestickData[] = [
                'time' => $time,
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close
            ];
            
            $volumeData[] = [
                'time' => $time,
                'value' => $volume,
                'is_green' => ($close > $open)
            ];
            
            if (!empty($pattern_alert) && $pattern_alert !== '0') {
                $patternAlerts[] = [
                    'time' => $time,
                    'type' => 'custom',
                    'text' => $pattern_alert
                ];
            }
        }
        fclose($handle);
    }
    
    // Alert entry marker
    $patternAlerts[] = [
        'time' => $alertTimestamp,
        'type' => 'alert_entry',
        'text' => 'ALERT ENTRY'
    ];
    
    // Win/Loss Outcome marker
    $winLossTimestamp = null;
    if (!empty($tradeRes['win_loss_time'])) {
        try {
            $winLossDT = new DateTime($tradeRes['win_loss_time'], $istTimezone);
            $winLossTimestamp = $winLossDT->getTimestamp();
        } catch (Exception $e) {}
    }
    
    if ($winLossTimestamp !== null) {
        $outcomeVal = strtolower($tradeRes['trade_result'] ?? '');
        if ($outcomeVal === 'win') {
            $patternAlerts[] = [
                'time' => $winLossTimestamp,
                'type' => 'win_outcome',
                'text' => 'WIN OUTCOME'
            ];
        } elseif ($outcomeVal === 'loss') {
            $patternAlerts[] = [
                'time' => $winLossTimestamp,
                'type' => 'loss_outcome',
                'text' => 'LOSS OUTCOME'
            ];
        }
    }
    
    // Sort alerts by time
    usort($patternAlerts, function($a, $b) {
        return $a['time'] <=> $b['time'];
    });
    
    // 2. Initialize Image (Aspect ratio 1.8 matching FPDF)
    $imgWidth = 1200;
    $imgHeight = 670;
    
    $im = imagecreatetruecolor($imgWidth, $imgHeight);
    
    // Allocate Colors
    $bgColor = imagecolorallocate($im, 11, 15, 25);         // #0b0f19 primary bg
    $panelColor = imagecolorallocate($im, 11, 18, 31);      // #0b121f chart area bg
    $cardBgColor = imagecolorallocate($im, 19, 27, 46);     // #131b2e card bg
    $cardBorderColor = imagecolorallocate($im, 51, 65, 85); // #334155 card border
    $gridColor = imagecolorallocate($im, 30, 41, 59);       // #1e293b grid lines
    $textColor = imagecolorallocate($im, 148, 163, 184);   // #94a3b8 text-secondary
    $whiteColor = imagecolorallocate($im, 248, 250, 252);   // #f8fafc white
    $greenColor = imagecolorallocate($im, 16, 185, 129);    // #10b981 emerald
    $redColor = imagecolorallocate($im, 239, 68, 68);       // #ef4444 soft red
    $yellowColor = imagecolorallocate($im, 251, 191, 36);   // #fbbf24 amber
    $blueColor = imagecolorallocate($im, 59, 130, 246);     // #3b82f6 blue

    // 4. Draw Chart Area
    $chartX1 = 15;
    $chartY1 = 15; 
    $chartX2 = 1185;
    $chartY2 = 655;

    imagefilledrectangle($im, $chartX1, $chartY1, $chartX2, $chartY2, $panelColor);
    imagerectangle($im, $chartX1, $chartY1, $chartX2, $chartY2, $cardBorderColor);

    $plotX1 = $chartX1 + 10;
    $plotY1 = $chartY1 + 15; 
    $plotX2 = $chartX2 - 70;
    $plotY2 = $chartY2 - 30;

    $plotWidth = $plotX2 - $plotX1;
    $plotHeight = $plotY2 - $plotY1;
    
    if (empty($candlestickData)) {
        imagestring($im, 4, $chartX1 + 100, $chartY1 + 100, "No candlestick data available", $textColor);
        if ($savePath) {
            imagepng($im, $savePath);
        } else {
            imagepng($im);
        }
        imagedestroy($im);
        return true;
    }
    
    $prices = [];
    foreach ($candlestickData as $c) {
        $prices[] = $c['high'];
        $prices[] = $c['low'];
    }
    $prices[] = (float)$tradeRes['price_target'];
    if ($tradeRes['win_loss_price'] !== null) {
        $prices[] = (float)$tradeRes['win_loss_price'];
    }
    
    $maxPrice = max($prices);
    $minPrice = min($prices);
    $priceRange = $maxPrice - $minPrice;
    if ($priceRange == 0) $priceRange = 0.0001;
    
    // Add 10% padding
    $maxPrice += $priceRange * 0.10;
    $minPrice -= $priceRange * 0.10;
    $priceRange = $maxPrice - $minPrice;
    
    $priceToY = function($price) use ($plotY1, $plotY2, $plotHeight, $minPrice, $maxPrice, $priceRange) {
        return $plotY2 - (($price - $minPrice) / $priceRange) * $plotHeight;
    };
    
    // Draw horizontal grid lines and price labels
    $gridCount = 5;
    for ($i = 0; $i <= $gridCount; $i++) {
        $gridPrice = $minPrice + ($priceRange / $gridCount) * $i;
        $gridY = $priceToY($gridPrice);
        imageline($im, $plotX1, $gridY, $plotX2, $gridY, $gridColor);
        
        $priceLabel = number_format($gridPrice, 5);
        imagestring($im, 2, $plotX2 + 5, $gridY - 6, $priceLabel, $textColor);
    }
    
    // X-axis coordinate mapping:
    $numCandles = count($candlestickData);
    $candleWidth = $plotWidth / $numCandles;
    
    // Draw vertical hourly gridlines and label time at bottom
    $labelInterval = max(1, floor($numCandles / 8));
    for ($i = 0; $i < $numCandles; $i++) {
        $c = $candlestickData[$i];
        $cX = $plotX1 + $i * $candleWidth + $candleWidth / 2;
        
        $dt = new DateTime("@" . $c['time']);
        $dt->setTimezone($istTimezone);
        $minute = $dt->format('i');
        if ($minute === '00' || $i % $labelInterval == 0) {
            imageline($im, $cX, $plotY1, $cX, $plotY2, $gridColor);
            $timeStr = $dt->format('H:i');
            imagestring($im, 2, $cX - 15, $plotY2 + 5, $timeStr, $textColor);
        }
    }
    
// Calculate the X coordinate for 3 candles before the Alert Entry candle
    $alertStartX = $plotX1; // Fallback to the left edge just in case
    for ($i = 0; $i < $numCandles; $i++) {
        if ($alertTimestamp >= $candlestickData[$i]['time'] && $alertTimestamp < ($candlestickData[$i]['time'] + 300)) {
            // Subtract 3 candles, clamping at 0 so it doesn't go out of bounds
            $startIndex = max(0, $i - 3);
            $alertStartX = $plotX1 + $startIndex * $candleWidth + $candleWidth / 2;
            break;
        }
    }

    // Draw target horizontal dashed line in red starting 3 candles before the alert
    $targetPriceY = $priceToY((float)$tradeRes['price_target']);
    for ($x = $alertStartX; $x < $plotX2; $x += 10) {
        imageline($im, $x, $targetPriceY, min($x + 5, $plotX2), $targetPriceY, $redColor);
    }
    imagestring($im, 2, $plotX2 - 180, $targetPriceY - 14, "TARGET: " . number_format($tradeRes['price_target'], 5), $redColor);
    
    // Draw candlesticks & Volume
    $maxVol = 0;
    foreach ($volumeData as $v) {
        if ($v['value'] > $maxVol) $maxVol = $v['value'];
    }
    if ($maxVol == 0) $maxVol = 1;
    
    for ($i = 0; $i < $numCandles; $i++) {
        $c = $candlestickData[$i];
        $v = $volumeData[$i];
        
        $cX = $plotX1 + $i * $candleWidth + $candleWidth / 2;
        $w = max(1, floor($candleWidth * 0.7));
        
        $cOpenY = $priceToY($c['open']);
        $cCloseY = $priceToY($c['close']);
        $cHighY = $priceToY($c['high']);
        $cLowY = $priceToY($c['low']);
        
        $cColor = $v['is_green'] ? $greenColor : $redColor;
        imageline($im, $cX, $cHighY, $cX, $cLowY, $cColor);
        
        $topY = min($cOpenY, $cCloseY);
        $bottomY = max($cOpenY, $cCloseY);
        if ($bottomY - $topY < 1) {
            $bottomY = $topY + 1;
        }
        imagefilledrectangle($im, $cX - $w/2, $topY, $cX + $w/2, $bottomY, $cColor);
        
        // Draw Volume bar
        $volHeight = ($v['value'] / $maxVol) * 50;
        $volY1 = $plotY2 - $volHeight;
        $volY2 = $plotY2;
        imagefilledrectangle($im, $cX - $w/2, $volY1, $cX + $w/2, $volY2, $cColor);
    }
    
    // Draw Markers
    foreach ($patternAlerts as $a) {
        $cIndex = -1;
        for ($i = 0; $i < $numCandles; $i++) {
            if ($a['time'] >= $candlestickData[$i]['time'] && $a['time'] < ($candlestickData[$i]['time'] + 300)) {
                $cIndex = $i;
                break;
            }
        }
        if ($cIndex === -1) continue;
        
        $c = $candlestickData[$cIndex];
        $cX = $plotX1 + $cIndex * $candleWidth + $candleWidth / 2;
        
        if ($a['type'] === 'alert_entry') {
            $cLowY = $priceToY($c['low']);
            $arrowY1 = $cLowY + 25;
            $arrowY2 = $cLowY + 10;
            
            imageline($im, $cX, $arrowY1, $cX, $arrowY2, $yellowColor);
            imageline($im, $cX-1, $arrowY1, $cX-1, $arrowY2, $yellowColor);
            imagefilledpolygon($im, [
                $cX, $arrowY2 - 3,
                $cX - 6, $arrowY2 + 3,
                $cX + 6, $arrowY2 + 3
            ], 3, $yellowColor);
            
            imagestring($im, 2, $cX - 35, $arrowY1 + 4, "ALERT ENTRY", $yellowColor);
        }
        elseif ($a['type'] === 'win_outcome') {
            $cHighY = $priceToY($c['high']);
            $arrowY1 = $cHighY - 25;
            $arrowY2 = $cHighY - 10;
            
            imageline($im, $cX, $arrowY1, $cX, $arrowY2, $greenColor);
            imageline($im, $cX-1, $arrowY1, $cX-1, $arrowY2, $greenColor);
            imagefilledpolygon($im, [
                $cX, $arrowY2 + 3,
                $cX - 6, $arrowY2 - 3,
                $cX + 6, $arrowY2 - 3
            ], 3, $greenColor);
            
            imagestring($im, 2, $cX - 35, $arrowY1 - 15, "WIN OUTCOME", $greenColor);
        }
        elseif ($a['type'] === 'loss_outcome') {
            $cHighY = $priceToY($c['high']);
            $arrowY1 = $cHighY - 25;
            $arrowY2 = $cHighY - 10;
            
            imageline($im, $cX, $arrowY1, $cX, $arrowY2, $redColor);
            imageline($im, $cX-1, $arrowY1, $cX-1, $arrowY2, $redColor);
            imagefilledpolygon($im, [
                $cX, $arrowY2 + 3,
                $cX - 6, $arrowY2 - 3,
                $cX + 6, $arrowY2 - 3
            ], 3, $redColor);
            
            imagestring($im, 2, $cX - 40, $arrowY1 - 15, "LOSS OUTCOME", $redColor);
        }
        elseif ($a['type'] === 'custom') {
            $cHighY = $priceToY($c['high']);
            $arrowY1 = $cHighY - 20;
            $arrowY2 = $cHighY - 5;
            
            imageline($im, $cX, $arrowY1, $cX, $arrowY2, $blueColor);
            imagefilledpolygon($im, [
                $cX, $arrowY2 + 2,
                $cX - 4, $arrowY2 - 2,
                $cX + 4, $arrowY2 - 2
            ], 3, $blueColor);
            
            imagestring($im, 2, $cX - 5, $arrowY1 - 12, $a['text'], $blueColor);
        }
        elseif ($a['type'] === 'new_day') {
            for ($y = $plotY1; $y < $plotY2; $y += 10) {
                imageline($im, $cX, $y, $cX, min($y + 5, $plotY2), $blueColor);
            }
            imagestring($im, 1, $cX - 15, $plotY1 + 10, "New Day", $blueColor);
        }
    }
    
    // Save or output
    if ($savePath) {
        imagepng($im, $savePath);
    } else {
        header('Content-Type: image/png');
        imagepng($im);
    }
    
    imagedestroy($im);
    return true;
}

// ==========================================
// CLI CRON BROADCAST RUNNER (FULLY AUTONOMOUS)
// ==========================================
if ($isCli && isset($_GET['run_broadcast']) && $_GET['run_broadcast'] == '1') {
    echo "[" . date('Y-m-d H:i:s') . "] Starting autonomous CLI broadcast...\n";
    
    // Verify GD Library presence to prevent fatal crash
    if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
        echo "ERROR: GD library is not enabled in this PHP environment (SAPI: " . php_sapi_name() . "). Chart generation requires GD.\n";
        exit(1);
    }
    
    // 1. Get IDs
    $tradeIdsRaw = isset($_GET['trade_ids']) ? explode(',', $_GET['trade_ids']) : [];
    $tradeIds = array_filter(array_map('intval', $tradeIdsRaw));
    
    if (empty($tradeIds)) {
        echo "ERROR: No trade IDs provided.\n";
        exit(1);
    }

    echo "Found " . count($tradeIds) . " trade IDs to process: " . implode(', ', $tradeIds) . "\n";

    $tempDir = __DIR__ . '/temp_images';
    if (!is_dir($tempDir)) {
        if (mkdir($tempDir, 0755, true)) {
            echo "Created temp directory: $tempDir\n";
        } else {
            echo "ERROR: Failed to create temp directory: $tempDir\n";
            exit(1);
        }
    }

    // 2. Generate all images (Server-side)
    foreach ($tradeIds as $id) {
        $filePath = $tempDir . '/trade_' . $id . '.png';
        echo "Generating chart image for trade #{$id}... ";
        $res = generateTradeChartGD($id, $filePath);
        if ($res && file_exists($filePath)) {
            echo "SUCCESS: Saved to " . basename($filePath) . "\n";
        } else {
            echo "FAILED to generate chart image.\n";
        }
    }

    // 3. Set Session-like state for the PDF generator
    $GLOBALS['pdf_trade_ids'] = $tradeIds;

    $_GET['generate_pdf'] = '1';
    $_GET['broadcast'] = '1';
    echo "Dispatched parameters to PDF generator and Telegram broadcast.\n";
}

// ==========================================
// 1. ROUTING LOGIC FOR DATASET DIRECTORIES
// ==========================================
function getCsvPath(string $pairName, string $alertTimeStr): string {
    $cleanPair = str_replace(['/', '_', ' '], '', $pairName);
    $fileName = "FX_" . $cleanPair . ".csv";
    
    $timestamp = strtotime($alertTimeStr);
    
    // Cutoff dates (UTC/Epoch)
    $cutoffStart = strtotime('2025-06-01 00:00:00');
    $cutoffEnd = strtotime('2026-03-01 00:00:00'); // Up to Feb 29, 2026 (handled up to Mar 1)
    
    $ffStart = strtotime('2026-05-22 00:00:00');
    
    // Find the dataset base directory dynamically
    $baseDir = __DIR__;
    if (is_dir($baseDir . '/dataset')) {
        $datasetDir = $baseDir . '/dataset';
    } elseif (is_dir($baseDir . '/../dataset')) {
        $datasetDir = $baseDir . '/../dataset';
    } else {
        $datasetDir = '';
        $dir = __DIR__;
        for ($i = 0; $i < 3; $i++) {
            $dir = dirname($dir);
            if (is_dir($dir . '/dataset')) {
                $datasetDir = $dir . '/dataset';
                break;
            }
        }
        if (!$datasetDir) {
            $datasetDir = __DIR__ . '/../dataset'; // Default fallback
        }
    }
    
    // Route based on trade alert timestamp
    $path = '';
    if ($timestamp < $cutoffEnd) {
        $path = $datasetDir . "/JUN-2025 TO FEB-2026/" . $fileName;
    } else {
        $path = $datasetDir . "/dataset/" . $fileName;
    }
    
    if (!empty($path) && file_exists($path)) {
        return $path;
    }
    
    // Fallback search: check directory structures
    $path1 = $datasetDir . "/dataset/" . $fileName;
    $path2 = $datasetDir . "/JUN-2025 TO FEB-2026/" . $fileName;
    $path3 = $datasetDir . "/" . $fileName;
    
    if (file_exists($path1)) return $path1;
    if (file_exists($path2)) return $path2;
    return $path3;
}

// ==========================================
// 2. SAVE CHART SCREENSHOT IMAGE (AJAX POST)
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] == '1' && isset($_GET['save_image'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// ==========================================
// 2b. SAVE PDF TRADE IDS TO SESSION (AJAX POST)
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] == '1' && isset($_GET['set_pdf_ids']) && isset($_POST['trade_ids'])) {
    header('Content-Type: application/json');
    $tradeIdsRaw = explode(',', $_POST['trade_ids']);
    $tradeIds = array_map('intval', $tradeIdsRaw);
    $tradeIds = array_filter($tradeIds);
    
    $_SESSION['pdf_trade_ids'] = $tradeIds;
    echo json_encode(['success' => true]);
    exit;
}

// ==========================================
// 3. GENERATE OUTCOME REPORT PDF (FPDF MULTIPAGE)
// ==========================================
if (isset($_GET['generate_pdf']) && $_GET['generate_pdf'] == '1') {
    if ($isCli) {
        echo "Starting PDF generation process...\n";
    }

    ob_clean();
    ob_start();

    $tradeIds = $GLOBALS['pdf_trade_ids'] ?? $_SESSION['pdf_trade_ids'] ?? [];
    
    if (empty($tradeIds)) {
        if ($isCli) echo "ERROR: No trade IDs selected for PDF generation.\n";
        die("Error: No trade IDs selected for PDF generation. Please select trades in the sidebar first.");
    }
    
    $fpdfPath = '';
    if (file_exists(__DIR__ . '/../fpdf.php')) {
        $fpdfPath = __DIR__ . '/../fpdf.php';
    } elseif (file_exists(__DIR__ . '/fpdf.php')) {
        $fpdfPath = __DIR__ . '/fpdf.php';
    } else {
        if ($isCli) echo "ERROR: FPDF library not found on server.\n";
        die("Error: FPDF library not found on server.");
    }
    if ($isCli) {
        echo "Loading FPDF library from: $fpdfPath\n";
    }
    require_once $fpdfPath;
    
    class TradeReportPDF extends FPDF {
        function Header() {
            // Draw background fill for A4 page (210 x 297 mm)
            $this->SetFillColor(11, 15, 25); // #0b0f19 primary dark
            $this->Rect(0, 0, 210, 297, 'F');
            
            // Header bar background
            $this->SetFillColor(19, 27, 46); // #131b2e secondary dark
            $this->Rect(0, 0, 210, 25, 'F');
            
            // Title text
            $this->SetFont('Arial', 'B', 14);
            $this->SetTextColor(96, 165, 250); // #60a5fa accent blue
            $this->Cell(0, 15, 'SMART TRADING BOT OUTCOMES REPORT', 0, 1, 'C');
            $this->Ln(10);
        }
        
        function Footer() {
            $this->SetY(-15);
            $this->SetFont('Arial', 'I', 8);
            $this->SetTextColor(148, 163, 184); // #94a3b8 text-secondary
            $this->Cell(0, 10, 'Page ' . $this->PageNo() . ' | Generated on ' . date('Y-m-d H:i:s') . ' IST', 0, 0, 'C');
        }
    }
    
    $pdf = new TradeReportPDF('P', 'mm', 'A4');
    $pdf->SetMargins(15, 25, 15);
    $pdf->AliasNbPages();
    
    // Fetch data
    $idsString = implode(',', $tradeIds);
    $query = "SELECT p.*, o.trade_result, o.win_loss_time, o.win_loss_price, 
                     UNIX_TIMESTAMP(r.created_at) AS trigger_unixtime
              FROM prediction_trade_data p
              LEFT JOIN trade_outcome_details o ON p.raw_trade_id = o.raw_trade_id
              INNER JOIN raw_trade_data r ON p.raw_trade_id = r.id
              WHERE p.raw_trade_id IN ($idsString)
              ORDER BY p.raw_trade_id DESC";
    $res = $conn->query($query);
    
    $tradesData = [];
    if ($res) {
        while ($t = $res->fetch_assoc()) {
            $tradesData[$t['raw_trade_id']] = $t;
        }
    }
    
    // --- 1. CALCULATE DAILY STATISTICS ---
    $totalTrades = count($tradesData);
    $totalWins = 0;
    $totalLosses = 0;
    $pairStats = [];

    foreach ($tradesData as $trade) {
        $resOutcome = strtolower($trade['trade_result'] ?? '');
        $pair = $trade['pair_name'];
        
        if (!isset($pairStats[$pair])) {
            $pairStats[$pair] = ['wins' => 0, 'losses' => 0, 'total' => 0];
        }
        $pairStats[$pair]['total']++;

        if ($resOutcome === 'win') {
            $totalWins++;
            $pairStats[$pair]['wins']++;
        } elseif ($resOutcome === 'loss') {
            $totalLosses++;
            $pairStats[$pair]['losses']++;
        }
    }

    $executedTrades = $totalWins + $totalLosses;
    $winRate = ($executedTrades > 0) ? round(($totalWins / $executedTrades) * 100, 1) : 0;
    $bestPair = 'N/A';
    $bestPairWinRate = -1;
    $bestPairWins = -1;
    
    foreach ($pairStats as $pair => $stats) {
        if ($stats['total'] > 0) {
            $pRate = $stats['wins'] / $stats['total'];
            // Tie breaker: higher win rate, or if equal, the one with more total wins
            if ($pRate > $bestPairWinRate || ($pRate == $bestPairWinRate && $stats['wins'] > $bestPairWins)) {
                $bestPairWinRate = $pRate;
                $bestPairWins = $stats['wins'];
                $bestPair = $pair . " (" . $stats['wins'] . "W / " . $stats['losses'] . "L)";
            }
        }
    }

    // --- 2. GENERATE PDF COVER PAGE ---
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 18);
    $pdf->SetTextColor(248, 250, 252); // White
    $pdf->SetY(40);
    $pdf->Cell(0, 10, 'Daily Performance Summary', 0, 1, 'C');
    $pdf->Ln(10);

    // Draw Summary Card Background
    $pdf->SetFillColor(30, 41, 59); // Dark slate
    $pdf->Rect(15, 60, 180, 80, 'F');

    $pdf->SetFont('Arial', 'B', 14);
    
    // Total Trades
    $pdf->SetXY(25, 70);
    $pdf->SetTextColor(148, 163, 184);
    $pdf->Cell(80, 10, 'Total Trades Taken:', 0, 0);
    $pdf->SetTextColor(248, 250, 252);
    $pdf->Cell(70, 10, $totalTrades, 0, 1, 'R');
    
    // Total Wins
    $pdf->SetX(25);
    $pdf->SetTextColor(148, 163, 184);
    $pdf->Cell(80, 10, 'Total Wins:', 0, 0);
    $pdf->SetTextColor(16, 185, 129); // Green
    $pdf->Cell(70, 10, $totalWins, 0, 1, 'R');
    
    // Total Losses
    $pdf->SetX(25);
    $pdf->SetTextColor(148, 163, 184);
    $pdf->Cell(80, 10, 'Total Losses:', 0, 0);
    $pdf->SetTextColor(239, 68, 68); // Red
    $pdf->Cell(70, 10, $totalLosses, 0, 1, 'R');
    
    // Win Rate
    $pdf->SetX(25);
    $pdf->SetTextColor(148, 163, 184);
    $pdf->Cell(80, 10, 'Daily Win Rate:', 0, 0);
    $pdf->SetTextColor(96, 165, 250); // Blue
    $pdf->Cell(70, 10, $winRate . '%', 0, 1, 'R');

    // Best Performing Pair
    $pdf->SetX(25);
    $pdf->SetTextColor(148, 163, 184);
    $pdf->Cell(80, 10, 'Best Performing Pair:', 0, 0);
    $pdf->SetTextColor(251, 191, 36); // Amber
    $pdf->Cell(70, 10, $bestPair, 0, 1, 'R');
    
    $pagesCount = 0;
    foreach ($tradeIds as $id) {
        $imgPath = __DIR__ . '/temp_images/trade_' . $id . '.png';
        if (!file_exists($imgPath)) {
            if ($isCli) {
                echo "Warning: Chart image for trade #{$id} does not exist at " . basename($imgPath) . ". Skipping page.\n";
            }
            continue;
        }
        
        if ($isCli) {
            echo "Adding page for trade #{$id} with chart " . basename($imgPath) . "...\n";
        }
        $pdf->AddPage();
        $pagesCount++;
        $trade = $tradesData[$id] ?? null;
        
        if ($trade) {
            // Draw metadata card
            $pdf->SetFillColor(30, 41, 59); // #1e293b dark slate card
            $pdf->Rect(15, 30, 180, 42, 'F');
            
            // Trade direction styling
            $direction = strtoupper($trade['trade_direction']);
            $dirLabel = ($direction === 'UP') ? 'BUY' : 'SELL';
            
            $pdf->SetFont('Arial', 'B', 15);
            $pdf->SetTextColor(248, 250, 252); // #f8fafc white
            $pdf->SetXY(20, 35);
            $pdf->Cell(0, 6, $trade['pair_name'] . ' - ' . $dirLabel, 0, 1);
            
            $pdf->SetFont('Arial', '', 10);
            $pdf->SetTextColor(148, 163, 184); // #94a3b8 text-secondary
            
            $alertTimeIST = 'N/A';
            if (!empty($trade['trigger_unixtime'])) {
                try {
                    $alertTimeIST = (new DateTime("@" . $trade['trigger_unixtime']))->setTimezone($istTimezone)->format('Y-m-d H:i:s');
                } catch (Exception $e) {}
            } elseif (!empty($trade['last_alert_time'])) {
                try {
                    $alertDT = new DateTime($trade['last_alert_time'], $utcTimezone);
                    $alertDT->setTimezone($istTimezone);
                    $alertTimeIST = $alertDT->format('Y-m-d H:i:s');
                } catch (Exception $e) {}
            }
            
            $pdf->SetXY(20, 45);
            $pdf->Cell(85, 5, 'Trade ID: #' . $id, 0, 0);
            $pdf->Cell(85, 5, 'Alert Time: ' . $alertTimeIST . ' IST', 0, 1);
            
            $pdf->SetX(20);
            $pdf->Cell(85, 5, 'Target Price: ' . number_format($trade['price_target'], 5), 0, 0);
            $outcome = strtoupper($trade['trade_result'] ?? 'PENDING');
            $pdf->Cell(85, 5, 'Outcome: ' . $outcome, 0, 1);
            
            $pdf->SetX(20);
            $winLossPrice = $trade['win_loss_price'] !== null ? number_format($trade['win_loss_price'], 5) : 'N/A';
            $pdf->Cell(85, 5, 'Resolution Price: ' . $winLossPrice, 0, 0);
            $winLossTime = $trade['win_loss_time'] !== null ? $trade['win_loss_time'] . ' IST' : 'N/A';
            $pdf->Cell(85, 5, 'Resolution Time: ' . $winLossTime, 0, 1);
        }
        
        // Draw screenshot image
        $pdf->Image($imgPath, 15, 78, 180, 100);
        
        // Delete server file to free space
        @unlink($imgPath);
    }
    
    if ($pagesCount === 0) {
        if ($isCli) {
            echo "ERROR: Cannot compile PDF. 0 pages added because all trade images are missing.\n";
        }
        die("Error: No trade chart images were found to compile the PDF. Check if GD is enabled and files are writable.");
    }
    
    if ($isCli) {
        echo "PDF compiled successfully with $pagesCount page(s).\n";
    }
    
    if (isset($_GET['broadcast']) && $_GET['broadcast'] == '1') {
        $pdfFileName = "daily_trades_" . date('Ymd_His') . ".pdf";
        $tempDir = __DIR__ . '/temp_images';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $pdfFilePath = $tempDir . '/' . $pdfFileName;
        if ($isCli) {
            echo "Saving PDF report to: $pdfFilePath\n";
        }
        $pdf->Output('F', $pdfFilePath);
        if ($isCli && file_exists($pdfFilePath)) {
            echo "PDF saved successfully. File size: " . filesize($pdfFilePath) . " bytes.\n";
        }
        
        // Fetch chat IDs from telegram_users
        $chatIds = [];
        $resChats = $conn->query("SELECT chat_id FROM telegram_users");
        if ($resChats) {
            while ($cRow = $resChats->fetch_assoc()) {
                $chatIds[] = $cRow['chat_id'];
            }
        }
        
        $sentCount = 0;
        if (!empty($chatIds) && isset($botToken)) {
            $website = "https://api.telegram.org/bot$botToken";
            
            if ($isCli) {
                echo "Sending PDF report via Telegram Bot API to " . count($chatIds) . " subscribers...\n";
            }
 
            foreach ($chatIds as $chatId) {
                if ($isCli) {
                    echo "Sending to chat ID #{$chatId}... ";
                }
                
               // Calculate Setup Not Formed (Total - Wins - Losses)
                $setupNotFormed = $totalTrades - $totalWins - $totalLosses;

                // Build Rich Telegram Caption
                $telegramCaption = "📊 *Daily Trades Report*\n\n";
                $telegramCaption .= "📈 *Total Trades:* {$totalTrades}\n";
                $telegramCaption .= "✅ *Wins:* {$totalWins}\n";
                $telegramCaption .= "❌ *Losses:* {$totalLosses}\n";
                $telegramCaption .= "⚠️ *Setup Not Formed:* {$setupNotFormed}\n";
                $telegramCaption .= "🎯 *Win Rate:* {$winRate}%\n";
                $telegramCaption .= "🏆 *Best Pair:* {$bestPair}\n\n";
                $telegramCaption .= "📄 _See attached PDF for detailed charts._";
                
                $params = [
                    'chat_id'    => $chatId,
                    'document'   => new CURLFile(realpath($pdfFilePath)),
                    'caption'    => $telegramCaption,
                    'parse_mode' => 'Markdown' // Enables Bold (*) and Italic (_)
                ];
                $ch = curl_init($website . '/sendDocument');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                $response = curl_exec($ch);
                $error = curl_error($ch);
 
 
                if ($response === false) {
                    if ($isCli) echo "CURL ERROR: $error\n";
                } else {
                    $resDecoded = json_decode($response, true);
                    if (isset($resDecoded['ok']) && $resDecoded['ok'] == true) {
                        if ($isCli) echo "SUCCESS\n";
                        $sentCount++;
                    } else {
                        $errDesc = $resDecoded['description'] ?? 'Unknown API error';
                        if ($isCli) echo "FAILED: $errDesc\n";
                    }
                }
 
                curl_close($ch);
            }
        } else {
            if ($isCli) {
                if (empty($chatIds)) {
                    echo "WARNING: No chat IDs found in 'telegram_users' table.\n";
                }
                if (!isset($botToken)) {
                    echo "WARNING: Telegram bot token is not defined in 'db.php'.\n";
                }
            }
        }
        
        if (file_exists($pdfFilePath)) {
            @unlink($pdfFilePath);
        }

        // ==========================================
        // MARK TRADES AS BROADCASTED (JSON VERSION)
        // ==========================================
        if (!empty($tradeIds)) {
            $jsonDb = getJsonDb(); 
            
            foreach ($tradeIds as $id) {
                $idStr = (string)$id;
                if (!isset($jsonDb[$idStr])) {
                    $jsonDb[$idStr] = []; 
                }
                $jsonDb[$idStr]['is_broadcasted'] = 1;
                $jsonDb[$idStr]['broadcast_time'] = date('Y-m-d H:i:s');
            }
            
            saveJsonDb($jsonDb);
        }
        // ==========================================
        
        $conn->close();
        if ($isCli) {
            echo "Marking trades completed. Headless broadcast finished! Sent: $sentCount message(s).\n";
        }
        echo json_encode(['success' => true, 'sent_count' => $sentCount]);
        exit;
    } else {
        $pdf->Output('I', 'Trade_Outcomes_Report_' . date('Ymd_His') . '.pdf');
        exit;
    }
}

// ==========================================
// 4. GD CHART GENERATION & STREAMING ENDPOINTS
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] == '1' && isset($_GET['trade_id'])) {
    header('Content-Type: application/json');
    $tradeId = (int)$_GET['trade_id'];
    
    // Join outcome details to supply user with computed win/loss markers
    $query = "SELECT p.*, o.trade_result, o.win_loss_time, o.win_loss_price, 
                     UNIX_TIMESTAMP(r.created_at) AS trigger_unixtime
              FROM prediction_trade_data p 
              LEFT JOIN trade_outcome_details o ON p.raw_trade_id = o.raw_trade_id
              INNER JOIN raw_trade_data r ON p.raw_trade_id = r.id
              WHERE p.raw_trade_id = " . (int)$tradeId . " LIMIT 1";
    $res = $conn->query($query);
    $tradeRes = $res ? $res->fetch_assoc() : null;
    
    if (!$tradeRes) {
        echo json_encode(['error' => 'Trade not found in database.']);
        exit;
    }
    
    // Generate and save GD image to temp_images/trade_XX.png immediately on server
    $tempDir = __DIR__ . '/temp_images';
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }
    $filePath = $tempDir . '/trade_' . $tradeId . '.png';
    generateTradeChartGD($tradeId, $filePath);
    
    $alertTimeUTC = $tradeRes['last_alert_time'] ?? '';
    try {
        $alertTimeIST = (new DateTime($alertTimeUTC, $utcTimezone))->setTimezone($istTimezone)->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        $alertTimeIST = 'N/A';
    }
    
    $winLossTimeIST = null;
    if (!empty($tradeRes['win_loss_time'])) {
        try {
            $winLossDT = new DateTime($tradeRes['win_loss_time'], $istTimezone);
            $winLossTimeIST = $winLossDT->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            $winLossTimeIST = $tradeRes['win_loss_time'];
        }
    }
    
    echo json_encode([
        'success' => true,
        'pair' => $tradeRes['pair_name'],
        'direction' => $tradeRes['trade_direction'],
        'alertTimeIST' => $alertTimeIST,
        'tradeResult' => $tradeRes['trade_result'] ?? 'pending',
        'winLossTime' => $winLossTimeIST,
        'winLossPrice' => $tradeRes['win_loss_price'] !== null ? (float)$tradeRes['win_loss_price'] : null,
        'targetPrice' => (float)$tradeRes['price_target']
    ]);
    exit;
}

// GET dynamic chart image streaming endpoint
if (isset($_GET['get_chart_image']) && isset($_GET['trade_id'])) {
    $tradeId = (int)$_GET['trade_id'];
    generateTradeChartGD($tradeId);
    exit;
}
?>
<?php
if (!$isCli) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advanced Trade Plotter & Chart Viewer</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-primary: #0b0f19;
            --bg-secondary: #131b2e;
            --bg-tertiary: #1e293b;
            --accent-blue: #3b82f6;
            --accent-green: #10b981;
            --accent-red: #ef4444;
            --text-main: #f8fafc;
            --text-secondary: #94a3b8;
            --border-color: #334155;
            --glass-bg: rgba(19, 27, 46, 0.7);
            --glass-border: rgba(255, 255, 255, 0.05);
            --gradient-blue: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
        }

        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--bg-primary);
            color: var(--text-main);
            margin: 0;
            padding: 30px;
        }

        .container {
            max-width: 1250px;
            margin: 0 auto;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 20px;
        }

        h1 {
            font-size: 28px;
            font-weight: 800;
            background: linear-gradient(to right, #60a5fa, #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin: 0;
        }

        .back-link {
            color: var(--accent-blue);
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        /* Chart Workspace Layout */
        .workspace {
            display: grid;
            grid-template-columns: 340px 1fr;
            gap: 25px;
            align-items: start;
        }

        /* Sidebar Trade List */
        .sidebar {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 20px;
            max-height: 720px;
            display: flex;
            flex-direction: column;
        }

        .sidebar h2 {
            font-size: 18px;
            margin: 0;
            font-weight: 600;
        }

        .search-box {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-main);
            padding: 10px 14px;
            font-size: 14px;
            outline: none;
        }

        .trade-select {
            margin-right: 10px;
            accent-color: var(--accent-blue);
            width: 16px;
            height: 16px;
            cursor: pointer;
        }

        .trade-list {
            overflow-y: auto;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-height: 500px;
            padding-right: 5px;
        }

        .trade-list::-webkit-scrollbar {
            width: 6px;
        }

        .trade-list::-webkit-scrollbar-thumb {
            background: var(--border-color);
            border-radius: 3px;
        }

        .trade-item {
            background-color: rgba(255,255,255,0.02);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 12px;
            cursor: pointer;
            transition: all 0.2s;
            text-align: left;
            position: relative;
        }

        .trade-item:hover {
            border-color: var(--accent-blue);
            background-color: rgba(255,255,255,0.04);
        }

        .trade-item.active {
            border-color: var(--accent-blue);
            background-color: rgba(59, 130, 246, 0.1);
        }

        .trade-item-header {
            display: flex;
            align-items: center;
            margin-bottom: 6px;
        }

        .trade-pair {
            font-weight: 700;
            font-size: 15px;
            flex: 1;
        }

        .trade-direction {
            font-size: 11px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 4px;
        }

        .direction-up {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--accent-green);
        }

        .direction-down {
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--accent-red);
        }

        .trade-time {
            font-size: 12px;
            color: var(--text-secondary);
            padding-left: 26px; /* align with pair name text next to checkbox */
        }

        /* Outcome Badges */
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .badge-win {
            background-color: rgba(16, 185, 129, 0.15);
            color: var(--accent-green);
            border: 1px solid rgba(16, 185, 129, 0.25);
        }
        .badge-loss {
            background-color: rgba(239, 68, 68, 0.15);
            color: var(--accent-red);
            border: 1px solid rgba(239, 68, 68, 0.25);
        }
        .badge-pending {
            background-color: rgba(148, 163, 184, 0.15);
            color: var(--text-secondary);
            border: 1px solid rgba(148, 163, 184, 0.25);
        }
        .badge-setup_not_formed {
            background-color: rgba(245, 158, 11, 0.15);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.25);
        }

        /* Main Chart Area */
        .chart-panel {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            position: relative;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }

        #chart-container {
            width: 100%;
            height: 520px;
            margin-top: 15px;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--border-color);
            position: relative;
            background-color: #0b121f;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .active-trade-info {
            display: flex;
            align-items: center;
            gap: 15px;
            text-align: left;
            flex-wrap: wrap;
        }

        .active-trade-pair {
            font-size: 20px;
            font-weight: 800;
        }

        .active-trade-meta {
            font-size: 14px;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .chart-actions {
            display: flex;
            gap: 10px;
        }

        .chart-btn {
            background-color: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            padding: 8px 14px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .chart-btn:hover {
            border-color: var(--accent-blue);
            background-color: rgba(255,255,255,0.02);
        }

        .chart-btn.active {
            background: var(--gradient-blue);
            border-color: var(--accent-blue);
        }

        #chart-placeholder {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: var(--text-secondary);
            font-size: 16px;
        }

        #loading-overlay {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(11, 15, 25, 0.9);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: 600;
            z-index: 20;
            display: none;
        }
    </style>
</head>
<body>

<div class="container">
    <header>
        <h1>📈 Advanced Trade Plotter</h1>
        <a href="../admin_dashboard.php" class="back-link">« Back to Dashboard</a>
    </header>

    <div class="workspace">
        <div class="sidebar">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">
                <h2>Select Trade</h2>
                <div style="font-size: 13px; color: var(--text-secondary); display: flex; align-items: center; gap: 4px;">
                    <input type="checkbox" id="select-all-checkbox" onchange="toggleSelectAll(this)" checked style="cursor: pointer;"> Select All (<span id="selected-count">0</span>)
                </div>
            </div>
            
            <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                <input type="text" id="search" class="search-box" style="margin-bottom: 0; flex: 1;" placeholder="Search pair (e.g. EURUSD)..." oninput="filterTrades()">
            </div>
            
            <div style="display: flex; gap: 6px; margin-bottom: 15px;">
                <input type="number" id="search-id" class="search-box" style="margin-bottom: 0; width: 90px;" placeholder="Trade ID..." onkeydown="if(event.key === 'Enter') loadTradeByIdInput()">
                <button class="chart-btn" style="background: var(--gradient-blue); border-color: var(--accent-blue); padding: 8px 10px; flex: 1;" onclick="loadTradeByIdInput()">Load ID</button>
                <button class="chart-btn" id="btn-generate-pdf" style="background: var(--gradient-blue); border-color: var(--accent-blue); padding: 8px 10px;" onclick="generateCheckedPdf()">📄 PDF</button>
            </div>
            
            <div class="trade-list" id="trade-list">
                <?php
                // Fetch recent trade signals that are verified wins or losses
                $tradeSql = "SELECT p.raw_trade_id, p.pair_name, p.trade_direction, p.last_alert_time, o.trade_result 
                             FROM prediction_trade_data p
                             INNER JOIN trade_outcome_details o ON p.raw_trade_id = o.raw_trade_id
                             WHERE o.trade_result IN ('win', 'loss')
                             ORDER BY p.raw_trade_id DESC LIMIT 100";
                $tradeResult = $conn->query($tradeSql);
                if ($tradeResult && $tradeResult->num_rows > 0) {
                    while ($row = $tradeResult->fetch_assoc()) {
                        $direction = strtoupper($row['trade_direction']);
                        $dirLabel = ($direction === 'UP') ? 'BUY' : 'SELL';
                        $dirClass = ($direction === 'UP') ? 'direction-up' : 'direction-down';
                        $istAlertTime = (new DateTime($row['last_alert_time'], $utcTimezone))->setTimezone($istTimezone)->format('d M H:i');
                        
                        $resultLabel = '';
                        $resultStyle = '';
                        if (!empty($row['trade_result'])) {
                            $res = strtolower($row['trade_result']);
                            if ($res === 'win') {
                                $resultLabel = ' | WIN';
                                $resultStyle = 'color: var(--accent-green); font-weight: 700; font-size: 11px;';
                            } elseif ($res === 'loss') {
                                $resultLabel = ' | LOSS';
                                $resultStyle = 'color: var(--accent-red); font-weight: 700; font-size: 11px;';
                            } elseif ($res === 'setup_not_formed') {
                                $resultLabel = ' | SETUP N/A';
                                $resultStyle = 'color: #f59e0b; font-weight: 700; font-size: 11px;';
                            } else {
                                $resultLabel = ' | PENDING';
                                $resultStyle = 'color: var(--text-secondary); font-size: 11px;';
                            }
                        } else {
                            $resultLabel = ' | PENDING';
                            $resultStyle = 'color: var(--text-secondary); font-size: 11px;';
                        }
                        
                        echo "<div class='trade-item' id='trade-{$row['raw_trade_id']}'>
                                <div class='trade-item-header'>
                                    <input type='checkbox' class='trade-select' value='{$row['raw_trade_id']}' onclick='event.stopPropagation(); updateSelectedCount();' checked>
                                    <span class='trade-pair' onclick='loadTrade({$row['raw_trade_id']})'>{$row['pair_name']}</span>
                                    <span class='trade-direction {$dirClass}' onclick='loadTrade({$row['raw_trade_id']})'>{$dirLabel}</span>
                                </div>
                                <div class='trade-time' onclick='loadTrade({$row['raw_trade_id']})'>ID: #{$row['raw_trade_id']} | {$istAlertTime} IST <span style='{$resultStyle}'>{$resultLabel}</span></div>
                              </div>";
                    }
                } else {
                    echo "<div style='color: var(--text-secondary); text-align: center; padding: 20px;'>No trade data found in database.</div>";
                }
                ?>
            </div>
        </div>

        <div class="chart-panel">
            <div class="chart-header">
                <div class="active-trade-info" id="active-info">
                    <span class="active-trade-pair" id="active-pair">Select a Trade</span>
                    <span class="active-trade-meta" id="active-meta">Select a trade from the sidebar to view the interactive chart.</span>
                </div>
                <div class="chart-actions" id="chart-actions" style="display: none;">
                    <button class="chart-btn" style="background-color: rgba(16, 185, 129, 0.1); border-color: var(--accent-green);" onclick="exportCurrentScreenshot()">📷 Export Image</button>
                </div>
            </div>

            <div id="chart-container">
                <div id="chart-placeholder">Select a trade signal from the sidebar to load the chart...</div>
                <div id="loading-overlay">Loading Chart Data...</div>
            </div>
        </div>
    </div>
</div>

<script>
    let activeTradeId = null;
    let activeTradeData = null;

    // Toggle all checkboxes in sidebar
    function toggleSelectAll(master) {
        const checkboxes = document.querySelectorAll('.trade-select');
        checkboxes.forEach(cb => {
            const item = cb.closest('.trade-item');
            if (item && item.style.display !== 'none') {
                cb.checked = master.checked;
            }
        });
        updateSelectedCount();
    }

    // Update the counter showing how many checkboxes are checked
    function updateSelectedCount() {
        const checked = document.querySelectorAll('.trade-select:checked').length;
        document.getElementById('selected-count').innerText = checked;
    }

    // Initialize the selection counter and automated broadcast runner
    document.addEventListener('DOMContentLoaded', () => {
        updateSelectedCount();
        
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('run_broadcast') && urlParams.get('run_broadcast') === '1') {
            const tradeIdsStr = urlParams.get('trade_ids');
            if (tradeIdsStr) {
                const tradeIds = tradeIdsStr.split(',').filter(x => x);
                if (tradeIds.length > 0) {
                    runAutomatedBroadcast(tradeIds);
                }
            }
        }
    });

    // Automated rendering and Telegram PDF broadcast pipeline (fully server-side GD)
    async function runAutomatedBroadcast(tradeIds) {
        const overlay = document.getElementById('loading-overlay');
        overlay.style.display = 'flex';
        
        // Step 1: Generate all GD chart images on the server
        for (let i = 0; i < tradeIds.length; i++) {
            const id = tradeIds[i];
            overlay.innerHTML = `<span style="text-align: center; font-size: 18px; color: #f8fafc; font-family: 'Outfit', sans-serif;">📊 Generating Telegram PDF Report<br><br>Rendering Chart ${i + 1}/${tradeIds.length} (ID #${id})...<br><small style="color: #64748b; font-size: 13px;">Server-side GD rendering in progress</small></span>`;
            
            // Highlight list item
            document.querySelectorAll('.trade-item').forEach(el => el.classList.remove('active'));
            const activeItem = document.getElementById(`trade-${id}`);
            if (activeItem) {
                activeItem.classList.add('active');
                activeItem.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }

            try {
                // The AJAX endpoint generates the GD image on the server automatically
                const currentUrl = window.location.pathname;
                const res = await fetch(`${currentUrl}?ajax=1&trade_id=${id}`);
                const data = await res.json();
                if (data.error) {
                    console.error(`Error generating chart for trade #${id}:`, data.error);
                }
            } catch (err) {
                console.error(`Error generating chart for trade #${id}:`, err);
            }
        }
        
        // Step 2: Compile PDF and broadcast
        overlay.innerHTML = `<span style="text-align: center; font-size: 18px; color: #f8fafc; font-family: 'Outfit', sans-serif;">🤖 Compiling Outcomes PDF & Broadcasting...</span>`;
        
        const currentUrl = window.location.pathname;
        try {
            await fetch(`${currentUrl}?ajax=1&set_pdf_ids=1`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ 'trade_ids': tradeIds.join(',') })
            });
            
            const bResp = await fetch(`${currentUrl}?generate_pdf=1&broadcast=1`);
            const bRes = await bResp.json();
            
            if (bRes.success) {
                overlay.innerHTML = `<span style="text-align: center; font-size: 18px; color: #10b981; font-family: 'Outfit', sans-serif;">✅ Outcomes PDF Report compiled and broadcasted successfully to all Telegram users!</span>`;
                setTimeout(() => {
                    const referrer = document.referrer;
                    let returnUrl = '../evaluate_win_loss.php?broadcast_success=1';
                    
                    if (referrer && (referrer.includes('evaluate_win_loss.php') || referrer.includes('daily_trades_pdf.php'))) {
                        returnUrl = referrer.split('?')[0] + '?broadcast_success=1';
                    }
                    
                    window.location.href = returnUrl;
                }, 2500);
            } else {
                throw new Error(bRes.error || "Broadcast failed");
            }
        } catch (err) {
            overlay.innerHTML = `<span style="text-align: center; font-size: 18px; color: #ef4444; font-family: 'Outfit', sans-serif;">❌ Broadcast Error: ${err.message}<br><br><button onclick="window.location.href='../evaluate_win_loss.php'" style="background:#1e293b; color:#fff; border:1px solid #334155; padding:8px 16px; border-radius:6px; cursor:pointer;">Return to Dashboard</button></span>`;
        }
    }

    // Generate multipage PDF report of all checked trade outcomes (fully server-side GD)
    async function generateCheckedPdf() {
        const checkedBoxes = Array.from(document.querySelectorAll('.trade-select:checked'));
        if (checkedBoxes.length === 0) {
            alert('Please check at least one trade signal to generate the PDF report.');
            return;
        }
        
        if (!confirm(`This will compile a PDF report containing charts for the ${checkedBoxes.length} selected trades. Continue?`)) {
            return;
        }

        const btn = document.getElementById('btn-generate-pdf');
        const originalText = btn.innerText;
        btn.disabled = true;

        const overlay = document.getElementById('loading-overlay');
        overlay.style.display = 'flex';
        
        const tradeIds = checkedBoxes.map(cb => cb.value);

        // Step 1: Generate all GD chart images on the server
        for (let i = 0; i < tradeIds.length; i++) {
            const id = tradeIds[i];
            overlay.innerHTML = `<span style="text-align: center;">Rendering Chart ${i + 1}/${tradeIds.length} (ID #${id})...<br><small style="color: var(--text-secondary)">Server-side GD rendering</small></span>`;
            
            document.querySelectorAll('.trade-item').forEach(el => el.classList.remove('active'));
            const activeItem = document.getElementById(`trade-${id}`);
            if (activeItem) {
                activeItem.classList.add('active');
                activeItem.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }

            try {
                const currentUrl = window.location.pathname;
                const res = await fetch(`${currentUrl}?ajax=1&trade_id=${id}`);
                const data = await res.json();
                if (data.error) {
                    console.error(`Error generating chart for trade #${id}:`, data.error);
                }
            } catch (err) {
                console.error(`Error generating chart for trade #${id}:`, err);
            }
        }
        
        // Step 2: Compile PDF
        overlay.innerHTML = `<span style="text-align: center;">Compiling Multipage A4 PDF Document...</span>`;
        
        const currentUrl = window.location.pathname;
        try {
            await fetch(`${currentUrl}?ajax=1&set_pdf_ids=1`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ 'trade_ids': tradeIds.join(',') })
            });
            
            const pdfUrl = `${currentUrl}?generate_pdf=1`;
            window.location.href = pdfUrl;
        } catch (err) {
            console.error('Failed to set PDF trade IDs:', err);
            alert('Failed to generate PDF due to a network error.');
        }
        
        overlay.style.display = 'none';
        btn.disabled = false;
        btn.innerText = originalText;
    }

    // Export current chart as PNG download (uses server-side GD image)
    function exportCurrentScreenshot() {
        if (!activeTradeId) {
            alert('Please select and load a trade first.');
            return;
        }
        const pair = document.getElementById('active-pair').innerText;
        const currentUrl = window.location.pathname;
        const link = document.createElement('a');
        link.download = `Trade_${activeTradeId}_${pair}.png`;
        link.href = `${currentUrl}?get_chart_image=1&trade_id=${activeTradeId}`;
        link.click();
    }

    // Filter trades in sidebar
    function filterTrades() {
        const query = document.getElementById('search').value.toUpperCase();
        const items = document.querySelectorAll('.trade-item');
        
        items.forEach(item => {
            const pairText = item.querySelector('.trade-pair').innerText.toUpperCase();
            if (pairText.includes(query)) {
                item.style.display = 'block';
            } else {
                item.style.display = 'none';
            }
        });
        updateSelectedCount();
    }

    // Load trade directly by entering ID input
    function loadTradeByIdInput() {
        const idInput = document.getElementById('search-id');
        const id = parseInt(idInput.value);
        if (isNaN(id) || id <= 0) {
            alert('Please enter a valid numeric Trade ID.');
            return;
        }
        loadTrade(id);
    }

    // Load and render trade chart (displays server-side GD-rendered PNG)
    function loadTrade(id) {
        // Highlight active item
        document.querySelectorAll('.trade-item').forEach(item => item.classList.remove('active'));
        const tradeItem = document.getElementById(`trade-${id}`);
        if (tradeItem) {
            tradeItem.classList.add('active');
        }
        
        // Show loading
        const overlay = document.getElementById('loading-overlay');
        overlay.style.display = 'flex';
        overlay.innerHTML = "Loading Chart Data...";
        document.getElementById('chart-placeholder').style.display = 'none';
        
        activeTradeId = id;
        
        // Fetch trade metadata from AJAX endpoint (also generates GD image on server)
        const currentUrl = window.location.pathname;
        fetch(`${currentUrl}?ajax=1&trade_id=${id}`)
            .then(res => {
                if (!res.ok) {
                    throw new Error(`HTTP error! status: ${res.status}`);
                }
                return res.json();
            })
            .then(data => {
                overlay.style.display = 'none';
                if (data.error) {
                    alert(data.error);
                    return;
                }
                
                activeTradeData = data;
                
                // Update UI header bar
                document.getElementById('active-pair').innerText = data.pair;
                const dirLabel = data.direction === 'UP' ? 'BUY' : 'SELL';
                
                let outcomeHtml = '';
                if (data.tradeResult) {
                    const outcomeClean = data.tradeResult.toUpperCase().replace(/_/g, ' ');
                    let badgeClass = 'badge-pending';
                    if (data.tradeResult === 'win') badgeClass = 'badge-win';
                    else if (data.tradeResult === 'loss') badgeClass = 'badge-loss';
                    else if (data.tradeResult === 'setup_not_formed') badgeClass = 'badge-setup_not_formed';
                    
                    outcomeHtml = `&nbsp;&nbsp;Outcome: <span class="badge ${badgeClass}">${outcomeClean}</span>`;
                    if (data.winLossPrice) {
                        outcomeHtml += `&nbsp;&nbsp;Res. Price: <strong>${parseFloat(data.winLossPrice).toFixed(5)}</strong>`;
                    }
                    if (data.winLossTime) {
                        outcomeHtml += `&nbsp;&nbsp;Res. Time: <strong>${data.winLossTime} IST</strong>`;
                    }
                }
                
                document.getElementById('active-meta').innerHTML = `
                    <span class="trade-direction ${data.direction === 'UP' ? 'direction-up' : 'direction-down'}">${dirLabel}</span>
                    &nbsp;&nbsp;Alert Time: <strong>${data.alertTimeIST} IST</strong>
                    &nbsp;&nbsp;Target Price: <strong>${parseFloat(data.targetPrice).toFixed(5)}</strong>
                    ${outcomeHtml}
                `;
                document.getElementById('chart-actions').style.display = 'flex';
                
                // Display the server-rendered GD chart image
                const container = document.getElementById('chart-container');
                const imgUrl = `${currentUrl}?get_chart_image=1&trade_id=${id}&t=${Date.now()}`;
                container.innerHTML = `<img src="${imgUrl}" alt="Trade #${id} Chart" style="width: 100%; height: 100%; object-fit: contain; display: block;">` +
                                      `<div id="loading-overlay" style="display: none;">Loading Chart Data...</div>`;
            })
            .catch(err => {
                overlay.style.display = 'none';
                alert('Failed to load chart data. Make sure the dataset CSV file is uploaded on the server.');
                console.error(err);
            });
    }
</script>

</body>
</html>
<?php
}
?>