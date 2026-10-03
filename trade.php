<?php
session_start();


$payout = 0.75; // 75% return
$base_trade = 100; // starting lot
$daily_loss_limit_percent = 10; // stop trading after 10% loss (can change)
$desired_profit = 50; // recovery target per loss

date_default_timezone_set('Asia/Kolkata');

// ---- INITIALIZE ----
if (!isset($_SESSION['balance'])) $_SESSION['balance'] = 2000;
if (!isset($_SESSION['start_balance'])) $_SESSION['start_balance'] = $_SESSION['balance'];
if (!isset($_SESSION['current_trade'])) $_SESSION['current_trade'] = $base_trade;
if (!isset($_SESSION['step'])) $_SESSION['step'] = 1;
if (!isset($_SESSION['max_steps'])) $_SESSION['max_steps'] = 2;
if (!isset($_SESSION['log'])) $_SESSION['log'] = [];
if (!isset($_SESSION['martingale'])) $_SESSION['martingale'] = false;
if (!isset($_SESSION['trading_locked'])) $_SESSION['trading_locked'] = false;

// ---- HANDLE FORM ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $time = date('d M Y, h:i:s A');

    // Update settings
    if (isset($_POST['set_steps'])) {
        $_SESSION['max_steps'] = (int)$_POST['steps'];
        $_SESSION['balance'] = (float)$_POST['balance'];
        $_SESSION['start_balance'] = $_SESSION['balance'];
        $_SESSION['current_trade'] = $base_trade;
        $_SESSION['step'] = 1;
        $_SESSION['log'] = [];
        $_SESSION['trading_locked'] = false;
        $msg = "✅ Settings updated successfully!";
    }

    // Toggle Martingale
    if (isset($_POST['toggle_martingale'])) {
        $_SESSION['martingale'] = !$_SESSION['martingale'];
        $state = $_SESSION['martingale'] ? "ON" : "OFF";
        $msg = "⚙️ Martingale Mode turned <b>$state</b>";
    }

    // Handle trade result
    if (isset($_POST['result']) && !$_SESSION['trading_locked']) {
        $result = $_POST['result'];
        $trade = $_SESSION['current_trade'];
        $pnl = 0;

        if ($result === 'win') {
            $pnl = $trade * $payout;
            $_SESSION['balance'] += $pnl;
            $msg = "🎯 Win! Added ₹" . number_format($pnl, 2);

            if ($_SESSION['step'] < $_SESSION['max_steps']) {
                $_SESSION['step']++;
                $_SESSION['current_trade'] = $trade * (1 + $payout);
            } else {
                $_SESSION['step'] = 1;
                $_SESSION['current_trade'] = $base_trade;
            }
        }

        if ($result === 'lose') {
            $pnl = -$trade;
            $_SESSION['balance'] += $pnl;
            $_SESSION['step'] = 1;
            $_SESSION['current_trade'] = $base_trade;

            $msg = "❌ Loss. Restarting from ₹{$base_trade}.";
        }

        $_SESSION['log'][] = [
            'time' => $time,
            'trade' => $trade,
            'result' => strtoupper($result),
            'pnl' => $pnl,
            'balance' => $_SESSION['balance']
        ];

        // ---- AUTO LOCK LOGIC ----
        $loss_limit = $_SESSION['start_balance'] * ($daily_loss_limit_percent / 100);
        $daily_change = $_SESSION['balance'] - $_SESSION['start_balance'];

        if ($daily_change <= -$loss_limit) {
            $_SESSION['trading_locked'] = true;
            $msg = "⛔ Trading locked! You hit daily loss limit (" . $daily_loss_limit_percent . "%). Reset tomorrow or manually.";
        }
    }

    // Reset all
    if (isset($_POST['reset'])) {
        session_destroy();
        header("Location: ".$_SERVER['PHP_SELF']);
        exit;
    }
}

// ---- RECOVERY SUGGESTION ----
$recovery_suggestion = null;
if (!empty($_SESSION['log'])) {
    $logs = $_SESSION['log'];
    $last = end($logs);
    if ($last['result'] === 'LOSE') {
        $last_loss = abs($last['pnl']);
        $actual_loss = $last_loss;
        if (count($logs) > 1) {
            $prev = $logs[count($logs) - 2];
            if ($prev['result'] === 'WIN') $actual_loss = $prev['trade'];
        }
        $next_trade = ($actual_loss + $desired_profit) / $payout;
        $recovery_suggestion = [
            'loss' => $actual_loss,
            'goal' => $desired_profit,
            'next' => round($next_trade, 2)
        ];
    }
}

// ---- RISK CALCS ----
$risk_amount = $_SESSION['current_trade'];
$risk_percent = ($_SESSION['balance'] > 0) ? round(($risk_amount / $_SESSION['balance']) * 100, 2) : 0;
$daily_change = $_SESSION['balance'] - $_SESSION['start_balance'];
$daily_percent = round(($daily_change / $_SESSION['start_balance']) * 100, 2);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Smart Quotex Risk Manager</title>
<style>
body {font-family: 'Segoe UI', sans-serif;background: #0f172a;color: white;text-align: center;padding: 30px;}
h1 {color: #38bdf8;}
.container {background: #1e293b;padding: 20px;border-radius: 15px;max-width: 550px;margin: auto;box-shadow: 0 0 20px rgba(56,189,248,0.3);}
button {background: #38bdf8;border: none;padding: 10px 20px;color: black;font-weight: bold;border-radius: 10px;margin: 8px;cursor: pointer;}
button:hover {background: #0ea5e9;color: white;}
input {padding: 8px;border-radius: 8px;border: none;margin: 5px;text-align: center;}
.msg {background: #334155;padding: 10px;border-radius: 8px;margin-top: 10px;}
.risk {color: #fbbf24;font-weight: bold;}
.locked {background:#ef4444;color:white;padding:10px;border-radius:10px;margin-top:10px;}
table {width: 100%;border-collapse: collapse;margin-top: 20px;font-size: 13px;}
th, td {border-bottom: 1px solid #334155;padding: 6px;}
th {background: #0ea5e9;color: black;}
td {color: #e2e8f0;}
</style>
</head>
<body>

<h1>💹 Smart Quotex Risk Manager</h1>
<div class="container">

<p><b>Balance:</b> ₹<?= number_format($_SESSION['balance'], 2) ?></p>
<p><b>Trade:</b> ₹<?= number_format($_SESSION['current_trade'], 2) ?> 
<span class="risk">(Risk: <?= $risk_percent ?>%)</span></p>
<p><b>Daily P/L:</b> <?= $daily_change >= 0 ? "🟢 +₹" . number_format($daily_change, 2) : "🔴 ₹" . number_format($daily_change, 2) ?>
 (<?= $daily_percent ?>%)</p>
<p><b>Mode:</b> <?= $_SESSION['martingale'] ? '🟢 Martingale ON' : '🔴 Normal' ?></p>
<hr>

<form method="POST">
<label><b>Compounding Steps:</b></label><br>
<input type="number" name="steps" value="<?= $_SESSION['max_steps'] ?>" min="1" max="5" required><br>
<label><b>Starting Balance:</b></label><br>
<input type="number" name="balance" value="<?= $_SESSION['balance'] ?>" step="0.01" required><br>
<button type="submit" name="set_steps">💾 Update</button>
</form>

<form method="POST">
<button type="submit" name="toggle_martingale">
<?= $_SESSION['martingale'] ? '🔴 Turn OFF Martingale' : '🟢 Turn ON Martingale' ?>
</button>
</form>

<hr>

<form method="POST">
<h3>Enter Trade Result</h3>
<?php if (!$_SESSION['trading_locked']): ?>
<button name="result" value="win">✅ Win</button>
<button name="result" value="lose">❌ Lose</button>
<?php else: ?>
<div class="locked">⛔ Trading Locked (Daily Loss Limit Hit)</div>
<?php endif; ?>
</form>

<form method="POST">
<button name="reset" style="background:#ef4444;color:white;">🔄 Reset All</button>
</form>

<?php if (isset($msg)): ?><div class="msg"><?= $msg ?></div><?php endif; ?>

<?php if ($recovery_suggestion): ?>
<div class="msg" style="background:#22c55e;color:black;">
💡 Recovery Tip: You lost ₹<?= $recovery_suggestion['loss'] ?>.<br>
Next trade ≈ <b>₹<?= number_format($recovery_suggestion['next'], 2) ?></b>
to recover + ₹<?= $recovery_suggestion['goal'] ?> profit.
</div>
<?php endif; ?>

<?php if (!empty($_SESSION['log'])): ?>
<h3>📅 Trade Log</h3>
<table>
<tr><th>Time</th><th>Trade</th><th>Result</th><th>P/L</th><th>Balance</th></tr>
<?php foreach (array_reverse($_SESSION['log']) as $entry): ?>
<tr>
<td><?= $entry['time'] ?></td>
<td>₹<?= $entry['trade'] ?></td>
<td><?= $entry['result'] ?></td>
<td><?= ($entry['pnl'] >= 0 ? "🟢 +₹" : "🔴 ₹") . abs($entry['pnl']) ?></td>
<td>₹<?= number_format($entry['balance'], 2) ?></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>
</div>

</body>
</html>
