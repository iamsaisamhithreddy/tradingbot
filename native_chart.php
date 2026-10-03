<?php

function drawChart($csvPath, $outputPath) {
    // 1. Validate File Exists
    if (!file_exists($csvPath)) {
        return "CSV file not found on server.";
    }

    // 2. Read CSV Data
    $lines = array_map('str_getcsv', file($csvPath));
    if (!$lines || count($lines) < 2) {
        return "CSV file is empty or invalid.";
    }

    $header = array_shift($lines);
    
    // Map columns (handles Case Sensitivity)
    $colMap = [];
    foreach($header as $i => $col) $colMap[strtolower(trim($col))] = $i;
    
    // Check required columns
    if (!isset($colMap['open']) || !isset($colMap['close'])) {
        return "CSV missing 'open' or 'close' columns.";
    }

    // --- ZOOM SETTINGS: Last 50 candles ---
    $zoom = 50; 
    $total = count($lines);
    $data = ($total > $zoom) ? array_slice($lines, -$zoom) : $lines;
    
    // Setup Image
    $w = 1200; 
    $h = 800;
    $margin = 50;
    
    // Check if GD Library is enabled
    if (!function_exists('imagecreatetruecolor')) {
        return "GD Library is not enabled on this server.";
    }

    $img = imagecreatetruecolor($w, $h);
    
    // Colors
    $white = imagecolorallocate($img, 255, 255, 255);
    $text  = imagecolorallocate($img, 50, 50, 50);
    $grid  = imagecolorallocate($img, 230, 230, 230);
    $green = imagecolorallocate($img, 8, 153, 129); // Bullish
    $red   = imagecolorallocate($img, 242, 54, 69); // Bearish

    imagefill($img, 0, 0, $white);

    // 4. Find Min/Max Prices for Scaling
    $min = 999999999; 
    $max = -999999999;
    $candles = [];
    
    foreach ($data as $row) {
        // Skip malformed rows
        if(!isset($row[$colMap['close']])) continue;
        
        $o = (float)$row[$colMap['open']];
        $h = (float)$row[$colMap['high']];
        $l = (float)$row[$colMap['low']];
        $c = (float)$row[$colMap['close']];
        
        if ($l < $min) $min = $l;
        if ($h > $max) $max = $h;
        
        $candles[] = ['o'=>$o, 'h'=>$h, 'l'=>$l, 'c'=>$c];
    }
    
    if (empty($candles)) return "No valid candle data found.";

    // Add padding (5%)
    $pad = ($max - $min) * 0.05;
    if ($pad == 0) $pad = 1; // Prevent division by zero
    $max += $pad; 
    $min -= $pad;
    $range = $max - $min;

    // Draw Grid
    $plotH = $h - ($margin * 2);
    $plotW = $w - ($margin * 2);
    
    for($i=0; $i<=5; $i++) {
        $y = $margin + $plotH - ($i * ($plotH/5));
        imageline($img, $margin, $y, $w-$margin, $y, $grid);
        $lbl = number_format($min + ($i*($range/5)), 2);
        imagestring($img, 5, $w-$margin+5, $y-7, $lbl, $text);
    }

    // Draw Candles
    $cnt = count($candles);
    $barW = ($plotW / $cnt) * 0.7; 
    $spacing = $plotW / $cnt;
    
    foreach ($candles as $i => $c) {
        $cx = $margin + ($i * $spacing) + ($spacing/2);
        
        // Convert prices to Y pixels
        // Formula: TopMargin + PlotHeight - (NormalizedValue * PlotHeight)
        $yO = $margin + $plotH - (($c['o'] - $min) / $range * $plotH);
        $yC = $margin + $plotH - (($c['c'] - $min) / $range * $plotH);
        $yH = $margin + $plotH - (($c['h'] - $min) / $range * $plotH);
        $yL = $margin + $plotH - (($c['l'] - $min) / $range * $plotH);
        
        $color = ($c['c'] >= $c['o']) ? $green : $red;
        
        // Draw Wick
        imageline($img, $cx, $yH, $cx, $yL, $color);
        
        // Draw Body (Ensure Y1 < Y2 for GD)
        imagefilledrectangle($img, 
            $cx - ($barW/2), min($yO, $yC), 
            $cx + ($barW/2), max($yO, $yC), 
            $color
        );
    }

    //Save Image
    imagejpeg($img, $outputPath, 90);
    imagedestroy($img);
    return true; // Success
}
?>