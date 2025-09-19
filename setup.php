<?php
/// PROJECT BY : SAI SAMHITH REDDY , DATE : 1/8/2025
// linkedin : https://www.linkedin.com/in/saisamhithreddy
// LEETCODE : https://leetcode.com/u/iamsaisamhithreddy/
// GITHUB : https://github.com/iamsaisamhithreddy

require 'db.php'; 

$tables = [];

// ----------------- Table Definitions -----------------

$tables['access_tokens'] = "
CREATE TABLE access_tokens (
    id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
";

$tables['admin_users'] = "
CREATE TABLE admin_users (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL
) ENGINE=InnoDB;
";

$tables['scheduled_broadcasts'] = "
CREATE TABLE scheduled_broadcasts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message TEXT NOT NULL,
    send_type ENUM('all','selected') NOT NULL DEFAULT 'all',
    user_ids TEXT DEFAULT 'all',
    scheduled_time DATETIME NOT NULL,
    sent TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
ENGINE=InnoDB;
";


$tables['economic_events'] = "
CREATE TABLE economic_events (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_name VARCHAR(255) NOT NULL,
    impact VARCHAR(10) NOT NULL,
    event_time DATETIME NOT NULL,
    sent_status TINYINT(1) DEFAULT 0
) ENGINE=InnoDB;
";

$tables['live_price_data'] = "
CREATE TABLE live_price_data (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    pair_name VARCHAR(20) NOT NULL,
    current_price DECIMAL(15,5) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
";

$tables['prediction_trade_data'] = "
CREATE TABLE prediction_trade_data (
    raw_trade_id INT(11) NOT NULL PRIMARY KEY,
    pair_name VARCHAR(20) NOT NULL,
    price_target DECIMAL(15,5) NOT NULL,
    trade_direction VARCHAR(10) NOT NULL,
    sent_status TINYINT(1) DEFAULT 0,
    last_alert_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_alerted_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
";

$tables['raw_trade_data'] = "
CREATE TABLE raw_trade_data (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    pair_name VARCHAR(20) NOT NULL,
    O1 DOUBLE NOT NULL, H1 DOUBLE NOT NULL, L1 DOUBLE NOT NULL, C1 DOUBLE NOT NULL,
    O2 DOUBLE NOT NULL, H2 DOUBLE NOT NULL, L2 DOUBLE NOT NULL, C2 DOUBLE NOT NULL,
    O3 DOUBLE NOT NULL, H3 DOUBLE NOT NULL, L3 DOUBLE NOT NULL, C3 DOUBLE NOT NULL,
    O4 DOUBLE NOT NULL, H4 DOUBLE NOT NULL, L4 DOUBLE NOT NULL, C4 DOUBLE NOT NULL,
    O5 DOUBLE NOT NULL, H5 DOUBLE NOT NULL, L5 DOUBLE NOT NULL, C5 DOUBLE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
";

$tables['telegram_users'] = "
CREATE TABLE telegram_users (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    chat_id VARCHAR(50) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
";

$tables['unauthorized_attempts'] = "
CREATE TABLE unauthorized_attempts (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    chat_id BIGINT(20) NOT NULL,
    username VARCHAR(255) DEFAULT NULL,
    first_name VARCHAR(255) DEFAULT NULL,
    command VARCHAR(255) DEFAULT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
";

// ----------------- Functions -----------------
function tableExists($conn, $table) {
    $res = $conn->query("SHOW TABLES LIKE '$table'");
    return $res && $res->num_rows > 0;
}

// ----------------- Create Tables -----------------
foreach ($tables as $name => $sql) {
    if (tableExists($conn, $name)) {
        echo "Table '$name' already exists.<br>";
    } else {
        if ($conn->query($sql) === TRUE) {
            echo "Table '$name' created successfully.<br>";
        } else {
            echo "Error creating table '$name': " . $conn->error . "<br>";
        }
    }
}

// ----------------- Default Admin User -----------------
$default_admin_username = 'admin';
$default_admin_password = 'Admin@123'; // You can change this
$hashed_password = password_hash($default_admin_password, PASSWORD_DEFAULT);

$result = $conn->query("SELECT id FROM admin_users WHERE username='$default_admin_username'");
if ($result && $result->num_rows === 0) {
    $stmt = $conn->prepare("INSERT INTO admin_users (username, password_hash) VALUES (?, ?)");
    $stmt->bind_param("ss", $default_admin_username, $hashed_password);
    $stmt->execute();
    $stmt->close();
    echo "Default admin user created. Username: $default_admin_username, Password: $default_admin_password<br>";
} else {
    echo "Default admin user already exists.<br> USERNAME :admin <br> password:Admin@123 ";
}

$conn->close();
?>
