<?php

// PROJECT BY : SAI SAMHITH REDDY , DATE : 1/8/2025
// linkedin : https://www.linkedin.com/in/saisamhithreddy
// LEETCODE : https://leetcode.com/u/iamsaisamhithreddy/
// GITHUB : https://github.com/iamsaisamhithreddy


error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'db.php'; // server connection is from here.

// Session login or not.
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php'); // redirecting to login page if not logged in
    exit;
}

// count function
function getCount($conn, $sql) {
    $res = $conn->query($sql);
    if ($res && $row = $res->fetch_assoc()) {
        return (int)$row['c'];
    }
    return 0;
}

// Current IST time
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

$validPairs = 20; // fixed beacuse we have only 20 major forex pairs like eurusd,usdjpy etc.. 

// Total alerts today
$totalAlertsToday = getCount(
    $conn,
    "SELECT COUNT(*) AS c
     FROM prediction_trade_data
     WHERE DATE(CONVERT_TZ(last_alert_time, '+00:00', '+05:30')) = '$todayIST'"
);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Admin Dashboard</title>
<style>
  * {margin:0;padding:0;box-sizing:border-box;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;}
  body {display:flex;min-height:100vh;background:#f4f7f9;}
  .sidebar {width:250px;background:#2d3e50;color:#fff;display:flex;flex-direction:column;padding:20px;}
  .sidebar h2 {margin-bottom:30px;font-weight:700;letter-spacing:2px;text-align:center;}
  .sidebar a {color:#cfd8dc;text-decoration:none;margin:12px 0;padding:12px 15px;border-radius:5px;transition:background 0.3s;}
  .sidebar a:hover,.sidebar a.active {background:#4c5c74;color:white;}
  .main-content {flex-grow:1;padding:25px 40px;}
  header {background:white;padding:15px 20px;box-shadow:0 2px 6px rgb(0 0 0 / 0.1);margin-bottom:25px;border-radius:6px;}
  header h1 {font-weight:700;color:#2d3e50;}
  .cards {display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;}
  .card {background:white;padding:25px;border-radius:10px;box-shadow:0 2px 6px rgb(0 0 0 / 0.1);text-align:center;transition:transform 0.2s ease;}
  .card:hover {transform:translateY(-5px);}
  .card h2 {font-size:2.8rem;color:#2d3e50;margin-bottom:10px;}
  .card p {font-size:1.1rem;color:#777;}
</style>
</head>
<body>

<!-- here we keep links to other features, when click on these it will take to respective pages. !-->

<div class="sidebar">
  <h2>ADMIN PANEL</h2>
  <a href="manage_users.php">Manage Users</a>
  <a href="news_admin.php">Add News Event</a>
  <a href="broadcast.php">Send BroadCast Message</a>
  <a href="events.php">View Upcoming News Events</a>
  <a href="view_alerts.php">View Alerts</a>
  <a href="Trade_report.php">TRADE REPORTS</a>
  <a href="tables_backup.php">Tables data backup</a>
  <a href="validate.php">Validate</a>
  <a href="valid_pairs.php">Valid Pairs</a>
  <a href="fetch_trade.php">Trade Enquiry</a>
  <a href="tg.php">Send Telegram Notification</a>
  <a href="send_mail.php">Send mail (10 mails/hr)</a>
  <a href="logout.php" style="margin-top:auto; color:#ff5555;">Logout</a>
</div>

<div class="main-content">
  <header>
    <h1>Welcome, Admin</h1>
  </header>

  <div class="cards">
    <div class="card">
      <h2><?php echo $usersCount; ?></h2>
      <p>Users Registered</p>
    </div>
    <div class="card">
      <h2><?php echo $pendingEvents; ?></h2>
      <p>Pending News Events</p>
    </div>
    <div class="card">
      <h2><?php echo $validPairs; ?></h2>
      <p>Valid Trading Pairs</p>
    </div>
    <div class="card">
      <h2><?php echo $totalAlertsToday; ?></h2>
      <p>Total Alerts Today</p>
    </div>
  </div>

</div>
</body>
</html>
