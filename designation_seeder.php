<?php 
require_once __DIR__ . '/db.php'; // MSSQL PDO connection

// Seeder data (২০টা Designation)
$designations = [
    'Chief Executive Officer (CEO)',
    'Chief Operating Officer (COO)',
    'Chief Financial Officer (CFO)',
    'Chief Technology Officer (CTO)',
    'General Manager',
    'Assistant General Manager',
    'Senior Manager',
    'Manager',
    'Deputy Manager',
    'Assistant Manager',
    'Team Lead',
    'Senior Executive',
    'Executive',
    'Junior Executive',
    'Officer',
    'Assistant Officer',
    'Intern',
    'Supervisor',
    'Coordinator',
    'Trainee Officer'
];

$createdBy = 1; // system user id
$jobId = 1;     // starting id

try {
    foreach ($designations as $title) {

        $sql = "INSERT INTO dbo.Designation (JobTitleID, JobTitleName, IsActive, CreatedAt, CreatedBy)
                VALUES (:id, :title, 1, GETDATE(), :cb)";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':id', $jobId, PDO::PARAM_INT);
        $stmt->bindValue(':title', $title, PDO::PARAM_STR);
        $stmt->bindValue(':cb', $createdBy, PDO::PARAM_INT);

        $stmt->execute();
        echo "✅ Inserted designation #$jobId: $title<br>";

        $jobId++; // next id
    }

    echo "<br><strong>✅ All 20 Designations inserted successfully!</strong>";

} catch (PDOException $ex) {
    echo "❌ Error: " . $ex->getMessage();
}
