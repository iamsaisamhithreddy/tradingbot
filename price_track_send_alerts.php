<?php

/**
 * Safe Price Target Alert Script
 *
 * Features:
 * - Prevents overlapping executions
 * - Sends Telegram price alerts
 * - Automatically deletes sent Telegram price messages
 * - Uses HTTP timeouts
 * - Avoids repeated DB queries
 * - Avoids server overload / 503 errors
 */

declare(strict_types=1);

date_default_timezone_set('Asia/Kolkata');


/*
|--------------------------------------------------------------------------
| SINGLE INSTANCE LOCK
|--------------------------------------------------------------------------
*/

$lockPath = __DIR__ . '/price_alert_worker.lock';
$lockHandle = fopen($lockPath, 'c');

if ($lockHandle === false) {
    error_log('Price alert worker: Unable to create lock file.');
    exit;
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    // Another instance is already running.
    exit;
}

register_shutdown_function(function () use ($lockHandle): void {
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
});


/*
|--------------------------------------------------------------------------
| LOAD DATABASE
|--------------------------------------------------------------------------
*/

$dbFile = __DIR__ . '/db.php';

if (!file_exists($dbFile)) {
    error_log('Price alert worker: db.php not found at ' . $dbFile);
    exit;
}

require_once $dbFile;


/*
|--------------------------------------------------------------------------
| VALIDATE DATABASE CONNECTION
|--------------------------------------------------------------------------
*/

if (!isset($conn) || !($conn instanceof mysqli)) {
    error_log(
        'Price alert worker: Database connection variable $conn is unavailable.'
    );
    exit;
}

if ($conn->connect_errno) {
    error_log(
        'Price alert worker: Database connection failed: ' .
        $conn->connect_error
    );
    exit;
}

$conn->set_charset('utf8mb4');


/*
|--------------------------------------------------------------------------
| CONFIGURATION
|--------------------------------------------------------------------------
*/

// Telegram bot token.
// Keep your existing token configuration in db.php or another config file.
$botToken = $botToken ?? '';

if ($botToken === '') {
    error_log('Price alert worker: Telegram bot token is missing.');
    $conn->close();
    exit;
}

// Delete Telegram price messages after this many seconds.
$deleteAfterSeconds = 120;

// Maximum number of trades to inspect per execution.
$maxTradesPerRun = 100;

// Price tolerance: 0.005% around target.
$targetTolerancePercent = 0.00005;

// File paths.
$alertedTodayFile = sys_get_temp_dir() .
    '/alerted_trades_' . date('Y-m-d') . '.json';

$deleteQueueFile = __DIR__ . '/delete_queue.json';

// Maximum execution time safeguard.
@set_time_limit(25);


/*
|--------------------------------------------------------------------------
| HELPER: SAFE JSON READ
|--------------------------------------------------------------------------
*/

function readJsonFile(string $filePath, $default = [])
{
    if (!file_exists($filePath)) {
        return $default;
    }

    $contents = @file_get_contents($filePath);

    if ($contents === false || trim($contents) === '') {
        return $default;
    }

    $decoded = json_decode($contents, true);

    return is_array($decoded) ? $decoded : $default;
}


/*
|--------------------------------------------------------------------------
| HELPER: ATOMIC JSON WRITE
|--------------------------------------------------------------------------
*/

function writeJsonFileAtomic(string $filePath, array $data): bool
{
    $tempFile = $filePath . '.tmp.' . getmypid();

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        return false;
    }

    $written = @file_put_contents(
        $tempFile,
        $json,
        LOCK_EX
    );

    if ($written === false) {
        @unlink($tempFile);
        return false;
    }

    @chmod($tempFile, 0644);

    if (!@rename($tempFile, $filePath)) {
        @unlink($tempFile);
        return false;
    }

    return true;
}


/*
|--------------------------------------------------------------------------
| HELPER: GET TODAY'S ALERTED TRADE IDS
|--------------------------------------------------------------------------
*/

function getAlertedTradeIds(string $filePath): array
{
    $data = readJsonFile($filePath, []);

    $result = [];

    foreach ($data as $id) {
        $result[(string)$id] = true;
    }

    return $result;
}


/*
|--------------------------------------------------------------------------
| HELPER: SAVE TODAY'S ALERTED TRADE IDS
|--------------------------------------------------------------------------
*/

function saveAlertedTradeIds(
    string $filePath,
    array $alertedTradeIds
): void {
    $ids = array_keys($alertedTradeIds);

    writeJsonFileAtomic($filePath, $ids);
}


/*
|--------------------------------------------------------------------------
| HELPER: TELEGRAM REQUEST
|--------------------------------------------------------------------------
*/

function telegramRequest(
    string $botToken,
    string $method,
    array $postData,
    int $timeoutSeconds = 5
): ?array {
    $url = 'https://api.telegram.org/bot' .
        rawurlencode($botToken) .
        '/' .
        $method;

    $payload = http_build_query($postData);

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' =>
                "Content-Type: application/x-www-form-urlencoded\r\n" .
                "Connection: close\r\n",
            'content' => $payload,
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $response = @file_get_contents(
        $url,
        false,
        $context
    );

    if ($response === false || $response === '') {
        return null;
    }

    $decoded = json_decode($response, true);

    if (!is_array($decoded)) {
        return null;
    }

    return $decoded;
}


/*
|--------------------------------------------------------------------------
| HELPER: SEND TELEGRAM ALERT
|--------------------------------------------------------------------------
*/

function sendTelegramAlert(
    string $botToken,
    string $chatId,
    string $message
): ?int {
    $result = telegramRequest(
        $botToken,
        'sendMessage',
        [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => 'true',
        ],
        5
    );

    if (
        !$result ||
        empty($result['ok']) ||
        empty($result['result']['message_id'])
    ) {
        return null;
    }

    return (int)$result['result']['message_id'];
}


/*
|--------------------------------------------------------------------------
| HELPER: DELETE TELEGRAM MESSAGE
|--------------------------------------------------------------------------
*/

function deleteTelegramMessage(
    string $botToken,
    string $chatId,
    int $messageId
): bool {
    $result = telegramRequest(
        $botToken,
        'deleteMessage',
        [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ],
        5
    );

    return !empty($result['ok']);
}


/*
|--------------------------------------------------------------------------
| HELPER: QUEUE MESSAGE FOR DELETION
|--------------------------------------------------------------------------
*/

function queueMessageDeletion(
    string $queueFile,
    string $chatId,
    int $messageId,
    int $deleteAfterSeconds
): void {
    $queue = readJsonFile($queueFile, []);

    $queue[] = [
        'chat_id' => (string)$chatId,
        'message_id' => $messageId,
        'delete_at' => time() + $deleteAfterSeconds,
    ];

    /*
     * Keep queue from growing forever.
     */
    if (count($queue) > 1000) {
        $queue = array_slice($queue, -1000);
    }

    writeJsonFileAtomic($queueFile, $queue);
}


/*
|--------------------------------------------------------------------------
| PROCESS DUE MESSAGE DELETIONS
|--------------------------------------------------------------------------
*/

function processDeletionQueue(
    string $botToken,
    string $queueFile
): void {
    $queue = readJsonFile($queueFile, []);

    if (empty($queue)) {
        return;
    }

    $now = time();
    $remaining = [];

    foreach ($queue as $item) {
        if (
            !isset($item['chat_id']) ||
            !isset($item['message_id']) ||
            !isset($item['delete_at'])
        ) {
            continue;
        }

        $chatId = (string)$item['chat_id'];
        $messageId = (int)$item['message_id'];
        $deleteAt = (int)$item['delete_at'];

        /*
         * Not ready yet.
         */
        if ($deleteAt > $now) {
            $remaining[] = $item;
            continue;
        }

        /*
         * Delete the message.
         *
         * Even if deletion fails, do not retry forever.
         * This prevents queue growth and repeated API requests.
         */
        deleteTelegramMessage(
            $botToken,
            $chatId,
            $messageId
        );

        /*
         * Small delay between Telegram deletion calls.
         */
        usleep(100000);
    }

    writeJsonFileAtomic($queueFile, $remaining);
}


/*
|--------------------------------------------------------------------------
| GET TELEGRAM CHAT IDS ONCE
|--------------------------------------------------------------------------
*/

function getTelegramChatIds(mysqli $conn): array
{
    $chatIds = [];

    $sql = "
        SELECT DISTINCT chat_id
        FROM telegram_users
        WHERE chat_id IS NOT NULL
          AND chat_id <> ''
    ";

    $result = $conn->query($sql);

    if (!$result) {
        error_log(
            'Price alert worker: Failed to load Telegram users: ' .
            $conn->error
        );

        return [];
    }

    while ($row = $result->fetch_assoc()) {
        $chatId = trim((string)$row['chat_id']);

        if ($chatId !== '') {
            $chatIds[] = $chatId;
        }
    }

    $result->free();

    return array_values(array_unique($chatIds));
}


/*
|--------------------------------------------------------------------------
| GET TODAY'S TRADES
|--------------------------------------------------------------------------
| Compatible with servers without mysqlnd.
| Does NOT use mysqli_stmt::get_result().
|--------------------------------------------------------------------------
*/

function getTodaysTrades(
    mysqli $conn,
    int $limit
): array {
    $trades = [];

    /*
     * Keep the original timezone behavior.
     *
     * last_alert_time historical values may contain IST-based timestamps.
     */
    $sql = "
        SELECT
            raw_trade_id,
            pair_name,
            price_target,
            trade_direction
        FROM prediction_trade_data
        WHERE DATE(
            CONVERT_TZ(
                last_alert_time,
                @@session.time_zone,
                '+05:30'
            )
        ) = DATE(
            CONVERT_TZ(
                NOW(),
                @@session.time_zone,
                '+05:30'
            )
        )
        ORDER BY raw_trade_id ASC
        LIMIT ?
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        error_log(
            'Price alert worker: Failed to prepare trades query: ' .
            $conn->error
        );

        return [];
    }

    if (!$stmt->bind_param('i', $limit)) {
        error_log(
            'Price alert worker: Failed to bind trade limit: ' .
            $stmt->error
        );

        $stmt->close();
        return [];
    }

    if (!$stmt->execute()) {
        error_log(
            'Price alert worker: Failed to execute trades query: ' .
            $stmt->error
        );

        $stmt->close();
        return [];
    }

    /*
     * mysqlnd is not required here.
     */
    if (!$stmt->store_result()) {
        error_log(
            'Price alert worker: Failed to store trades result: ' .
            $stmt->error
        );

        $stmt->close();
        return [];
    }

    if ($stmt->num_rows > 0) {
        $stmt->bind_result(
            $rawTradeId,
            $pairName,
            $priceTarget,
            $tradeDirection
        );

        while ($stmt->fetch()) {
            $trades[] = [
                'raw_trade_id' => $rawTradeId,
                'pair_name' => $pairName,
                'price_target' => $priceTarget,
                'trade_direction' => $tradeDirection,
            ];
        }
    }

    $stmt->free_result();
    $stmt->close();

    return $trades;
}


/*
|--------------------------------------------------------------------------
| GET LATEST PRICES FOR ALL PAIRS IN ONE QUERY
|--------------------------------------------------------------------------
*/

function getLatestPrices(
    mysqli $conn,
    array $pairNames
): array {
    $prices = [];

    if (empty($pairNames)) {
        return $prices;
    }

    $safePairs = [];

    foreach ($pairNames as $pair) {
        $safePairs[] = "'" .
            $conn->real_escape_string($pair) .
            "'";
    }

    $inClause = implode(',', $safePairs);

    /*
     * This uses a single query instead of one query per trade.
     *
     * The NOT EXISTS condition selects the latest row per pair.
     */
    $sql = "
        SELECT
            p.pair_name,
            p.current_price,
            p.updated_at
        FROM live_price_data p
        WHERE p.pair_name IN ($inClause)
          AND NOT EXISTS (
              SELECT 1
              FROM live_price_data newer
              WHERE newer.pair_name = p.pair_name
                AND newer.updated_at > p.updated_at
          )
    ";

    $result = $conn->query($sql);

    if (!$result) {
        error_log(
            'Price alert worker: Latest prices query failed: ' .
            $conn->error
        );

        return [];
    }

    while ($row = $result->fetch_assoc()) {
        $prices[$row['pair_name']] = [
            'current_price' => (float)$row['current_price'],
            'updated_at' => $row['updated_at'],
        ];
    }

    $result->free();

    return $prices;
}


/*
|--------------------------------------------------------------------------
| CHECK UPCOMING HIGH/MEDIUM IMPACT NEWS ONCE
|--------------------------------------------------------------------------
*/

function hasUpcomingEconomicNews(mysqli $conn): bool
{
    $sql = "
        SELECT id
        FROM economic_events
        WHERE impact IN (2, 3)
          AND event_time BETWEEN
              CONVERT_TZ(
                  NOW(),
                  @@session.time_zone,
                  '+05:30'
              )
              AND
              DATE_ADD(
                  CONVERT_TZ(
                      NOW(),
                      @@session.time_zone,
                      '+05:30'
                  ),
                  INTERVAL 10 MINUTE
              )
        LIMIT 1
    ";

    $result = $conn->query($sql);

    if (!$result) {
        error_log(
            'Price alert worker: Economic news query failed: ' .
            $conn->error
        );

        /*
         * Fail safely.
         * Do not send price alerts if news state cannot be verified.
         */
        return true;
    }

    $hasNews = $result->num_rows > 0;

    $result->free();

    return $hasNews;
}


/*
|--------------------------------------------------------------------------
| BUILD TELEGRAM MESSAGE
|--------------------------------------------------------------------------
*/

function buildTelegramMessage(
    string $pair,
    string $direction,
    float $target,
    float $currentPrice,
    string $updatedAt,
    string $tradeId
): string {
    $direction = strtoupper($direction);

    $chartUrl =
        'https://cpanel.tradeedify.in/price_chart.php?pair=' .
        rawurlencode($pair);

    $message = "🎯 *PRICE TARGET ALERT*\n\n";
    $message .= "*Pair:* `" . $pair . "`\n";
    $message .= "*Direction:* `" . $direction . "`\n";
    $message .= "*Target:* `" .
        rtrim(
            rtrim(number_format($target, 8, '.', ''), '0'),
            '.'
        ) .
        "`\n";

    $message .= "*Current Price:* `" .
        rtrim(
            rtrim(number_format($currentPrice, 8, '.', ''), '0'),
            '.'
        ) .
        "`\n";

    $message .= "*Price Updated:* `" . $updatedAt . "`\n";
    $message .= "*Trade ID:* `" . $tradeId . "`\n\n";
    $message .= "[View Chart](" . $chartUrl . ")";

    return $message;
}


/*
|--------------------------------------------------------------------------
| MAIN PRICE CHECK FUNCTION
|--------------------------------------------------------------------------
*/

function checkPriceLevels(
    mysqli $conn,
    string $botToken,
    string $alertedTodayFile,
    string $deleteQueueFile,
    int $maxTradesPerRun,
    float $targetTolerancePercent,
    int $deleteAfterSeconds
): void {
    /*
     * Skip weekends.
     */
    $dayOfWeek = (int)date('N');

    if ($dayOfWeek >= 6) {
        return;
    }

    /*
     * Process old messages first.
     */
    processDeletionQueue(
        $botToken,
        $deleteQueueFile
    );

    /*
     * Load today's already alerted trades.
     */
    $alertedToday = getAlertedTradeIds($alertedTodayFile);

    /*
     * Get trades only once.
     */
    $trades = getTodaysTrades(
        $conn,
        $maxTradesPerRun
    );

    if (empty($trades)) {
        return;
    }

    /*
     * Get all pair names.
     */
    $pairNames = [];

    foreach ($trades as $trade) {
        if (!empty($trade['pair_name'])) {
            $pairNames[] = $trade['pair_name'];
        }
    }

    $pairNames = array_values(array_unique($pairNames));

    if (empty($pairNames)) {
        return;
    }

    /*
     * Get latest prices in ONE query.
     */
    $latestPrices = getLatestPrices(
        $conn,
        $pairNames
    );

    if (empty($latestPrices)) {
        return;
    }

    /*
     * Get Telegram chat IDs in ONE query.
     */
    $chatIds = getTelegramChatIds($conn);

    if (empty($chatIds)) {
        error_log('Price alert worker: No Telegram subscribers found.');
        return;
    }

    /*
     * Check economic news only ONCE.
     */
    $upcomingNews = hasUpcomingEconomicNews($conn);

    /*
     * If important news is coming, do not send alerts.
     */
    if ($upcomingNews) {
        return;
    }

    $changed = false;

    foreach ($trades as $trade) {
        $tradeId = (string)($trade['raw_trade_id'] ?? '');
        $pair = trim((string)($trade['pair_name'] ?? ''));
        $target = (float)($trade['price_target'] ?? 0);
        $direction = strtoupper(
            trim((string)($trade['trade_direction'] ?? ''))
        );

        if (
            $tradeId === '' ||
            $pair === '' ||
            $target <= 0
        ) {
            continue;
        }

        /*
         * Already alerted today.
         */
        if (isset($alertedToday[$tradeId])) {
            continue;
        }

        /*
         * No live price for this pair.
         */
        if (!isset($latestPrices[$pair])) {
            continue;
        }

        $currentPrice = (float)$latestPrices[$pair]['current_price'];
        $updatedAt = (string)$latestPrices[$pair]['updated_at'];

        if ($currentPrice <= 0) {
            continue;
        }

        /*
         * Calculate target tolerance.
         */
        $tolerance = abs($target) * $targetTolerancePercent;

        $lowerBound = $target - $tolerance;
        $upperBound = $target + $tolerance;

        /*
         * Alert only when price is inside target zone.
         */
        if (
            $currentPrice < $lowerBound ||
            $currentPrice > $upperBound
        ) {
            continue;
        }

        $message = buildTelegramMessage(
            $pair,
            $direction,
            $target,
            $currentPrice,
            $updatedAt,
            $tradeId
        );

        /*
         * Send to all subscribers.
         */
        foreach ($chatIds as $chatId) {
            $messageId = sendTelegramAlert(
                $botToken,
                $chatId,
                $message
            );

            /*
             * Queue successfully sent message for deletion.
             */
            if ($messageId !== null) {
                queueMessageDeletion(
                    $deleteQueueFile,
                    $chatId,
                    $messageId,
                    $deleteAfterSeconds
                );
            }

            /*
             * Avoid Telegram API bursts.
             */
            usleep(150000);
        }

        /*
         * Mark this trade as alerted only after processing.
         */
        $alertedToday[$tradeId] = true;
        $changed = true;

        /*
         * Save immediately.
         */
        saveAlertedTradeIds(
            $alertedTodayFile,
            $alertedToday
        );

        /*
         * Small delay between trades.
         */
        usleep(100000);
    }

    if ($changed) {
        saveAlertedTradeIds(
            $alertedTodayFile,
            $alertedToday
        );
    }
}


/*
|--------------------------------------------------------------------------
| RUN
|--------------------------------------------------------------------------
*/

try {
    checkPriceLevels(
        $conn,
        $botToken,
        $alertedTodayFile,
        $deleteQueueFile,
        $maxTradesPerRun,
        $targetTolerancePercent,
        $deleteAfterSeconds
    );
} catch (Throwable $e) {
    error_log(
        'Price alert worker fatal error: ' .
        $e->getMessage()
    );
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

exit;