<?php
require_once __DIR__ . '/../../init.php';
require_login();

/* Current user info */
$currentUserId = $_SESSION['auth_user']['UserID'] ?? null;
$approverEmployeeId = 0;

if ($currentUserId) {
    try {
        $stmt = $conn->prepare("SELECT EmployeeID FROM dbo.Users WHERE UserID = :uid");
        $stmt->execute([':uid' => $currentUserId]);
        $approverEmployeeId = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        $approverEmployeeId = 0;
    }
}

/* Status labels */
$statusText = [
    1 => '<span class="badge bg-warning">Pending L1</span>',
    2 => '<span class="badge bg-info">Approved L1</span>',
    3 => '<span class="badge bg-success">Approved L2 (Final)</span>',
    4 => '<span class="badge bg-danger">Rejected</span>',
    5 => '<span class="badge bg-secondary">Cancelled by Staff</span>'
];

/* Handle POST actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $appId = (int)($_POST['LeaveApplicationID'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');
    $action = $_POST['action'];

    if ($appId > 0 && $approverEmployeeId) {
        try {
            $conn->beginTransaction();

            switch ($action) {
                case 'l1_approve':
                    $stmt = $conn->prepare("
                        UPDATE dbo.LeaveApplications 
                        SET Status = 2, 
                            L1ApprovedBy = :by, 
                            L1ApprovedDate = GETDATE() 
                        WHERE LeaveApplicationID = :id AND Status = 1
                    ");
                    $stmt->execute([':by' => $approverEmployeeId, ':id' => $appId]);
                    break;

                case 'l2_approve':
                    $stmt = $conn->prepare("
                        UPDATE dbo.LeaveApplications 
                        SET Status = 3, 
                            L2ApprovedBy = :by, 
                            L2ApprovedDate = GETDATE() 
                        WHERE LeaveApplicationID = :id AND Status = 2
                    ");
                    $stmt->execute([':by' => $approverEmployeeId, ':id' => $appId]);
                    break;

                case 'reject':
                    if ($comment === '') throw new Exception('Rejection reason required');
                    $stmt = $conn->prepare("
                        UPDATE dbo.LeaveApplications 
                        SET Status = 4, 
                            RejectedBy = :by, 
                            RejectedDate = GETDATE(),
                            RejectionReason = :reason 
                        WHERE LeaveApplicationID = :id AND Status IN (1, 2)
                    ");
                    $stmt->execute([':by' => $approverEmployeeId, ':reason' => $comment, ':id' => $appId]);
                    break;

                case 'cancel':
                    $stmt = $conn->prepare("
                        UPDATE dbo.LeaveApplications 
                        SET Status = 5 
                        WHERE LeaveApplicationID = :id 
                        AND EmployeeID = (SELECT EmployeeID FROM dbo.Users WHERE UserID = :uid)
                        AND Status = 1
                    ");
                    $stmt->execute([':id' => $appId, ':uid' => $currentUserId]);
                    break;

                default:
                    throw new Exception('Invalid action');
            }

            $conn->commit();
            $_SESSION['flash_message'] = "Action completed successfully!";
            $_SESSION['flash_type'] = "success";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;

        } catch (Exception $e) {
            $conn->rollBack();
            $_SESSION['flash_message'] = "Error: " . $e->getMessage();
            $_SESSION['flash_type'] = "danger";
        }
    }
}

/* Fetch leave applications */
$rows = [];
try {
    $stmt = $conn->query("
        SELECT la.*,
               e.FirstName+' '+e.LastName AS EmployeeName,
               lt.LeaveTypeName,
               l1.FirstName+' '+l1.LastName AS L1Name,
               l2.FirstName+' '+l2.LastName AS L2Name,
               r.FirstName+' '+r.LastName AS RejName,
               CASE WHEN la.EmployeeID = (SELECT EmployeeID FROM dbo.Users WHERE UserID = $currentUserId) THEN 1 ELSE 0 END AS IsMyApplication
        FROM dbo.LeaveApplications la
        LEFT JOIN dbo.Employees e ON e.EmployeeID = la.EmployeeID
        LEFT JOIN dbo.LeaveTypes lt ON lt.LeaveTypeID = la.LeaveTypeID
        LEFT JOIN dbo.Employees l1 ON l1.EmployeeID = la.L1ApprovedBy
        LEFT JOIN dbo.Employees l2 ON l2.EmployeeID = la.L2ApprovedBy
        LEFT JOIN dbo.Employees r ON r.EmployeeID = la.RejectedBy
        ORDER BY la.LeaveApplicationID DESC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $rows = [];
}

require_once __DIR__.'/../../include/header.php';
?>

<div class="container mt-4">
    <h3>Leave Management</h3>

    <?php if (!empty($_SESSION['flash_message'])): ?>
        <div class="alert alert-<?= $_SESSION['flash_type'] ?? 'info' ?> alert-dismissible fade show">
            <?= htmlspecialchars($_SESSION['flash_message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
    <?php endif; ?>

    <?php if (empty($rows)): ?>
        <div class="alert alert-info">No leave applications found.</div>
    <?php else: ?>
        <div class="card shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Employee</th>
                                <th>Type</th>
                                <th>Dates</th>
                                <th>Days</th>
                                <th>Status</th>
                                <th>Applied Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td>#<?= $r['LeaveApplicationID'] ?></td>
                                    <td><?= htmlspecialchars($r['EmployeeName']) ?></td>
                                    <td><?= htmlspecialchars($r['LeaveTypeName']) ?></td>
                                    <td>
                                        <?= date('d/m/Y', strtotime($r['StartDate'])) ?> to 
                                        <?= date('d/m/Y', strtotime($r['EndDate'])) ?>
                                    </td>
                                    <td><?= $r['TotalDays'] ?> days</td>
                                    <td><?= $statusText[$r['Status']] ?? '<span class="badge bg-light">Unknown</span>' ?></td>
                                    <td><?= date('d/m/Y', strtotime($r['AppliedDate'])) ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary view-btn" data-id="<?= $r['LeaveApplicationID'] ?>">
                                            <i class="fas fa-eye"></i> View
                                        </button>

                                        <?php if (in_array($r['Status'], [1,2]) && $approverEmployeeId): ?>
                                            <div class="btn-group">
                                                <button type="button" class="btn btn-sm btn-outline-success dropdown-toggle" data-bs-toggle="dropdown">
                                                    <i class="fas fa-check"></i> Approve
                                                </button>
                                                <ul class="dropdown-menu">
                                                    <?php if ($r['Status']==1): ?>
                                                        <li><a class="dropdown-item" href="#" onclick="showApproveModal(<?= $r['LeaveApplicationID'] ?>,'l1')">L1 Approve</a></li>
                                                    <?php endif; ?>
                                                    <?php if ($r['Status']==2): ?>
                                                        <li><a class="dropdown-item" href="#" onclick="showApproveModal(<?= $r['LeaveApplicationID'] ?>,'l2')">L2 Approve</a></li>
                                                    <?php endif; ?>
                                                </ul>
                                            </div>
                                            <button class="btn btn-sm btn-outline-danger" onclick="showRejectModal(<?= $r['LeaveApplicationID'] ?>)">Reject</button>
                                        <?php endif; ?>

                                        <?php if ($r['Status']==1 && $r['IsMyApplication']==1): ?>
                                            <button class="btn btn-sm btn-outline-warning" onclick="confirmCancel(<?= $r['LeaveApplicationID'] ?>)">Cancel</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Include modals and JS handlers (View, Approve, Reject, Cancel) -->
<?php include __DIR__.'/leave_modals.php'; ?>

<?php require_once __DIR__.'/../../include/footer.php'; ?>
