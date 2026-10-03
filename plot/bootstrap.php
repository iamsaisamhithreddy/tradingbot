<?php
/**
 * bootstrap.php
 * ---------------------------------------------------
 * Environment setup: CLI/CGI argument parsing, session handling,
 * DB connection loading + validation, table bootstrap, timezones,
 * and the flat-file JSON "outcomes" database helpers.
 *
 * Exposes globals used by the other modules:
 *   $conn            - mysqli connection (from db.php)
 *   $isCli           - bool, true when running via CLI/CGI/cron
 *   $istTimezone      - DateTimeZone Asia/Kolkata
 *   $utcTimezone      - DateTimeZone UTC
 *   $jsonDbPath       - path to outcomes_db.json
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// ---------------------------------------------------
// Parse CLI/CGI arguments (key=value) into $_GET/$_REQUEST
// ---------------------------------------------------
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

// Safely start session for web requests to ensure trade IDs persist
if (!$isCli && session_status() === PHP_SESSION_NONE && !headers_sent()) {
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
    $fp = fopen($jsonDbPath, 'c');
    if ($fp && flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
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

// Bootstrap (Create table if not exists)
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
