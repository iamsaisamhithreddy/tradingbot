<?php

require 'db.php';

$tables = [];



// admin_users

$tables['admin_users'] = "
CREATE TABLE admin_users (
  id INT(11) NOT NULL AUTO_INCREMENT,
  username VARCHAR(50) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  telegram_chat_id BIGINT(20) DEFAULT NULL,
  permissions LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
    DEFAULT JSON_ARRAY() CHECK (JSON_VALID(permissions)),
  PRIMARY KEY (id),
  UNIQUE KEY username (username)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
";

// economic_events
$tables['economic_events'] = "
CREATE TABLE economic_events (
  id INT(11) NOT NULL AUTO_INCREMENT,
  event_name VARCHAR(255) NOT NULL,
  impact VARCHAR(10) NOT NULL,
  event_time DATETIME NOT NULL,
  sent_status TINYINT(1) DEFAULT 0,
  event_date DATE AS (DATE(event_time)) STORED,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
";

// live_price_data
$tables['live_price_data'] = "
CREATE TABLE live_price_data (
  id INT(11) NOT NULL AUTO_INCREMENT,
  pair_name VARCHAR(20) NOT NULL,
  current_price DECIMAL(15,5) NOT NULL,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
";

// scheduled_broadcasts
$tables['scheduled_broadcasts'] = "
CREATE TABLE scheduled_broadcasts (
  id int(11) NOT NULL,
  message text NOT NULL,
  send_type enum('all','selected') NOT NULL DEFAULT 'all',
  user_ids text DEFAULT 'all',
  scheduled_time datetime NOT NULL,
  sent tinyint(1) NOT NULL DEFAULT 0,
  created_at timestamp NULL DEFAULT current_timestamp(),
  file_path varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
";

// api_keys 
$tables['api_keys'] = "
CREATE TABLE api_keys (
  id int(11) NOT NULL,
  provider varchar(50) DEFAULT NULL,
  api_key text DEFAULT NULL,
  base_url varchar(255) DEFAULT NULL,
  model varchar(100) DEFAULT NULL,
  status enum('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
";


// raw_trade_data  (PARENT FIRST – IMPORTANT)
$tables['raw_trade_data'] = "
CREATE TABLE raw_trade_data (
  id INT(11) NOT NULL AUTO_INCREMENT,
  pair_name VARCHAR(20) NOT NULL,
  O1 DOUBLE NOT NULL, H1 DOUBLE NOT NULL, L1 DOUBLE NOT NULL, C1 DOUBLE NOT NULL,
  O2 DOUBLE NOT NULL, H2 DOUBLE NOT NULL, L2 DOUBLE NOT NULL, C2 DOUBLE NOT NULL,
  O3 DOUBLE NOT NULL, H3 DOUBLE NOT NULL, L3 DOUBLE NOT NULL, C3 DOUBLE NOT NULL,
  O4 DOUBLE NOT NULL, H4 DOUBLE NOT NULL, L4 DOUBLE NOT NULL, C4 DOUBLE NOT NULL,
  O5 DOUBLE NOT NULL, H5 DOUBLE NOT NULL, L5 DOUBLE NOT NULL, C5 DOUBLE NOT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
";

// prediction_trade_data (ONE-TO-ONE, PK = FK)
$tables['prediction_trade_data'] = "
CREATE TABLE prediction_trade_data (
  raw_trade_id INT(11) NOT NULL,
  pair_name VARCHAR(20) DEFAULT NULL,
  price_target DECIMAL(10,5) DEFAULT NULL,
  trade_direction ENUM('UP','DOWN') DEFAULT NULL,
  sent_status TINYINT(1) DEFAULT NULL,
  trade_result VARCHAR(20) DEFAULT NULL,
  last_alert_time DATETIME DEFAULT NULL,
  updated_at DATETIME DEFAULT NULL,
  PRIMARY KEY (raw_trade_id),
  CONSTRAINT fk_prediction_raw_trade
    FOREIGN KEY (raw_trade_id)
    REFERENCES raw_trade_data(id)
    ON DELETE RESTRICT
    ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
";

// scheduled_broadcasts
$tables['scheduled_broadcasts'] = "
CREATE TABLE scheduled_broadcasts (
  id INT(11) NOT NULL AUTO_INCREMENT,
  message TEXT NOT NULL,
  send_type ENUM('all','selected') NOT NULL DEFAULT 'all',
  user_ids TEXT DEFAULT 'all',
  scheduled_time DATETIME NOT NULL,
  sent TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  file_path VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
";

// telegram_users
$tables['telegram_users'] = "
CREATE TABLE telegram_users (
  id INT(11) NOT NULL AUTO_INCREMENT,
  chat_id VARCHAR(50) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
";

// unauthorized_attempts
$tables['unauthorized_attempts'] = "
CREATE TABLE unauthorized_attempts (
  id INT(11) NOT NULL AUTO_INCREMENT,
  chat_id BIGINT(20) NOT NULL,
  username VARCHAR(255) DEFAULT NULL,
  first_name VARCHAR(255) DEFAULT NULL,
  command VARCHAR(255) DEFAULT NULL,
  attempted_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
";

/* ================= FUNCTIONS ================= */

function tableExists($conn, $table) {
    $res = $conn->query("SHOW TABLES LIKE '$table'");
    return ($res && $res->num_rows > 0);
}

/* ================= CREATE TABLES ================= */

foreach ($tables as $name => $sql) {
    if (tableExists($conn, $name)) {
        echo "Table '$name' already exists.<br>";
    } else {
        if ($conn->query($sql) === TRUE) {
            echo "Table '$name' created successfully.<br>";
        } else {
            echo "Error creating '$name': " . $conn->error . "<br>";
        }
    }
}

/* ================= DEFAULT ADMIN ================= */

$default_admin_username = 'admin';
$default_admin_password = 'Admin@123';
$hashed_password = password_hash($default_admin_password, PASSWORD_DEFAULT);

$result = $conn->query(
    "SELECT id FROM admin_users WHERE username='$default_admin_username'"
);

if ($result && $result->num_rows === 0) {
    $stmt = $conn->prepare(
        "INSERT INTO admin_users (username, password_hash) VALUES (?, ?)"
    );
    $stmt->bind_param("ss", $default_admin_username, $hashed_password);
    $stmt->execute();
    $stmt->close();
    echo "Default admin created (admin / Admin@123)<br>";
} else {
    echo "Default admin already exists.<br>";
}

$conn->close();
?>
