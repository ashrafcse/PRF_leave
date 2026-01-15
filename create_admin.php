<?php
require_once __DIR__ . '/db.php';

// Change these:
$username   = 'admin';
$email      = 'admin@example.com';
$password   = 'admin123';
$createdBy  = 'system'; // <-- not numeric, so we'll omit CreatedBy column

$hash = password_hash($password, PASSWORD_BCRYPT);

// If CreatedBy is numeric, use it; otherwise, omit the column to avoid INT conversion errors
$createdById = is_numeric($createdBy) ? (int)$createdBy : null;

try {
    if ($createdById === null) {
        // Omit CreatedBy column
        $sql = "INSERT INTO dbo.Users (Username, PasswordHash, Email, IsActive, CreatedAt)
                VALUES (:u, :p, :e, 1, GETDATE())";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':u', $username, PDO::PARAM_STR);
        $stmt->bindValue(':p', $hash, PDO::PARAM_STR);
        $stmt->bindValue(':e', $email, PDO::PARAM_STR);
    } else {
        // Include CreatedBy as INT
        $sql = "INSERT INTO dbo.Users (Username, PasswordHash, Email, IsActive, CreatedAt, CreatedBy)
                VALUES (:u, :p, :e, 1, GETDATE(), :cb)";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':u', $username, PDO::PARAM_STR);
        $stmt->bindValue(':p', $hash, PDO::PARAM_STR);
        $stmt->bindValue(':e', $email, PDO::PARAM_STR);
        $stmt->bindValue(':cb', $createdById, PDO::PARAM_INT);
    }

    $stmt->execute();
    echo "Admin user created.\n";

} catch (PDOException $ex) {
    echo "Error: " . $ex->getMessage();
}
