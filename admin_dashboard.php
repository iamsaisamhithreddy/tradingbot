<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
require 'db.php'; 

date_default_timezone_set("Asia/Kolkata");

// Session login or not.
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php'); // redirecting to login page if not logged in
    exit;
}

$loggedInUser = $_SESSION['admin_username'];
$permissions  = $_SESSION['admin_permissions'] ?? []; // fetch permissions

// Reusable function to calculate and return the session banner HTML
function generateSessionBanner() {
    $now = new DateTime("now");
    $current_time = $now->format("H:i");
    $dayOfWeek = $now->format("l");
    $currentDateDisplay = $now->format("d/m/Y");

    $sessions = [
        "Sydney"   => ["02:30", "11:30"],
        "Tokyo"    => ["05:30", "14:30"],
        "London"   => ["12:30", "21:30"],
        "New York" => ["17:30", "02:30"],
    ];

    $active = [];
    
    foreach ($sessions as $name => [$start, $end]) {
        if ($start < $end) {
            if ($current_time >= $start && $current_time <= $end) {
                $active[] = $name;
            }
        } else {
            if ($current_time >= $start || $current_time <= $end) {
                $active[] = $name;
            }
        }
    }

    if (count($active) > 1) {
        $sessionLabel = "Overlap: " . implode(" + ", $active);
        $state = "overlap";
    } elseif (count($active) == 1) {
        $sessionLabel = $active[0];
        $state = strtolower(str_replace(' ', '_', $active[0]));
    } else {
        $sessionLabel = "No Active Session";
        $state = "none";
    }

    $confidence = "Normal";
    if (strpos($sessionLabel, "London") !== false && strpos($sessionLabel, "New York") !== false) {
        $confidence = "Very High (London + NY Overlap)";
    } elseif (strpos($sessionLabel, "London") !== false && $current_time >= "13:30") {
        $confidence = "Boosted";
    } elseif (strpos($sessionLabel, "London") !== false || strpos($sessionLabel, "New York") !== false) {
        $confidence = "High";
    }
    
    if ($current_time >= "21:30" && strpos($sessionLabel, "New York") !== false) {
        $confidence = "Low (Late NY)";
    }

    // Notes
    if ($dayOfWeek === "Monday") {
        $note = "⚠️ Markets can be volatile on Monday.";
    } elseif ($dayOfWeek === "Friday" && $current_time >= "20:00") {
        $note = "⚠️ Friday late session: liquidity often drops.";
    } else {
        $note = "ℹ️ Moderate volatility expected.";
    }

    $gradientClass = "session-bar--neutral";
    if ($state === "overlap") {
        $gradientClass = "session-bar--overlap";
    } elseif ($state === "london" || $state === "new_york") {
        $gradientClass = "session-bar--highlight";
    } elseif ($state === "tokyo" || $state === "sydney") {
        $gradientClass = "session-bar--calm";
    } elseif ($state === "none") {
        $gradientClass = "session-bar--neutral";
    }

    // Build HTML snippet
    $html  = '<div class="session-bar ' . $gradientClass . '">';
    $html .= '  <div class="session-bar__left">';
    $html .= '    <div class="session-bar__title">🌍 ACTIVE SESSION</div>';
    $html .= '    <div class="session-bar__session"><strong>' . htmlspecialchars($sessionLabel) . '</strong></div>';
    $html .= '  </div>';
    $html .= '  <div class="session-bar__right">';
    $html .= '    <div class="session-bar__time">⏰ ' . htmlspecialchars($current_time) . ' IST</div>';
    $html .= '    <div class="session-bar__date">📅 ' . htmlspecialchars($currentDateDisplay) . '</div>';
    $html .= '    <div class="session-bar__confidence">📊 ' . htmlspecialchars($confidence) . '</div>';
    $html .= '  </div>';
    $html .= '  <div class="session-bar__note">📝 ' . htmlspecialchars($note) . '</div>';
    $html .= '</div>';

    return $html;
}

// Handle AJAX refresh request
if (isset($_GET['action']) && $_GET['action'] === 'session_status') {
    header('Content-Type: text/html; charset=utf-8');
    echo generateSessionBanner();
    exit;
}

function getCount($conn, $sql) {
    $res = $conn->query($sql);
    if ($res && $row = $res->fetch_assoc()) {
        return (int)$row['c'];
    }
    return 0;
}

$nowIST = new DateTime("now", new DateTimeZone("Asia/Kolkata"));
$currentIST = $nowIST->format("Y-m-d H:i:s");
$todayIST   = $nowIST->format("Y-m-d");

// get count of total authorized telegram users.
$usersCount = getCount($conn, "SELECT COUNT(*) AS c FROM telegram_users");

// Pending events after current IST time
$pendingEvents = getCount(
    $conn,
    "SELECT COUNT(*) AS c 
     FROM economic_events 
     WHERE sent_status = 0
       AND CONVERT_TZ(event_time, '+00:00', '+05:30') > '$currentIST'"
);

$validPairs = 20; // fixed because we have only 20 major forex pairs

$istZone = new DateTimeZone('Asia/Kolkata');
$utcZone = new DateTimeZone('UTC');

$istStart = new DateTime($todayIST . ' 00:00:00', $istZone);
$istEnd = clone $istStart;
$istEnd->modify('+1 day');

// convert both to UTC so we can compare directly with UTC-stored DB timestamps
$istStartUTC = $istStart->setTimezone($utcZone)->format('Y-m-d H:i:s');
$istEndUTC   = $istEnd->setTimezone($utcZone)->format('Y-m-d H:i:s');

$totalAlertsToday = getCount(
    $conn,
    "SELECT COUNT(*) AS c
     FROM prediction_trade_data
     WHERE last_alert_time >= '$istStartUTC'
       AND last_alert_time  < '$istEndUTC'"
);

// Fetch all admin usernames
$admins = [];
$result = $conn->query("SELECT username FROM admin_users ORDER BY id ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $admins[] = $row['username'];
    }
}

// --- WIN PERCENTAGE CALCULATION ---
$winStatsQuery = "
    SELECT 
        SUM(CASE WHEN trade_result LIKE 'win%' THEN 1 ELSE 0 END) AS wins,
        SUM(CASE WHEN trade_result LIKE 'loss%' THEN 1 ELSE 0 END) AS losses
    FROM prediction_trade_data";
    
$winResult = $conn->query($winStatsQuery);
$winData = $winResult->fetch_assoc();
$totalWins = (int)$winData['wins'];
$totalLosses = (int)$winData['losses'];
$totalClosed = $totalWins + $totalLosses;
$winPercentage = ($totalClosed > 0) ? round(($totalWins / $totalClosed) * 100, 2) : 0;

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Admin Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  * {margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif;}
  body {display:flex;min-height:100vh;background:#f5f7fa;color:#333;}

  /* Sidebar */
  .sidebar {
    width:260px;
    background:linear-gradient(180deg,#1e293b,#0f172a);
    color:#fff;
    display:flex;
    flex-direction:column;
    padding:20px;
  }
  .sidebar h2 {
    margin-bottom:40px;
    font-weight:700;
    font-size:1.4rem;
    text-align:center;
    letter-spacing:1px;
  }
  .sidebar a {
    color:#cbd5e1;
    text-decoration:none;
    margin:10px 0;
    padding:12px 15px;
    border-radius:8px;
    display:block;
    transition:all 0.3s;
  }
  .sidebar a:hover,
  .sidebar a.active {
    background:#334155;
    color:#fff;
  }
  .sidebar a.logout {
    margin-top:auto;
    color:#f87171;
    font-weight:600;
  }

  /* Main Content */
  .main-content {
    flex-grow:1;
    padding:30px;
  }
  header {
    background:white;
    padding:20px 25px;
    border-radius:12px;
    box-shadow:0 4px 12px rgba(0,0,0,0.05);
    margin-bottom:16px;
  }
  header h1 {
    font-weight:700;
    font-size:1.8rem;
    color:#1e293b;
  }

  /* Session bar (B3 gradient style) */
  .session-bar {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    padding:14px 18px;
    border-radius:12px;
    color:#fff;
    margin-bottom:22px;
    box-shadow:0 8px 20px rgba(2,6,23,0.08);
  }
  .session-bar__left { display:flex; flex-direction:column; gap:6px; }
  .session-bar__right { display:flex; flex-direction:column; align-items:flex-end; gap:4px; }
  .session-bar__title { font-weight:700; font-size:0.95rem; opacity:0.95; }
  .session-bar__session { font-weight:800; font-size:1.05rem; }
  .session-bar__time, .session-bar__date, .session-bar__confidence { font-size:0.95rem; opacity:0.95; }
  .session-bar__note { margin-left:16px; font-size:0.9rem; opacity:0.95; }

  /* Gradient variants */
  .session-bar--overlap {
    background: linear-gradient(90deg,#8b5cf6,#ec4899);
  }
  .session-bar--highlight {
    background: linear-gradient(90deg,#06b6d4,#3b82f6);
  }
  .session-bar--calm {
    background: linear-gradient(90deg,#60a5fa,#34d399);
  }
  .session-bar--neutral {
    background: linear-gradient(90deg,#94a3b8,#64748b);
  }

  /* Cards */
  .cards {
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(240px,1fr));
    gap:25px;
  }
  .card {
    padding:30px 25px;
    border-radius:16px;
    color:white;
    box-shadow:0 8px 20px rgba(0,0,0,0.08);
    transition:transform 0.25s ease, box-shadow 0.25s ease;
  }
  .card:hover {
    transform:translateY(-6px);
    box-shadow:0 12px 28px rgba(0,0,0,0.12);
  }
  .card h2 {
    font-size:2.6rem;
    font-weight:700;
    margin-bottom:12px;
  }
  .card p {
    font-size:1.1rem;
    opacity:0.9;
  }

  /* Different colors for each card */
  .card.users {background:linear-gradient(135deg,#3b82f6,#1d4ed8);}
  .card.events {background:linear-gradient(135deg,#f59e0b,#d97706);}
  .card.pairs {background:linear-gradient(135deg,#10b981,#047857);}
  .card.alerts {background:linear-gradient(135deg,#ef4444,#b91c1c);}
  .card.admins {background:linear-gradient(135deg,#8b5cf6,#7c3aed);}
  .card.admins ul {list-style:none; padding-left:0; margin-top:5px;}
  .card.winrate {background: linear-gradient(135deg,#0ea5e9,#2563eb);}
  
</style>
</head>
<body>

<div class="sidebar">
  <h2>⚡ ADMIN PANEL</h2>

  <?php if (in_array('add_admin', $permissions)): ?>
    <a href="add_admin.php">😎 Add Admins </a>
  <?php endif; ?>

  <a href="dataset/">📰 DATASET</a>
  <a href="stats.php"> PAIR-WISE STATISTICS</a>
  <a href="plot/">📊 PLOT DATA - Replay mode</a>
  <a href="trail/">📊 TRADES PLOT LINKING </a>
  <a href="api_keys_add.php"> AI API🌐 </a>
  <a href="/livechart/trades_status_verify.php">Trade result status (automated)</a>
  
  <?php if (in_array('manage_users', $permissions)): ?>
    <a href="manage_users.php">👤 Manage Users</a>
  <?php endif; ?>

  <?php if (in_array('add_news', $permissions)): ?>
    <a href="news_admin.php">📰 Add News Event</a>
  <?php endif; ?>

  <?php if (in_array('broadcast', $permissions)): ?>
    <a href="broadcast.php">📢 Broadcast Message</a>
  <?php endif; ?>

  <?php if (in_array('view_events', $permissions)): ?>
    <a href="events.php">📅 Upcoming Events</a>
  <?php endif; ?>

  <?php if (in_array('trade_reports', $permissions)): ?>
    <a href="Trade_report.php">📑 Trade Reports</a>
  <?php endif; ?>

  <?php if (in_array('backup', $permissions)): ?>
    <a href="tables_backup.php">💾 Tables Backup</a>
  <?php endif; ?>

  <?php if (in_array('validate', $permissions)): ?>
    <a href="livechart/trades_status_verify.php">✅ Validate ForexFactory data</a>
    <a href="verify.php">✅ Validate (CSV tradingview data)</a>
  <?php endif; ?>

  <?php if (in_array('valid_pairs', $permissions)): ?>
    <a href="valid_pairs.php">💱 Valid Pairs</a>
  <?php endif; ?>

  <?php if (in_array('trade_enquiry', $permissions)): ?>
    <a href="fetch_trade.php">🔍 Trade Enquiry</a>
  <?php endif; ?>

  <?php if (in_array('upload_files', $permissions)): ?>
    <a href="upload.php">📑 Upload Files </a>
  <?php endif; ?>

  <?php if (in_array('send_mail', $permissions)): ?>
    <a href="send_mail.php">✉️ Send Mail</a>
  <?php endif; ?>

     <a href="logout.php" class="logout">🚪 Logout</a>
     
</div>

<div class="main-content">
  <header>
    <h1>Welcome back, <?php echo htmlspecialchars($loggedInUser); ?> 👋</h1>
  </header>

  <div id="session-banner-container">
    <?php echo generateSessionBanner(); ?>
  </div>

  <div class="cards">
    <div class="card users">
      <h2><?php echo $usersCount; ?></h2>
      <p>Users Registered</p>
    </div>
    <div class="card events">
      <h2><?php echo $pendingEvents; ?></h2>
      <p>Pending News Events</p>
    </div>
    <div class="card pairs">
      <h2><?php echo $validPairs; ?></h2>
      <p>Valid Trading Pairs</p>
    </div>
    <div class="card alerts">
      <h2><?php echo $totalAlertsToday; ?></h2>
      <p>Total Alerts Today</p>
    </div>
    
    <div class="card winrate">
      <h2><?php echo $winPercentage; ?>%</h2>
      <p>Win Rate (<?php echo $totalClosed; ?> Trades)</p>
    </div>
    
  </div>

</div>

<script>
  // Auto-refresh the session banner every 30 seconds
  (function(){
    const container = document.getElementById('session-banner-container');

    async function fetchSession() {
      try {
        const resp = await fetch(window.location.pathname + '?action=session_status', {cache: 'no-store'});
        if (!resp.ok) throw new Error('Network response was not ok');
        const html = await resp.text();
        container.innerHTML = html;
      } catch (err) {
        console.warn('Session fetch failed:', err);
      }
    }

    setInterval(fetchSession, 30000); // 30 seconds
    setTimeout(fetchSession, 1500);
  })();
</script>

</body>
</html>