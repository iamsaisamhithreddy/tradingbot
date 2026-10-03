<?php

/**
 * manual_news.php
 *
 * Web-based importer for economic calendar events into `economic_events`:
 *   id (auto), event_name, impact, event_time (DATETIME), sent_status, event_date (DATE)
 *
 * sent_status is FORCED to 1 for every inserted row, so your Telegram bot
 * (which presumably only sends rows where sent_status = 0) will NOT spam
 * old/backfilled events.
 *
 * TIMEZONE NOTE
 * -------------
 * Your source JSON times (e.g. "6:30pm") are already in IST, and your
 * `economic_events` table also stores times in IST (matches your existing
 * rows, e.g. "RBA Gov Bullock Speaks" at 17:45 IST lines up with the RBA's
 * actual ~12:15 AEST speech time = 17:45 IST). So NO timezone shifting is
 * applied here - we only convert the 12-hour "6:30pm" style string into a
 * correct 24-hour "18:30:00" string. If you ever find your source JSON is
 * NOT in IST, tell me the source timezone and I'll add a conversion step.
 *
 * -------------------------------------------------------------------
 * ASSUMPTION ABOUT db.php
 * -------------------------------------------------------------------
 * This script assumes db.php defines a mysqli connection in $conn, e.g.:
 *   $conn = new mysqli('localhost', 'user', 'pass', 'your_db');
 * If yours differs, edit the "DB CONNECTION" section below.
 * -------------------------------------------------------------------
 *
 * USAGE:
 *   Visit manual_news.php in your browser, paste the JSON (multiple
 *   concatenated JSON arrays are fine - it auto-fixes that), set the
 *   year, and click Import.
 */

// ---------------------------------------------------------------
// CONFIG
// ---------------------------------------------------------------
$defaultYear = 2026;
$forcedSentStatus = 1; // per your request - never 0, so nothing gets pushed to Telegram

// ---------------------------------------------------------------
// DB CONNECTION
// ---------------------------------------------------------------
require __DIR__ . '/db.php'; // must provide $conn as a mysqli connection

$dbError = null;
if (!isset($conn) || !($conn instanceof mysqli)) {
    $dbError = "db.php did not provide a mysqli connection in \$conn. "
        . "Rename your variable to \$conn in db.php, or edit the 'DB CONNECTION' "
        . "section of this script to match your setup.";
}

// ---------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------

/**
 * Converts a source date like "Mon Aug 11" + a given year into "2026-08-11".
 */
/**
 * Converts a source date like "Mon Apr 6" or "Apr 5" + a given year into "2026-04-06".
 */
function parseEventDate(string $dateStr, int $year): ?string
{
    $dateStr = trim($dateStr);
    
    // Try format with weekday first (e.g., "Mon Apr 6 2026")
    $dt = DateTime::createFromFormat('D M j Y', $dateStr . ' ' . $year);
    
    // If that fails, try format without weekday (e.g., "Apr 5 2026")
    if ($dt === false) {
        $dt = DateTime::createFromFormat('M j Y', $dateStr . ' ' . $year);
    }
    
    if ($dt === false) {
        return null;
    }
    
    return $dt->format('Y-m-d');
}

/**
 * Converts a source time like "6:30pm" / "11:26am" into 24-hour "18:30:00".
 * Non-clock values like "All Day", "N/A", "Day 1/2/3" default to 00:00:00.
 * No timezone shift is applied - source and DB are both IST.
 */
function parseEventTime(string $timeStr): string
{
    $timeStr = strtolower(trim($timeStr));

    if ($timeStr === 'n/a' || $timeStr === 'all day' || substr($timeStr, 0, 4) === 'day ') {
        return '00:00:00';
    }

    // Normalize things like "6:30 pm" -> "6:30pm" so the format matches
    $normalized = preg_replace('/\s+/', '', $timeStr);

    $dt = DateTime::createFromFormat('g:ia', $normalized);
    if ($dt !== false) {
        return $dt->format('H:i:s');
    }

    return '00:00:00';
}

/**
 * Normalizes the "multiple concatenated JSON arrays" input into a flat
 * list of event objects.
 */
function parseEventsJson(string $raw): array
{
    $fixed = trim($raw);
    $fixed = preg_replace('/\]\s*\[/', '],[', $fixed);
    $fixed = '[' . $fixed . ']';

    $decoded = json_decode($fixed, true);
    if ($decoded === null) {
        throw new RuntimeException('Failed to parse JSON: ' . json_last_error_msg());
    }

    $events = [];
    foreach ($decoded as $block) {
        if (is_array($block)) {
            foreach ($block as $event) {
                $events[] = $event;
            }
        }
    }

    // De-duplicate events that repeat at block boundaries
    $seen = [];
    $unique = [];
    foreach ($events as $e) {
        $key = ($e['date'] ?? '') . '|' . ($e['time'] ?? '') . '|' . ($e['event_name'] ?? '');
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $unique[] = $e;
        }
    }

    return $unique;
}

// ---------------------------------------------------------------
// Handle form submission
// ---------------------------------------------------------------
$result = null;
$rows = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$dbError) {
    $jsonInput = $_POST['json_input'] ?? '';
    $year = isset($_POST['year']) ? (int)$_POST['year'] : $defaultYear;

    if (trim($jsonInput) === '') {
        $result = ['error' => 'Please paste some JSON.'];
    } else {
        try {
            $events = parseEventsJson($jsonInput);

            $stmt = $conn->prepare(
                "INSERT INTO economic_events (event_name, impact, event_time, sent_status, event_date)
                 VALUES (?, ?, ?, ?, ?)"
            );

            if ($stmt === false) {
                throw new RuntimeException('Failed to prepare statement: ' . $conn->error);
            }

            $inserted = 0;
            $skipped = 0;

            foreach ($events as $e) {
                $eventName = $e['event_name'] ?? null;
                $impact = isset($e['impact']) ? (int)$e['impact'] : 0;
                $dateStr = $e['date'] ?? null;
                $timeStr = $e['time'] ?? null;

                if (!$eventName || !$dateStr || $timeStr === null) {
                    $rows[] = ['status' => 'skipped', 'name' => $eventName ?? '(unknown)', 'reason' => 'missing fields'];
                    $skipped++;
                    continue;
                }

                $eventDate = parseEventDate($dateStr, $year);
                if ($eventDate === null) {
                    $rows[] = ['status' => 'skipped', 'name' => $eventName, 'reason' => "bad date '$dateStr'"];
                    $skipped++;
                    continue;
                }

                $timeOnly = parseEventTime($timeStr);
                $eventDateTime = $eventDate . ' ' . $timeOnly;

                $stmt->bind_param(
                    'sisis',
                    $eventName,
                    $impact,
                    $eventDateTime,
                    $forcedSentStatus,
                    $eventDate
                );

                if ($stmt->execute()) {
                    $inserted++;
                    $rows[] = ['status' => 'inserted', 'name' => $eventName, 'reason' => $eventDateTime . " (impact $impact)"];
                } else {
                    $skipped++;
                    $rows[] = ['status' => 'error', 'name' => $eventName, 'reason' => $stmt->error];
                }
            }

            $stmt->close();

            $result = [
                'inserted' => $inserted,
                'skipped' => $skipped,
                'total' => count($events),
            ];
        } catch (Throwable $ex) {
            $result = ['error' => $ex->getMessage()];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Import Economic Events</title>
<style>
    body { font-family: Arial, sans-serif; max-width: 900px; margin: 30px auto; padding: 0 15px; }
    textarea { width: 100%; height: 260px; font-family: monospace; font-size: 13px; }
    input[type=number] { width: 100px; }
    button { padding: 8px 20px; font-size: 15px; margin-top: 10px; cursor: pointer; }
    .summary { padding: 12px; margin: 15px 0; border-radius: 6px; }
    .ok { background: #e6f7e6; border: 1px solid #7bc77b; }
    .err { background: #fdecea; border: 1px solid #f5a8a0; }
    table { border-collapse: collapse; width: 100%; margin-top: 15px; font-size: 13px; }
    th, td { border: 1px solid #ccc; padding: 5px 8px; text-align: left; }
    th { background: #f0f0f0; }
    .inserted { color: #227722; }
    .skipped, .error { color: #aa2222; }
</style>
</head>
<body>
<h2>Import Economic Events into <code>economic_events</code></h2>

<?php if ($dbError): ?>
    <div class="summary err"><strong>DB connection error:</strong> <?= htmlspecialchars($dbError) ?></div>
<?php endif; ?>

<p>Paste your JSON below (multiple concatenated <code>[...]</code> blocks are fine, they'll be merged automatically).
All imported rows are saved with <code>sent_status = 1</code> so your Telegram bot won't re-send them.
Times are read as IST (e.g. <code>6:30pm</code>) and stored as 24-hour IST (<code>18:30:00</code>) - no timezone shift, since your DB is already IST.</p>

<form method="POST">
    <label>Year for these events: </label>
    <input type="number" name="year" value="<?= htmlspecialchars($_POST['year'] ?? $defaultYear) ?>"><br><br>
    <textarea name="json_input" placeholder="Paste your JSON here..."><?= isset($_POST['json_input']) ? htmlspecialchars($_POST['json_input']) : '' ?></textarea><br>
    <button type="submit">Import</button>
</form>

<?php if ($result): ?>
    <?php if (isset($result['error'])): ?>
        <div class="summary err"><strong>Error:</strong> <?= htmlspecialchars($result['error']) ?></div>
    <?php else: ?>
        <div class="summary ok">
            <strong>Done.</strong>
            Parsed <?= (int)$result['total'] ?> unique events -
            Inserted: <?= (int)$result['inserted'] ?>,
            Skipped: <?= (int)$result['skipped'] ?>.
            All inserted rows have <code>sent_status = 1</code>.
        </div>
        <table>
            <tr><th>Status</th><th>Event</th><th>Detail</th></tr>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="<?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></td>
                    <td><?= htmlspecialchars($r['name']) ?></td>
                    <td><?= htmlspecialchars($r['reason']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
<?php endif; ?>

</body>
</html>