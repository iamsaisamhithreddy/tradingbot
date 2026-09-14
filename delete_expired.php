<?php

include 'db.php';

$sql = "DELETE FROM `economic_events` WHERE `event_time` < CURDATE()";

if ($conn->query($sql) === TRUE) {
    $deletedRows = $conn->affected_rows;
    echo "Cleanup successful. Deleted $deletedRows expired events.";
} else {
    echo "Error deleting records: " . $conn->error;
}

// Close the connection
$conn->close();
?>