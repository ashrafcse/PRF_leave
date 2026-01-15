<?php
require_once __DIR__ . '/../../init.php';
require_login();

function h($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/* CSRF */
if(!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
function check_csrf(){
    if($_POST['csrf'] !== ($_SESSION['csrf'] ?? '')) die('CSRF error');
}

/* Status Labels */
$STATUS = [
    1 => 'Pending L1',
    2 => 'Approved L1',
    3 => 'Approved L2 (Final)',
    4 => 'Rejected',
    5 => 'Cancelled by Staff'
];

/* Get Leave Application ID */
$id = (int)($_GET['id'] ?? 0);
if($id <= 0) die('Invalid ID');

/* Fetch Application */
$stmt = $conn->prepare("
    SELECT la.*, e.FirstName + ' ' + e.LastName AS EmployeeName
    FROM dbo.LeaveApplications la
    JOIN dbo.Employees e ON e.EmployeeID = la.EmployeeID
    WHERE la.LeaveApplicationID = :id
");
$stmt->execute([':id' => $id]);
$app = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$app) die('Application not found');

/* Get Leave Type Name */
$stmt2 = $conn->prepare("SELECT LeaveTypeName FROM dbo.LeaveTypes WHERE LeaveTypeID = :id");
$stmt2->execute([':id' => $app['LeaveTypeID']]);
$leaveTypeName = $stmt2->fetchColumn();

/* Resolve Approver EmployeeID */
$st = $conn->prepare("SELECT EmployeeID FROM dbo.Users WHERE UserID = :u");
$st->execute([':u' => $_SESSION['auth_user']['UserID']]);
$approverEID = $st->fetchColumn();

/* Handle Actions */
$msg = '';
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    check_csrf();
    $action = $_POST['action'] ?? '';
    $comment = trim($_POST['comment'] ?? '');

    $conn->beginTransaction();
    try {
        /* L1 Approve */
        if($action === 'l1' && $app['Status'] == 1){
            $conn->prepare("
                UPDATE dbo.LeaveApplications
                SET Status=2,
                    L1ApprovedBy=:by,
                    L1ApprovedDate=GETDATE()
                WHERE LeaveApplicationID=:id
            ")->execute([':by' => $approverEID, ':id' => $id]);
            $msg = 'L1 Approved';
        }
        /* L2 Approve */
        elseif($action === 'l2' && $app['Status'] == 2){
            $conn->prepare("
                UPDATE dbo.LeaveApplications
                SET Status=3,
                    L2ApprovedBy=:by,
                    L2ApprovedDate=GETDATE()
                WHERE LeaveApplicationID=:id
            ")->execute([':by' => $approverEID, ':id' => $id]);
            $msg = 'L2 Approved (Final)';
        }
        /* Reject */
        elseif($action === 'reject'){
            if($comment === '') throw new Exception('Comment required');
            $conn->prepare("
                UPDATE dbo.LeaveApplications
                SET Status=4,
                    RejectedBy=:by,
                    RejectedDate=GETDATE(),
                    RejectionReason=:r
                WHERE LeaveApplicationID=:id
            ")->execute([':by' => $approverEID, ':r' => $comment, ':id' => $id]);
            $msg = 'Application Rejected';
        } else {
            throw new Exception('Invalid action');
        }

        $conn->commit();
        header("Location: leave_view.php?id=$id&ok=1");
        exit;

    } catch(Exception $e){
        $conn->rollBack();
        $msg = $e->getMessage();
    }
}

require_once __DIR__ . '/../../include/header.php';
?>

<div class="container mt-4">
    <div class="card shadow-sm">
        <div class="card-body">
            <h3 class="mb-4">Leave Application Details</h3>

            <?php if($msg): ?>
                <div class="alert alert-danger"><?= h($msg) ?></div>
            <?php endif; ?>

            <table class="table table-bordered table-striped">
                <tr><th>Employee</th><td><?= h($app['EmployeeName']) ?></td></tr>
                <tr><th>Leave Type</th><td><?= h($leaveTypeName) ?></td></tr>
                <tr><th>Start Date</th><td><?= date('d/m/Y', strtotime($app['StartDate'])) ?></td></tr>
                <tr><th>End Date</th><td><?= date('d/m/Y', strtotime($app['EndDate'])) ?></td></tr>
                <tr><th>Total Days</th><td><?= $app['TotalDays'] ?> days</td></tr>
                <tr><th>Reason</th><td><?= h($app['Reason']) ?></td></tr>
                <tr><th>Status</th><td><?= $STATUS[$app['Status']] ?? 'Unknown' ?></td></tr>
                <tr><th>Applied Date</th><td><?= date('d/m/Y', strtotime($app['AppliedDate'])) ?></td></tr>
                <tr><th>L1 Approved</th><td><?= $app['L1ApprovedDate'] ?></td></tr>
                <tr><th>L2 Approved</th><td><?= $app['L2ApprovedDate'] ?></td></tr>
                <tr><th>Rejected</th><td><?= $app['RejectedDate'] ?></td></tr>
                <tr><th>Rejection Reason</th><td><?= h($app['RejectionReason']) ?></td></tr>
            </table>

            <form method="post" class="mt-3">
                <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
                <div class="mb-3">
                    <textarea name="comment" class="form-control" placeholder="Comment (required for reject)"></textarea>
                </div>

                <?php if($app['Status'] == 1): ?>
                    <button name="action" value="l1" class="btn btn-success me-2">Approve L1</button>
                <?php endif; ?>

                <?php if($app['Status'] == 2): ?>
                    <button name="action" value="l2" class="btn btn-primary me-2">Approve L2</button>
                <?php endif; ?>

                <button name="action" value="reject" class="btn btn-danger me-2">Reject</button>
                <a href="leave_manage.php" class="btn btn-secondary">Back</a>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../include/footer.php'; ?>
