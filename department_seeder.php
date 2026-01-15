<?php
require_once __DIR__ . '/db.php'; // PDO MSSQL connection file

// Seeder data (২০টা department)
$departments = [
    'Human Resources',
    'Finance',
    'IT Department',
    'Marketing',
    'Procurement',
    'Operations',
    'Research & Development',
    'Customer Service',
    'Legal Affairs',
    'Logistics',
    'Public Relations',
    'Production',
    'Sales',
    'Quality Assurance',
    'Maintenance',
    'Planning',
    'Security',
    'Administration',
    'Training & Development',
    'Audit Department'
];

$createdBy = 'system'; // যদি numeric ID হয় তবে int দাও
$createdById = is_numeric($createdBy) ? (int)$createdBy : null;

try {
    foreach ($departments as $name) {

        if ($createdById === null) {
            // CreatedBy ছাড়া insert
            $sql = "INSERT INTO dbo.Departments (DepartmentName, IsActive, CreatedAt)
                    VALUES (:name, 1, GETDATE())";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        } else {
            // CreatedBy সহ insert
            $sql = "INSERT INTO dbo.Departments (DepartmentName, IsActive, CreatedAt, CreatedBy)
                    VALUES (:name, 1, GETDATE(), :cb)";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(':name', $name, PDO::PARAM_STR);
            $stmt->bindValue(':cb', $createdById, PDO::PARAM_INT);
        }

        $stmt->execute();
        echo "Inserted department: $name<br>";
    }

    echo "<br><strong>✅ 20 Departments inserted successfully!</strong>";

} catch (PDOException $ex) {
    echo "❌ Error: " . $ex->getMessage();
}
