<?php
require_once __DIR__ . '/db.php'; // $conn = new PDO(...); ERRMODE_EXCEPTION on

// ---------- Seed data (code, typeLabelOrBit, name, address, phone, district) ----------
$locations = [
    ['LOC001', 'Corporate HQ',   'Head Office',    '123 Main Street, Dhaka',       '01710000001', 'Dhaka'],
    ['LOC002', 'Regional Office','Branch Office',  '456 North Road, Chittagong',   '01710000002', 'Chittagong'],
    ['LOC003', 'Industrial',     'Factory',        '789 Factory Road, Gazipur',    '01710000003', 'Gazipur'],
    ['LOC004', 'Storage',        'Warehouse',      '25A Storage Lane, Narayanganj','01710000004', 'Narayanganj'],
    ['LOC005', 'Sales',          'Sales Office',   '78 Commerce Street, Rajshahi', '01710000005', 'Rajshahi'],
    ['LOC006', 'Support',        'Service Center', '11A Service Road, Sylhet',     '01710000006', 'Sylhet'],
    ['LOC007', 'Education',      'Training Center','32 Learning Road, Khulna',     '01710000007', 'Khulna'],
    ['LOC008', 'R&D',            'Research Lab',   '88 Science Ave, Mymensingh',   '01710000008', 'Mymensingh'],
    ['LOC009', 'Technology',     'IT Hub',         '101 Tech Park, Barisal',       '01710000009', 'Barisal'],
    ['LOC010', 'Support',        'Customer Care',  '55 Help Street, Rangpur',      '01710000010', 'Rangpur'],
];

$createdBy = 1; // system user id

// ---------- Helpers ----------
function to_bit($v): int {
    // Accept 1/0, true/false, "1"/"0" etc.
    if (is_bool($v)) return $v ? 1 : 0;
    if (is_numeric($v)) return ((int)$v) ? 1 : 0;
    $s = mb_strtolower(trim((string)$v));
    if ($s === 'true' || $s === 'yes' || $s === 'y') return 1;
    if ($s === 'false' || $s === 'no'  || $s === 'n') return 0;

    // Heuristic: treat corporate/HQ/head-office as 1, others 0
    if (str_contains($s, 'corporate') || str_contains($s, 'hq') || str_contains($s, 'head')) return 1;
    return 0;
}

try {
    $conn->beginTransaction();

    // Upsert-style: if LocationCode exists → UPDATE, else INSERT
    $sel = $conn->prepare("SELECT LocationID FROM dbo.Locations WHERE LocationCode = :code");

    $ins = $conn->prepare("
        INSERT INTO dbo.Locations
            (LocationCode, LocationType, LocationName, Address, Phone, District, IsActive, CreatedAt, CreatedBy)
        VALUES
            (:LocationCode, :LocationType, :LocationName, :Address, :Phone, :District, 1, GETDATE(), :CreatedBy)
    ");

    $upd = $conn->prepare("
        UPDATE dbo.Locations
           SET LocationType = :LocationType,
               LocationName = :LocationName,
               Address      = :Address,
               Phone        = :Phone,
               District     = :District,
               IsActive     = 1
         WHERE LocationID   = :LocationID
    ");

    foreach ($locations as $loc) {
        [$code, $typeLabelOrBit, $name, $addr, $phone, $district] = $loc;

        // Cast/derive bit safely
        $bitType = to_bit($typeLabelOrBit);

        // Exists?
        $sel->execute([':code' => $code]);
        $id = $sel->fetchColumn();

        if ($id) {
            // UPDATE
            $upd->execute([
                ':LocationType' => $bitType,
                ':LocationName' => $name,
                ':Address'      => $addr,
                ':Phone'        => $phone,
                ':District'     => $district,
                ':LocationID'   => (int)$id,
            ]);
            echo "♻️ Updated Location: {$name} ({$code})<br>";
        } else {
            // INSERT (no LocationID → identity handles it)
            $ins->execute([
                ':LocationCode' => $code,
                ':LocationType' => $bitType,
                ':LocationName' => $name,
                ':Address'      => $addr,
                ':Phone'        => $phone,
                ':District'     => $district,
                ':CreatedBy'    => $createdBy,
            ]);
            echo "✅ Inserted Location: {$name} ({$code})<br>";
        }
    }

    $conn->commit();
    echo "<br><strong>✅ Locations seeding completed.</strong>";

} catch (PDOException $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo "❌ Error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
