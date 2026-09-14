<?php
/**
 * pdf_broadcast.php
 * ---------------------------------------------------
 * FPDF-based multipage trade report + Telegram broadcast delivery.
 *
 * Depends on globals from bootstrap.php: $conn, $istTimezone, $utcTimezone, $isCli
 * Depends on chart_generator.php: getNearbyEconomicEvents(), pickHighestImpactEvent()
 * Depends on bootstrap.php JSON helpers: getJsonDb(), saveJsonDb()
 * Expects $botToken to be defined in db.php for Telegram delivery.
 */

/**
 * Locate fpdf.php by walking up parent directories from __DIR__.
 * Returns '' if not found.
 */
function findFpdfLibrary(): string {
    $searchDir = __DIR__;
    while (true) {
        if (file_exists($searchDir . '/fpdf.php')) {
            return $searchDir . '/fpdf.php';
        }
        $parentDir = dirname($searchDir);
        if ($parentDir === $searchDir) {
            break; // Reached filesystem root without finding it
        }
        $searchDir = $parentDir;
    }
    return '';
}

// FPDF must be loaded BEFORE the class below is declared, since PHP
// evaluates class declarations as soon as this file is require_once'd
// (i.e. at trade_router.php's require time, not when generatePdfReport()
// is later called). die() here matches the original script's behavior
// when the library is missing, just moved earlier.
$__fpdfPath = findFpdfLibrary();
if ($__fpdfPath) {
    require_once $__fpdfPath;
}

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

/**
 * Builds and delivers the multipage trade outcomes PDF report.
 *
 * Reads trade IDs from $GLOBALS['pdf_trade_ids'] (CLI cron path) or
 * $_SESSION['pdf_trade_ids'] (browser AJAX path), exactly as the
 * original monolithic script did.
 *
 * If $_GET['broadcast'] == '1': sends the PDF to all Telegram subscribers,
 * marks trades as broadcasted in the JSON db, and exits with a JSON response.
 * Otherwise: streams the PDF inline to the browser and exits.
 */
function generatePdfReport() {
    global $conn, $istTimezone, $utcTimezone, $isCli;

    if ($isCli) {
        echo "Starting PDF generation process...\n";
    }

    ob_clean();
    ob_start();

    $tradeIds = $GLOBALS['pdf_trade_ids'] ?? $_SESSION['pdf_trade_ids'] ?? [];

    if (empty($tradeIds)) {
        if ($isCli) echo "ERROR: No trade IDs selected for PDF generation.\n";
        if (isset($_GET['broadcast']) && $_GET['broadcast'] == '1') {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'No trade IDs selected for PDF generation. Your session may have expired.']);
            exit;
        }
        die("Error: No trade IDs selected for PDF generation. Please select trades in the sidebar first.");
    }

    global $__fpdfPath;

    if (!$__fpdfPath) {
        if ($isCli) echo "ERROR: FPDF library not found on server.\n";
        if (isset($_GET['broadcast']) && $_GET['broadcast'] == '1') {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'FPDF library not found on server.']);
            exit;
        }
        die("Error: FPDF library not found on server.");
    }
    if ($isCli) {
        echo "Loading FPDF library from: $__fpdfPath\n";
    }
    // Already require_once'd at the top of this file (before the
    // TradeReportPDF class declaration), so nothing further to load here.

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
            $pdf->Rect(15, 30, 180, 50, 'F');

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

            // Economic news within +/-15 minutes of the win/loss resolution
            $winLossTimestampForPdf = null;
            if (!empty($trade['win_loss_time'])) {
                try {
                    $winLossTimestampForPdf = (new DateTime($trade['win_loss_time'], $istTimezone))->getTimestamp();
                } catch (Exception $e) {}
            }
            $nearbyNewsPdf = $winLossTimestampForPdf !== null
                ? getNearbyEconomicEvents($conn, $istTimezone, $winLossTimestampForPdf, 15)
                : [];
            $topNewsPdf = pickHighestImpactEvent($nearbyNewsPdf);

            $pdf->SetX(20);
            $pdf->SetFont('Arial', 'I', 8);
            $pdf->SetTextColor(168, 85, 247); // #a855f7 violet, matches chart news markers
            if ($topNewsPdf !== null) {
                $t = substr($topNewsPdf['event_time_ist'], 11, 5); // HH:MM portion of the IST datetime
                $newsLine = 'Highest-impact news (+/-15m): ' . $topNewsPdf['event_name'] . ' [Impact ' . $topNewsPdf['impact'] . '] (' . $t . ' IST)';
                $pdf->Cell(170, 4, $newsLine, 0, 1);
            } else {
                $pdf->Cell(170, 4, 'News (+/-15m): None', 0, 1);
            }
        }

        // Draw screenshot image
        $pdf->Image($imgPath, 15, 84, 180, 100);

        // Delete server file to free space
        @unlink($imgPath);
    }

    if ($pagesCount === 0) {
        if ($isCli) {
            echo "ERROR: Cannot compile PDF. 0 pages added because all trade images are missing.\n";
        }
        if (isset($_GET['broadcast']) && $_GET['broadcast'] == '1') {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'No trade chart images were found to compile the PDF. Ensure charts generated correctly.']);
            exit;
        }
        die("Error: No trade chart images were found to compile the PDF. Check if GD is enabled and files are writable.");
    }

    if ($isCli) {
        echo "PDF compiled successfully with $pagesCount page(s).\n";
    }

    if (isset($_GET['broadcast']) && $_GET['broadcast'] == '1') {
        broadcastPdfReportToTelegram($pdf, $tradeIds, $totalTrades, $totalWins, $totalLosses, $winRate, $bestPair);
        // broadcastPdfReportToTelegram() always exits.
    } else {
        $pdf->Output('I', 'Trade_Outcomes_Report_' . date('Ymd_His') . '.pdf');
        exit;
    }
}

/**
 * Saves the compiled PDF to disk, sends it to every subscribed Telegram
 * chat_id, marks the trades as broadcasted in the JSON db, and exits with
 * a JSON summary. Mirrors the original inline broadcast block exactly.
 */
function broadcastPdfReportToTelegram($pdf, array $tradeIds, $totalTrades, $totalWins, $totalLosses, $winRate, $bestPair) {
    global $conn, $isCli, $botToken;

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
}
