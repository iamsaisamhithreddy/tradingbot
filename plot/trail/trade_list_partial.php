<?php
/**
 * Sidebar trade list partial.
 * Expects in scope: $conn (mysqli), $utcTimezone, $istTimezone (DateTimeZone).
 * Echoes the list of <div class="trade-item"> rows for the last 100
 * win/loss trades, or a placeholder message if none are found.
 */

$tradeSql = "SELECT p.raw_trade_id, p.pair_name, p.trade_direction, p.last_alert_time,
                    o.trade_result, o.win_loss_time
             FROM prediction_trade_data p
             INNER JOIN trade_outcome_details o ON p.raw_trade_id = o.raw_trade_id
             WHERE o.trade_result IN ('win', 'loss')
             ORDER BY p.raw_trade_id DESC";
$tradeResult = $conn->query($tradeSql);

if ($tradeResult && $tradeResult->num_rows > 0) {
    while ($row = $tradeResult->fetch_assoc()) {
        $direction = strtoupper($row['trade_direction']);
        $dirLabel = ($direction === 'UP') ? 'BUY' : 'SELL';
        $dirClass = ($direction === 'UP') ? 'direction-up' : 'direction-down';
        $istAlertTime = (new DateTime($row['last_alert_time'], $utcTimezone))->setTimezone($istTimezone)->format('d M H:i');

        // win_loss_time is stored as IST DATETIME in trade_outcome_details
        $wltIST = '';
        if (!empty($row['win_loss_time'])) {
            try {
                $wltIST = (new DateTime($row['win_loss_time'], $istTimezone))->format('H:i');
            } catch (Exception $e) {
                $wltIST = '';
            }
        }

        $resultLabel = '';
        $resultStyle = '';
        $dataResult = 'pending'; // used by the new Wins/Losses dropdown filter in trade-plotter.js
        if (!empty($row['trade_result'])) {
            $res = strtolower($row['trade_result']);
            $dataResult = $res;
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

        echo "<div class='trade-item' id='trade-{$row['raw_trade_id']}' data-result='{$dataResult}' data-wlt='{$wltIST}' data-candle-count=''>
                <div class='trade-item-header'>
                    <input type='checkbox' class='trade-select' value='{$row['raw_trade_id']}' onclick='event.stopPropagation(); updateSelectedCount();' checked>
                    <span class='trade-pair' onclick='loadTrade({$row['raw_trade_id']})'>{$row['pair_name']}</span>
                    <span class='trade-direction {$dirClass}' onclick='loadTrade({$row['raw_trade_id']})'>{$dirLabel}</span>
                    <span class='trade-candle-badge' id='candle-badge-{$row['raw_trade_id']}' style='display:none;'></span>
                </div>
                <div class='trade-time' onclick='loadTrade({$row['raw_trade_id']})'>ID: #{$row['raw_trade_id']} | {$istAlertTime} IST <span style='{$resultStyle}'>{$resultLabel}</span></div>
              </div>";
    }
} else {
    echo "<div style='color: var(--text-secondary); text-align: center; padding: 20px;'>No trade data found in database.</div>";
}