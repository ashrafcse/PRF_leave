<?php
// includes/db.php
// PDO SQL Server connection for dbPRFAssetMgt

$serverName = "27.147.225.171";
$database   = "dbPRFAssetMgt"; // confirmed by you
$db_username = "sa";
$password    = "LocK@DMServer"; // keep this secure

try {
    $conn = new PDO("sqlsrv:Server=$serverName;Database=$database", $db_username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // optional: set default fetch mode
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // if($conn){
    //     echo"db connected";
    // }
} catch (PDOException $e) {
    // In production, log errors instead of echoing
    die('Database connection failed: ' . $e->getMessage());
}
?>