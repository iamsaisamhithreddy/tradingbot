<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db.php';

// Verify the connection
if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed. Please check db.php.");
}

// URL for the Forex Factory weekly calendar XML
$url = "https://nfs.faireconomy.media/ff_calendar_thisweek.xml";

// 2. Initialize cURL to fetch the XML
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) PHP Calendar Fetcher');

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

$events = [];
$errorMessage = '';

// 3. Process XML and Database logic
if ($httpCode === 200 && $response) {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($response);
    
    if ($xml !== false) {
        foreach ($xml->event as $event) {
            $dateStr = (string)$event->date;
            $timeStr = (string)$event->time;
            $title   = (string)$event->title;
            $impactStr = (string)$event->impact;
            $country = (string)$event->country;
            
            // --- NEW MAPPING LOGIC ---
            // Map the impact string to a matching integer format for database requirements
            $impactNum = 0; // Default or 'None' / 'Holiday'
            $impactLower = strtolower($impactStr);
            if (strpos($impactLower, 'low') !== false) {
                $impactNum = 1;
            } elseif (strpos($impactLower, 'medium') !== false) {
                $impactNum = 2;
            } elseif (strpos($impactLower, 'high') !== false) {
                $impactNum = 3;
            }
            // -------------------------
            
            $eventTimeForDB = null;
            $displayDate = $dateStr;
            $displayTime = $timeStr;
            
            // 4. Adjust timezone (+5:30 IST) and format for MySQL DATETIME
            if (preg_match('/[0-9]+:[0-9]+[a-zA-Z]{2}/', $timeStr)) {
                // It's a standard time (e.g., 8:30am)
                $dateTimeStr = $dateStr . ' ' . $timeStr;
                $dt = DateTime::createFromFormat('m-d-Y g:ia', $dateTimeStr);
                
                if ($dt !== false) {
                    $dt->modify('+5 hours 30 minutes');
                    
                    // Format for MySQL DATETIME column (YYYY-MM-DD HH:MM:SS)
                    $eventTimeForDB = $dt->format('Y-m-d H:i:s');
                    
                    // Format for HTML display
                    $displayDate = $dt->format('m-d-Y');
                    $displayTime = $dt->format('g:ia');
                }
            } else {
                // It's "All Day" or "Tentative", so we default the time to 00:00:00 for the DB
                $dt = DateTime::createFromFormat('m-d-Y', $dateStr);
                if ($dt !== false) {
                    $eventTimeForDB = $dt->format('Y-m-d 00:00:00');
                    $displayDate = $dt->format('m-d-Y');
                }
            }

            $dbStatus = "";

            // 5. Database Insertion/Update Logic
            if ($eventTimeForDB !== null) {
                // Using ON DUPLICATE KEY UPDATE to skip/update values dynamically without get_result() crashes
                $stmt = $conn->prepare("
                    INSERT INTO economic_events (event_name, impact, event_time) 
                    VALUES (?, ?, ?) 
                    ON DUPLICATE KEY UPDATE impact = VALUES(impact)
                ");
                
                // Note: using 'sis' configuration here because $impactNum is processed as an integer type bound variable
                $stmt->bind_param("sis", $title, $impactNum, $eventTimeForDB);
                $stmt->execute();
                
                // Check MySQL affected rows to determine what happened:
                // 1 = Inserted new row
                // 2 = Updated existing row's impact
                // 0 = Skipped (Duplicate with no changes)
                $affected = $stmt->affected_rows;
                $stmt->close();

                if ($affected === 1) {
                    $dbStatus = "Inserted";
                } elseif ($affected === 2) {
                    $dbStatus = "Updated";
                } else {
                    $dbStatus = "Skipped (Duplicate)";
                }
            } else {
                $dbStatus = "Error parsing time";
            }

            // Save to array for HTML display (Keeping structural text tags readable for frontend layout)
            $events[] = [
                'title'    => $title,
                'country'  => $country,
                'date'     => $displayDate,
                'time'     => $displayTime,
                'impact'   => $impactStr,
                'db_status'=> $dbStatus
            ];
        }
    } else {
        $errorMessage = "Failed to parse the XML response.";
    }
} else {
    $errorMessage = "Failed to fetch data from the server. HTTP Status Code: $httpCode. $curlError";
}

// Close DB connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Economic Calendar Cron Sync</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #f4f7f6;
            color: #333;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        h1 {
            text-align: center;
            color: #2c3e50;
        }
        .error {
            background-color: #fee2e2;
            color: #b91c1c;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 14px;
        }
        th, td {
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        th {
            background-color: #f8fafc;
            font-weight: 600;
            color: #475569;
        }
        tr:hover {
            background-color: #f1f5f9;
        }
        .impact-high { color: #dc2626; font-weight: bold; }
        .impact-medium { color: #d97706; font-weight: bold; }
        .impact-low { color: #16a34a; font-weight: bold; }
        .impact-none { color: #94a3b8; }
        
        .status-inserted { color: #16a34a; font-weight: 500; }
        .status-updated { color: #2563eb; font-weight: 500; }
        .status-skipped { color: #94a3b8; font-style: italic; }
    </style>
</head>
<body>

<div class="container">
    <h1>Cron Sync Complete: Economic Calendar (IST Adjusted)</h1>

    <?php if ($errorMessage): ?>
        <div class="error">
            <strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($events)): ?>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Currency</th>
                    <th>Impact</th>
                    <th>Event</th>
                    <th>Database Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($events as $event): ?>
                    <?php 
                        // Color code impact based on textual reference
                        $impactClass = 'impact-none';
                        $impactLower = strtolower($event['impact']);
                        if (strpos($impactLower, 'high') !== false) $impactClass = 'impact-high';
                        elseif (strpos($impactLower, 'medium') !== false) $impactClass = 'impact-medium';
                        elseif (strpos($impactLower, 'low') !== false) $impactClass = 'impact-low';

                        // Color code database status
                        $statusClass = 'status-skipped';
                        if ($event['db_status'] === 'Inserted') $statusClass = 'status-inserted';
                        elseif ($event['db_status'] === 'Updated') $statusClass = 'status-updated';
                    ?>
                    <tr>
                        <td style="white-space: nowrap;"><?php echo htmlspecialchars($event['date']); ?></td>
                        <td style="white-space: nowrap;"><?php echo htmlspecialchars($event['time']); ?></td>
                        <td><strong><?php echo htmlspecialchars($event['country']); ?></strong></td>
                        <td class="<?php echo $impactClass; ?>"><?php echo htmlspecialchars($event['impact']); ?></td>
                        <td><?php echo htmlspecialchars($event['title']); ?></td>
                        <td class="<?php echo $statusClass; ?>"><?php echo htmlspecialchars($event['db_status']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php elseif (!$errorMessage): ?>
        <p>No events found for this week.</p>
    <?php endif; ?>
</div>

</body>
</html>