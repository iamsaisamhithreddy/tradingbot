<?php
/**
 * trades_status_verify_final.php
 *
 * Historical BEFORE-JUN-2025 evaluator.
 *
 * Rules enforced:
 * 1. No ForexFactory API/MDS.
 * 2. Read trades from prediction_trade_data.
 * 3. Alert must be on the same IST date between 12:30:00 and 21:30:00 IST.
 * 4. Resolution/evaluation is allowed only on that same IST date between
 *    13:00:00 and 21:30:00 IST.
 * 5. Invalid alert-time records are deleted from prediction_trade_data.
 * 6. Uses date-wise CSV files from BEFORE-JUN-2025.
 * 7. Uses keyset pagination to stay below 150 MB memory.
 * 8. No mysqli_stmt::get_result() and no commands-out-of-sync issue.
 *
 * CLI:
 *   php trades_status_verify_final.php 2020-01-01 2025-05-31
 * Browser:
 *   trades_status_verify_final.php?start=2020-01-01&end=2025-05-31
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once __DIR__ . '/../db.php';

$IST = new DateTimeZone('Asia/Kolkata');
$UTC = new DateTimeZone('UTC');
$BATCH_SIZE = 250; // safe for 150 MB hosting

function cleanPair(string $pair): string {
    return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $pair));
}

function parseCsvTime($value): int {
    $v = trim((string)$value);
    if ($v === '') return 0;

    if (is_numeric($v)) {
        $n = (float)$v;
        if ($n > 100000000000) $n /= 1000.0;
        return (int)$n;
    }

    $dt = DateTime::createFromFormat('!Y-m-d H:i:s', $v, new DateTimeZone('UTC'));
    if (!$dt) $dt = DateTime::createFromFormat('!Y-m-d H:i', $v, new DateTimeZone('UTC'));
    if (!$dt) {
        $ts = strtotime($v . ' UTC');
        return $ts === false ? 0 : (int)$ts;
    }
    return $dt->getTimestamp();
}

/**
 * Exact evaluator logic, with the additional required resolution window.
 * Results are only allowed from resolutionStartTimestamp through sessionEndTimestamp.
 */
function evaluatePatternTrade(
    array $candles,
    string $direction,
    float $targetPrice,
    int $alertTimestamp,
    int $resolutionStartTimestamp,
    int $sessionEndTimestamp
): array {
    $count = count($candles);
    $alertCandleIndex = -1;

    for ($i = 0; $i < $count; $i++) {
        if ($alertTimestamp >= $candles[$i]['time'] &&
            $alertTimestamp < ($candles[$i]['time'] + 300)) {
            $alertCandleIndex = $i;
            break;
        }
    }

    if ($alertCandleIndex === -1) {
        return ['result' => 'pending', 'reason' => 'Alert candle not found'];
    }

    $consecutiveRed = 0;
    $consecutiveGreen = 0;
    $startWaveIndex = $alertCandleIndex;

    for ($j = $alertCandleIndex; $j < $count; $j++) {
        $c = $candles[$j];
        if ($direction === 'UP' && $c['close'] < $c['open']) {
            $startWaveIndex = $j;
            break;
        }
        if ($direction === 'DOWN' && $c['close'] > $c['open']) {
            $startWaveIndex = $j;
            break;
        }
    }

    $targetBroken = false;

    for ($i = $alertCandleIndex; $i < $count; $i++) {
        $c = $candles[$i];

        if ($c['close'] < $c['open']) {
            $consecutiveRed++;
            $consecutiveGreen = 0;
        } elseif ($c['close'] > $c['open']) {
            $consecutiveGreen++;
            $consecutiveRed = 0;
        } else {
            $consecutiveRed = 0;
            $consecutiveGreen = 0;
        }

        if ($c['time'] > $sessionEndTimestamp) break;
        if ($c['time'] < $resolutionStartTimestamp) continue;

        $waveLength = $i - $startWaveIndex + 1;
        if ($i < $startWaveIndex) {
            $waveLength = $i - $alertCandleIndex + 1;
        }

        if ($direction === 'UP') {
            if ($c['close'] < $targetPrice) {
                if (!$targetBroken) {
                    if ($waveLength < 3) {
                        return ['result' => 'setup_not_formed', 'reason' => 'Too fast'];
                    }
                    $targetBroken = true;
                }

                if ($consecutiveRed >= 3) {
                    $c1 = $candles[$i + 1] ?? null;
                    $c2 = $candles[$i + 2] ?? null;

                    if (!$c1 || $c1['time'] > $sessionEndTimestamp) {
                        return ['result' => 'pending', 'reason' => 'Waiting for C1'];
                    }
                    if ($c1['time'] >= $resolutionStartTimestamp && $c1['close'] > $c1['open']) {
                        return ['result' => 'win', 'reason' => 'Direct Win'];
                    }

                    if (!$c2 || $c2['time'] > $sessionEndTimestamp) {
                        return ['result' => 'pending', 'reason' => 'Waiting for C2'];
                    }
                    if ($c2['time'] >= $resolutionStartTimestamp && $c2['close'] > $c2['open']) {
                        return ['result' => 'win', 'reason' => 'MTG1 Win'];
                    }
                    return ['result' => 'loss', 'reason' => 'Failed'];
                }
            }

            if ($targetBroken && $consecutiveRed === 0) {
                return ['result' => 'setup_not_formed', 'reason' => 'Invalid streak'];
            }
        }

        if ($direction === 'DOWN') {
            if ($c['close'] > $targetPrice) {
                if (!$targetBroken) {
                    if ($waveLength < 3) {
                        return ['result' => 'setup_not_formed', 'reason' => 'Too fast'];
                    }
                    $targetBroken = true;
                }

                if ($consecutiveGreen >= 3) {
                    $c1 = $candles[$i + 1] ?? null;
                    $c2 = $candles[$i + 2] ?? null;

                    if (!$c1 || $c1['time'] > $sessionEndTimestamp) {
                        return ['result' => 'pending', 'reason' => 'Waiting for C1'];
                    }
                    if ($c1['time'] >= $resolutionStartTimestamp && $c1['close'] < $c1['open']) {
                        return ['result' => 'win', 'reason' => 'Direct Win'];
                    }

                    if (!$c2 || $c2['time'] > $sessionEndTimestamp) {
                        return ['result' => 'pending', 'reason' => 'Waiting for C2'];
                    }
                    if ($c2['time'] >= $resolutionStartTimestamp && $c2['close'] < $c2['open']) {
                        return ['result' => 'win', 'reason' => 'MTG1 Win'];
                    }
                    return ['result' => 'loss', 'reason' => 'Failed'];
                }
            }

            if ($targetBroken && $consecutiveGreen === 0) {
                return ['result' => 'setup_not_formed', 'reason' => 'Invalid streak'];
            }
        }
    }

    if ($targetBroken) {
        $last = $count ? $candles[$count - 1] : null;
        if ($last && $last['time'] >= $sessionEndTimestamp) {
            return ['result' => 'setup_not_formed', 'reason' => 'Invalid streak'];
        }
        return ['result' => 'pending', 'reason' => 'Waiting for setup streak'];
    }

    return ['result' => 'pending', 'reason' => 'Target not broken'];
}

/** Return date-wise historical CSV candidates. */
function historicalCsvFiles(string $pair, int $from, int $to): array {
    $p = cleanPair($pair);
    $roots = [
        __DIR__ . '/../dataset/BEFORE-JUN-2025',
        __DIR__ . '/../dataset',
        __DIR__ . '/../../dataset',
        '/home/sairedd1/public_html/dataset/BEFORE-JUN-2025',
        '/home/sairedd1/public_html/dataset'
    ];

    $files = [];
    $d = (new DateTime('@' . $from))->setTimezone(new DateTimeZone('UTC'));
    $last = (new DateTime('@' . $to))->setTimezone(new DateTimeZone('UTC'));
    $d->modify('-1 day');
    $last->modify('+1 day');

    while ($d <= $last) {
        $ymdUtc = $d->format('Y-m-d');
        $ymdIst = (clone $d)->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('Y-m-d');
        $dates = array_unique([$ymdUtc, $ymdIst]);

        foreach ($dates as $ymd) {
            $names = ["FX_{$p}-{$ymd}.csv", "{$p}-{$ymd}.csv"];
            foreach ($roots as $root) {
                foreach ($names as $name) {
                    $candidates = [
                        "$root/$name",
                        "$root/$p/$name",
                        "$root/" . strtolower($p) . "/$name"
                    ];
                    foreach ($candidates as $file) {
                        if (is_file($file)) $files[$file] = true;
                    }
                }
            }
        }
        $d->modify('+1 day');
    }

    return array_keys($files);
}

function loadCandles(string $pair, int $alertTs, int $resolutionStartTs, int $sessionEndTs): array {
    $files = historicalCsvFiles($pair, $alertTs - 3600, $sessionEndTs + 3600);
    $candles = [];

    foreach ($files as $file) {
        $h = @fopen($file, 'r');
        if (!$h) continue;

        $header = fgetcsv($h);
        if (!$header) {
            fclose($h);
            continue;
        }

        $header = array_map(function ($x) {
            return strtolower(trim(trim((string)$x, "\" \t\r\n")));
        }, $header);

        $ti = array_search('time', $header, true);
        $oi = array_search('open', $header, true);
        $ci = array_search('close', $header, true);

        if ($ti === false || $oi === false || $ci === false) {
            fclose($h);
            continue;
        }

        while (($row = fgetcsv($h)) !== false) {
            if (!isset($row[$ti], $row[$oi], $row[$ci])) continue;
            $t = parseCsvTime($row[$ti]);
            if ($t <= 0 || $t < $alertTs - 3600 || $t > $sessionEndTs + 3600) continue;

            $o = (float)$row[$oi];
            $c = (float)$row[$ci];
            if (!is_finite($o) || !is_finite($c)) continue;
            $candles[$t] = ['time' => $t, 'open' => $o, 'close' => $c];
        }
        fclose($h);
    }

    if (!$candles) return [];
    ksort($candles, SORT_NUMERIC);
    return array_values($candles);
}

function parseUtcDbTimestamp(string $value): int {
    $dt = DateTime::createFromFormat('!Y-m-d H:i:s', trim($value), new DateTimeZone('UTC'));
    if (!$dt) {
        $dt = new DateTime(trim($value), new DateTimeZone('UTC'));
    }
    return $dt->getTimestamp();
}

function istDateTimes(int $alertTs, DateTimeZone $ist): array {
    $alert = (new DateTime('@' . $alertTs))->setTimezone($ist);
    $date = $alert->format('Y-m-d');
    $resolutionStart = new DateTime($date . ' 13:00:00', $ist);
    $sessionEnd = new DateTime($date . ' 21:30:00', $ist);
    return [$alert, $resolutionStart, $sessionEnd];
}

$isCli = (PHP_SAPI === 'cli');
$start = $isCli ? ($argv[1] ?? '2020-01-01') : ($_GET['start'] ?? '2020-01-01');
$end = $isCli ? ($argv[2] ?? '2025-05-31') : ($_GET['end'] ?? '2025-05-31');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
    die("Invalid dates. Use YYYY-MM-DD.\n");
}

$fromDate = new DateTime($start . ' 00:00:00', $IST);
$toDate = new DateTime($end . ' 23:59:59', $IST);
if ($fromDate > $toDate) die("Start date must be before end date.\n");

// Convert requested IST date range to a broad UTC range, then enforce exact IST windows in PHP.
$fromSql = (clone $fromDate)->setTimezone($UTC)->format('Y-m-d H:i:s');
$toSql = (clone $toDate)->setTimezone($UTC)->format('Y-m-d H:i:s');

$lastId = 0;
$total = 0;
$deletedInvalidAlerts = 0;
$updated = 0;
$pending = 0;
$missing = 0;
$skippedDirection = 0;

while (true) {
    $sql = "SELECT raw_trade_id, pair_name, price_target, trade_direction, last_alert_time, trade_result
            FROM prediction_trade_data
            WHERE raw_trade_id > ?
              AND last_alert_time >= ?
              AND last_alert_time <= ?
            ORDER BY raw_trade_id ASC
            LIMIT {$BATCH_SIZE}";

    $stmt = $conn->prepare($sql);
    if (!$stmt) die("SELECT prepare failed: " . $conn->error . "\n");
    $stmt->bind_param('iss', $lastId, $fromSql, $toSql);
    if (!$stmt->execute()) die("SELECT execute failed: " . $stmt->error . "\n");

    $stmt->bind_result($rawId, $pair, $target, $direction, $lastAlert, $oldResult);
    $batch = [];
    while ($stmt->fetch()) {
        $batch[] = [
            'raw_trade_id' => (int)$rawId,
            'pair_name' => (string)$pair,
            'price_target' => (float)$target,
            'trade_direction' => strtoupper(trim((string)$direction)),
            'last_alert_time' => (string)$lastAlert,
            'trade_result' => (string)$oldResult
        ];
    }
    $stmt->close();

    if (!$batch) break;

    foreach ($batch as $trade) {
        $lastId = $trade['raw_trade_id'];
        $total++;

        $alertTs = parseUtcDbTimestamp($trade['last_alert_time']);
        [$alertIst, $resolutionStartIst, $sessionEndIst] = istDateTimes($alertTs, $IST);
        $alertClock = $alertIst->format('H:i:s');

        // Required alert window: 12:30 IST inclusive through 21:30 IST inclusive.
        $validAlert = ($alertClock >= '12:30:00' && $alertClock <= '21:30:00');
        if (!$validAlert) {
            $id = (int)$trade['raw_trade_id'];
            $deleteSql = "DELETE FROM prediction_trade_data WHERE raw_trade_id={$id} LIMIT 1";
            if (!$conn->query($deleteSql)) {
                die("DELETE FAILED for raw_trade_id {$id}: {$conn->error}\nSQL: {$deleteSql}\n");
            }
            $deletedInvalidAlerts++;
            echo "DELETED INVALID ALERT | {$id} | {$trade['pair_name']} | {$alertIst->format('Y-m-d H:i:s')} IST\n";
            continue;
        }

        if ($trade['trade_direction'] !== 'UP' && $trade['trade_direction'] !== 'DOWN') {
            $skippedDirection++;
            echo "SKIPPED INVALID DIRECTION | {$trade['raw_trade_id']} | {$trade['trade_direction']}\n";
            continue;
        }

        $resolutionStartTs = $resolutionStartIst->setTimezone($UTC)->getTimestamp();
        $sessionEndTs = $sessionEndIst->setTimezone($UTC)->getTimestamp();

        $candles = loadCandles($trade['pair_name'], $alertTs, $resolutionStartTs, $sessionEndTs);
        if (!$candles) {
            $missing++;
            echo "MISSING CSV | {$trade['raw_trade_id']} | {$trade['pair_name']} | {$alertIst->format('Y-m-d H:i:s')} IST\n";
            continue;
        }

        $eval = evaluatePatternTrade(
            $candles,
            $trade['trade_direction'],
            $trade['price_target'],
            $alertTs,
            $resolutionStartTs,
            $sessionEndTs
        );
        $newResult = $eval['result'];

        if ($newResult !== 'pending') {
            $safe = $conn->real_escape_string($newResult);
            $id = (int)$trade['raw_trade_id'];
            $updateSql = "UPDATE prediction_trade_data SET trade_result='{$safe}' WHERE raw_trade_id={$id} LIMIT 1";
            if (!$conn->query($updateSql)) {
                die("UPDATE FAILED for raw_trade_id {$id}: {$conn->error}\nSQL: {$updateSql}\n");
            }
            $updated++;
        } else {
            $pending++;
        }

        echo "{$trade['raw_trade_id']} | {$trade['pair_name']} | "
           . $alertIst->format('Y-m-d H:i:s') . " IST | "
           . strtoupper($newResult) . " | {$eval['reason']} | candles=" . count($candles) . "\n";
    }

    unset($batch);
}

echo "\nDONE\n";
echo "Trades scanned: {$total}\n";
echo "Invalid alert records deleted: {$deletedInvalidAlerts}\n";
echo "Updated final results: {$updated}\n";
echo "Pending: {$pending}\n";
echo "Missing CSV: {$missing}\n";
echo "Invalid directions skipped: {$skippedDirection}\n";
?>
