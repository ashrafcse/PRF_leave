<?php
/******************************
 * Employee History (SQL Server, PDO) [PHP 5.6 compatible]
 ******************************/
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../init.php';
require_login();

/* ---------- Tiny helpers ---------- */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
function self_name(){ return strtok(basename($_SERVER['SCRIPT_NAME']), "?"); }
$self = self_name();

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$msg = ''; $msg_type = 'success';

$rows = array();

/* ---------- Base SELECT with all joins (IDs -> Names) ---------- */
$sql_base = "
SELECT TOP (1000)
  h.[HistoryID], h.[EmployeeRefID], h.[EmployeeCode],
  h.[FirstName], h.[LastName], h.[NationalID],
  h.[Email_Office], h.[Email_Personal],
  h.[Phone1], h.[Phone2],
  h.[JobTitleID], jt.JobTitleName,
  h.[DepartmentID], d.DepartmentName,
  h.[LocationID], l.LocationName,
  h.[SupervisorID_admin],
     LTRIM(RTRIM(sa.FirstName + ' ' + ISNULL(sa.LastName,''))) AS SupervisorAdminName,
  h.[SupervisorID_technical],
     LTRIM(RTRIM(st.FirstName + ' ' + ISNULL(st.LastName,''))) AS SupervisorTechName,
  h.[HireDate], h.[EndDate], h.[Blood_group], h.[Salary_increment_month],
  h.[DOB], h.[Job_Type], h.[Salary_level], h.[Salary_Steps],
  h.[Status], h.[CreatedAt], h.[CreatedBy],
  u.Username AS CreatedByName, u.Email AS CreatedByEmail,
  h.[ActionType], h.[ChangeTimestamp]
FROM [dbo].[Employees_history] AS h
LEFT JOIN [dbo].[Designation] jt ON jt.JobTitleID = h.JobTitleID
LEFT JOIN [dbo].[Departments] d ON d.DepartmentID = h.DepartmentID
LEFT JOIN [dbo].[Locations]  l ON l.LocationID   = h.LocationID
LEFT JOIN [dbo].[Employees] sa ON sa.EmployeeID   = h.SupervisorID_admin
LEFT JOIN [dbo].[Employees] st ON st.EmployeeID   = h.SupervisorID_technical
LEFT JOIN [dbo].[Users]     u  ON u.UserID        = h.CreatedBy
";

try {
  if ($q !== '') {
    // IMPORTANT: ODBC + PDO cannot reuse the same named placeholder.
    // Use positional placeholders (?) and pass the value N times.
    $sql = $sql_base . "
      WHERE (h.[EmployeeCode] LIKE ?
         OR  h.[FirstName]    LIKE ?
         OR  h.[LastName]     LIKE ?
         OR  h.[Status]       LIKE ?
         OR  h.[ActionType]   LIKE ?
         OR  jt.JobTitleName  LIKE ?
         OR  d.DepartmentName LIKE ?
         OR  l.LocationName   LIKE ?
         OR  u.Username       LIKE ?)
      ORDER BY h.[ChangeTimestamp] DESC, h.[HistoryID] DESC
    ";
    $st = $conn->prepare($sql);
    $like = '%'.$q.'%';
    $params = array($like,$like,$like,$like,$like,$like,$like,$like,$like);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $st = $conn->query($sql_base . "
      ORDER BY h.[ChangeTimestamp] DESC, h.[HistoryID] DESC
    ");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  }
} catch (PDOException $e) {
  $msg = 'Load history failed: '.h($e->getMessage());
  $msg_type = 'danger';
}

/* ---------- View ---------- */
require_once __DIR__ . '/../../include/header.php';
?>
<style>
  .page-wrap { margin:28px auto; padding:0 12px; }
  .page-title { font-weight:700; letter-spacing:.2px; }
  .card-elevated { border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 8px 24px rgba(2,6,23,.06); }
  .badge-soft { border:1px solid #e2e8f0; background:#f8fafc; border-radius:999px; padding:4px 10px; font-size:12px; }
  .btn-brand { background:#2563eb; color:#fff!important; border:none; }
  .btn-brand:hover{ background:#1d4ed8; }
  .btn-muted { background:#e5e7eb; color:#111827!important; border:none; }
  .btn-muted:hover{ background:#d1d5db; }
  .btn-view { background:#f1f5f9; border:1px solid #e2e8f0; }
  .table thead th{ background:#f8fafc; color:#334155; border-bottom:1px solid #e5e7eb; white-space:nowrap; }
  .table tbody td{ vertical-align:middle; }
  .nowrap { white-space:nowrap; }
  .text-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
  @media (max-width: 576px){
    .table thead th, .table tbody td { white-space:nowrap; }
  }
  .modal-wide .modal-dialog { max-width: 960px; }
  @media (max-width: 992px){ .modal-wide .modal-dialog { max-width: 95%; }
  }
  .kv{ display:flex; gap:6px; padding:6px 0; border-bottom:1px dashed #e5e7eb; }
  .kv .k{ width:220px; color:#475569; font-weight:600; }
  @media (max-width: 576px){
    .kv{ flex-direction:column; }
    .kv .k{ width:auto; }
  }
</style>

<div class="page-wrap">
  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
    <h1 class="page-title mb-0">Employee History</h1>
    <form method="get" class="w-100 w-md-auto" accept-charset="UTF-8">
      <div class="input-group">
        <input type="text" name="q" class="form-control" placeholder="Search code/name/status/action/department..." value="<?php echo h($q); ?>">
        <button class="btn btn-brand" type="submit">Search</button>
        <a class="btn btn-muted" href="<?php echo h($self); ?>">Reset</a>
      </div>
    </form>
  </div>

  <?php if ($msg): ?>
    <div class="alert alert-<?php echo ($msg_type==='danger'?'danger':'success'); ?> alert-dismissible fade show" role="alert">
      <?php echo $msg; ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endif; ?>

  <div class="card card-elevated">
    <div class="card-body">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
        <h5 class="mb-0">History Records</h5>
        <span class="text-muted small">Total: <?php echo count($rows); ?></span>
      </div>

      <div class="table-responsive">
        <!-- Main table: compact like employees page -->
        <table class="table table-hover align-middle">
          <thead>
            <tr>
              <th class="nowrap">#History</th>
              <th class="nowrap">Code</th>
              <th>Name</th>
              <th class="nowrap">Designation</th>
              <th class="nowrap">Department</th>
              <th class="nowrap">Location</th>
              <th class="nowrap">Status</th>
              <th class="nowrap">Action</th>
              <th class="nowrap">Changed At</th>
              <th class="nowrap">Created By</th>
              <th class="text-end nowrap">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($rows)): ?>
              <?php foreach ($rows as $r): ?>
                <?php
                  // Build JSON payload for modal (all fields)
                  $payload = array(
                    'HistoryID' => (int)$r['HistoryID'],
                    'EmployeeRefID' => (int)$r['EmployeeRefID'],
                    'EmployeeCode' => (string)$r['EmployeeCode'],
                    'FirstName' => (string)$r['FirstName'],
                    'LastName' => (string)$r['LastName'],
                    'NationalID' => (string)$r['NationalID'],
                    'Email_Office' => (string)$r['Email_Office'],
                    'Email_Personal' => (string)$r['Email_Personal'],
                    'Phone1' => (string)$r['Phone1'],
                    'Phone2' => (string)$r['Phone2'],
                    'JobTitleID' => (int)$r['JobTitleID'],
                    'JobTitleName' => (string)$r['JobTitleName'],
                    'DepartmentID' => (int)$r['DepartmentID'],
                    'DepartmentName' => (string)$r['DepartmentName'],
                    'LocationID' => (int)$r['LocationID'],
                    'LocationName' => (string)$r['LocationName'],
                    'SupervisorID_admin' => isset($r['SupervisorID_admin']) ? (int)$r['SupervisorID_admin'] : null,
                    'SupervisorAdminName' => (string)$r['SupervisorAdminName'],
                    'SupervisorID_technical' => isset($r['SupervisorID_technical']) ? (int)$r['SupervisorID_technical'] : null,
                    'SupervisorTechName' => (string)$r['SupervisorTechName'],
                    'HireDate' => (string)$r['HireDate'],
                    'EndDate' => (string)$r['EndDate'],
                    'Blood_group' => (string)$r['Blood_group'],
                    'Salary_increment_month' => (string)$r['Salary_increment_month'],
                    'DOB' => (string)$r['DOB'],
                    'Job_Type' => (string)$r['Job_Type'],
                    'Salary_level' => (string)$r['Salary_level'],
                    'Salary_Steps' => (string)$r['Salary_Steps'],
                    'Status' => (string)$r['Status'],
                    'CreatedAt' => (string)$r['CreatedAt'],
                    'CreatedBy' => isset($r['CreatedBy']) ? (int)$r['CreatedBy'] : null,
                    'CreatedByName' => (string)$r['CreatedByName'],
                    'CreatedByEmail' => (string)$r['CreatedByEmail'],
                    'ActionType' => (string)$r['ActionType'],
                    'ChangeTimestamp' => (string)$r['ChangeTimestamp']
                  );
                  $json = json_encode($payload, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
                ?>
                <tr>
                  <td class="text-mono"><?php echo (int)$r['HistoryID']; ?></td>
                  <td class="text-mono"><?php echo h($r['EmployeeCode']); ?></td>
                  <td><?php echo h(trim(($r['FirstName']?:'').' '.($r['LastName']?:''))); ?></td>
                  <td><?php echo h($r['JobTitleName']); ?></td>
                  <td><?php echo h($r['DepartmentName']); ?></td>
                  <td><?php echo h($r['LocationName']); ?></td>
                  <td>
                    <?php
                      $st = (string)$r['Status'];
                      echo $st==='Active'
                        ? '<span class="badge-soft text-success">Active</span>'
                        : '<span class="badge-soft text-secondary">'.h($st).'</span>';
                    ?>
                  </td>
                  <td><span class="badge-soft"><?php echo h($r['ActionType']); ?></span></td>
                  <td class="nowrap"><?php echo h(substr((string)$r['ChangeTimestamp'],0,19)); ?></td>
                  <td>
                    <?php
                      $nm = trim((string)$r['CreatedByName']);
                      $em = trim((string)$r['CreatedByEmail']);
                      if ($nm !== '') {
                        echo h($nm);
                        if ($em !== '') echo '<div class="small text-muted">'.h($em).'</div>';
                      } elseif (!empty($r['CreatedBy'])) {
                        echo '<span class="text-mono">#'.(int)$r['CreatedBy'].'</span>';
                      } else {
                        echo '<span class="text-muted">—</span>';
                      }
                    ?>
                  </td>
                  <td class="text-end">
                    <button type="button" class="btn btn-view btn-sm js-view"
                      data-json="<?php echo h($json); ?>">
                      View
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="11" class="text-center text-muted py-4">No history data</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade modal-wide" id="historyViewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header" style="margin-top:65px;">
        <h5 class="modal-title">History Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="hist-content"><div class="text-muted">Loading...</div></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-muted" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  // helpers
  function $(sel, root){ return (root||document).querySelector(sel); }
  function _closest(node, sel){
    // tiny closest polyfill (for older browsers)
    while (node && node !== document) {
      if (node.matches && node.matches(sel)) return node;
      node = node.parentNode;
    }
    return null;
  }
  function h(s){ var d=document.createElement('div'); d.textContent=(s==null?'':String(s)); return d.innerHTML; }
  function rowKV(k,v){
    return '<div class="kv"><div class="k">'+h(k)+'</div><div class="v">'+(v!==''?v:'<span class="text-muted">—</span>')+'</div></div>';
  }
  function fmtDateStr(s){ return s ? h(String(s).substring(0,19)) : ''; }

  var modalEl = document.getElementById('historyViewModal');
  var contentEl = document.getElementById('hist-content');

  function fillModal(data){
    var fullName = ((data.FirstName||'') + ' ' + (data.LastName||'')).replace(/\s+/g,' ').trim();
    var html = '';
    html += '<div class="mb-2"><strong>'+h(data.EmployeeCode||'')+'</strong> — '+h(fullName||'')+'</div>';
    html += rowKV('HistoryID', h(data.HistoryID));
    html += rowKV('EmployeeRefID', h(data.EmployeeRefID));
    html += rowKV('Action', h(data.ActionType||'')); 
    html += rowKV('Changed At', fmtDateStr(data.ChangeTimestamp));
    html += rowKV('Created By', h(data.CreatedByName||'') + (data.CreatedByEmail?' <span class="text-muted">('+h(data.CreatedByEmail)+')</span>':''));
    html += rowKV('Status', h(data.Status||''));
    html += rowKV('National ID', h(data.NationalID||''));
    html += rowKV('Email (Office)', h(data.Email_Office||'')); 
    html += rowKV('Email (Personal)', h(data.Email_Personal||''));
    html += rowKV('Phone1', h(data.Phone1||'')); 
    html += rowKV('Phone2', h(data.Phone2||''));
    html += rowKV('Designation', h(data.JobTitleName||'') + (data.JobTitleID?' <span class="text-muted">#'+h(data.JobTitleID)+'</span>':''));
    html += rowKV('Department', h(data.DepartmentName||'') + (data.DepartmentID?' <span class="text-muted">#'+h(data.DepartmentID)+'</span>':''));
    html += rowKV('Location', h(data.LocationName||'') + (data.LocationID?' <span class="text-muted">#'+h(data.LocationID)+'</span>':''));
    html += rowKV('Supervisor (Admin)', h(data.SupervisorAdminName||'') + (data.SupervisorID_admin?' <span class="text-muted">#'+h(data.SupervisorID_admin)+'</span>':''));
    html += rowKV('Supervisor (Technical)', h(data.SupervisorTechName||'') + (data.SupervisorID_technical?' <span class="text-muted">#'+h(data.SupervisorID_technical)+'</span>':''));
    html += rowKV('Hire Date', fmtDateStr(data.HireDate));
    html += rowKV('End Date', fmtDateStr(data.EndDate));
    html += rowKV('DOB', fmtDateStr(data.DOB));
    html += rowKV('Blood Group', h(data.Blood_group||''));
    html += rowKV('Increment Month', h(data.Salary_increment_month||''));
    html += rowKV('Job Type', h(data.Job_Type||''));
    html += rowKV('Salary Level', h(data.Salary_level||''));
    html += rowKV('Salary Steps', h(data.Salary_Steps||''));
    html += rowKV('Created At', fmtDateStr(data.CreatedAt));
    contentEl.innerHTML = html;
  }

  function openModal(){
    // Bootstrap 5
    if (window.bootstrap && typeof window.bootstrap.Modal === 'function') {
      var instance = bootstrap.Modal.getOrCreateInstance(modalEl);
      instance.show(); return;
    }
    // Bootstrap 4 (jQuery)
    if (window.jQuery && jQuery.fn && typeof jQuery.fn.modal === 'function') {
      jQuery(modalEl).modal('show'); return;
    }
    // Fallback
    modalEl.classList.add('show');
    modalEl.style.display = 'block';
    document.body.classList.add('modal-open');
  }

  function closeFallback(){
    if (!window.bootstrap && !(window.jQuery && jQuery.fn && jQuery.fn.modal)) {
      modalEl.classList.remove('show');
      modalEl.style.display = 'none';
      document.body.classList.remove('modal-open');
    }
  }

  // Event delegation for all "View" buttons
  document.addEventListener('click', function(e){
    var btn = e.target ? (_closest(e.target, '.js-view')) : null;
    if(!btn) return;

    var json = btn.getAttribute('data-json') || '{}';
    var data;
    try { data = JSON.parse(json); }
    catch(err){
      if (contentEl) contentEl.innerHTML = '<div class="text-danger">Failed to parse row data.</div>';
      openModal(); return;
    }
    fillModal(data);
    openModal();
  });

  // Fallback close if bootstrap JS not present
  modalEl.addEventListener('click', function(e){
    if (e.target.matches('.btn-close') || e.target.getAttribute('data-bs-dismiss') === 'modal'){
      closeFallback();
    }
  });
})();
</script>

<?php require_once __DIR__ . '/../../include/footer.php'; ?>
