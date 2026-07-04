<?php
// Set the content type to JSON, as this file ONLY outputs JSON.
header('Content-Type: application/json');

// --- NEW: Set timezone to UTC. This is CRITICAL for accurately comparing dates. ---
date_default_timezone_set('UTC');

// Check for file upload
if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == UPLOAD_ERR_OK) {
    
    $csv_file_path = $_FILES['csv_file']['tmp_name'];
    
    $candlestickData = [];
    $volumeData = [];
    $patternAlerts = [];
    
    $is_header = true;
    
    // --- NEW: Variable to track the last day we've seen ---
    $last_day = null;

    if (($handle = fopen($csv_file_path, "r")) !== FALSE) {
        
        while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
            
            if ($is_header) {
                $is_header = false;
                continue;
            }
            
            // [0]=time, [1]=open, [2]=high, [3]=low, [4]=close, [5]=Pattern Alert, [6]=Volume
            if (count($row) < 7) continue; // Skip bad rows

            $time = (int)$row[0];
            $open = (float)$row[1];
            $high = (float)$row[2];
            $low = (float)$row[3];
            $close = (float)$row[4];
            $pattern_alert = trim($row[5]);
            $volume = (float)$row[6];

            // --- NEW: Session Break Detection Logic ---
            $current_day = date('Y-m-d', $time); // Get the 'YYYY-MM-DD' of the current candle
            
            if ($last_day === null) {
                // This is the very first candle
                $last_day = $current_day;
            }
            
            // Check if the day has changed
            if ($current_day != $last_day) {
                // Add a special session break marker
                $patternAlerts[] = [
                    'time' => $time,
                    'position' => 'belowBar', // Puts it below the candle
                    'color' => 'rgba(41, 98, 255, 0.2)', // A light, transparent blue
                    'shape' => 'verticalLine', // This draws the vertical line
                    'text' => 'New Day' // Text on hover
                ];
                
                // Update the last day
                $last_day = $current_day;
            }
            // --- End of new logic ---


            // --- Add the original data ---
            $candlestickData[] = ['time' => $time, 'open' => $open, 'high' => $high, 'low' => $low, 'close' => $close];
            $color = ($close > $open) ? 'rgba(0, 150, 136, 0.8)' : 'rgba(255, 82, 82, 0.8)';
            $volumeData[] = ['time' => $time, 'value' => $volume, 'color' => $color];
            
            // This is for your 'Pattern Alert' column
            if (!empty($pattern_alert) && $pattern_alert !== '0') {
                $patternAlerts[] = [
                    'time' => $time,
                    'position' => 'aboveBar',
                    'color' => '#2196F3',
                    'shape' => 'arrowDown',
                    'text' => $pattern_alert
                ];
            }
        }
        fclose($handle);
        
        // --- This part is unchanged, it just finds the min/max dates ---
        $startDate = null;
        $endDate = null;
        if (!empty($candlestickData)) {
            $startDate = $candlestickData[0]['time']; // Get first timestamp
            $endDate = $candlestickData[count($candlestickData) - 1]['time']; // Get last timestamp
        }

        $chart_data = [
            'candlesticks' => $candlestickData,
            'volume' => $volumeData,
            'alerts' => $patternAlerts, // This array now contains BOTH your alerts and the session breaks
            'startDate' => $startDate,
            'endDate' => $endDate
        ];
        
        echo json_encode($chart_data);

    } else {
        echo json_encode(['error' => 'Could not open uploaded CSV file.']);
    }
} else {
    echo json_encode(['error' => 'No file was uploaded or an upload error occurred.']);
}
?>