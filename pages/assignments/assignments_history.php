<?php
/****************************************************
 * Assignment History (SQL Server, PDO) [PHP 5.6]
 * Same UX as your Asset/Employee history pages
 ****************************************************/
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../init.php';
require_login();

/* -------- Helpers -------- */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
function self_name(){ return strtok(basename($_SERVER['SCRIPT_NAME']), "?"); }
$self = self_name();

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$msg = ''; $msg_type = 'success';
$rows = array();

/* -------- Base SELECT (IDs -> Names via LEFT JOINs) --------
   Tables used:
   - dbo.Assignments_history (h)
   - dbo.Assets               (a)  -> AssetTag, AssetName
   - dbo.Employees            (e)  -> AssignedToEmployee full name
   - dbo.Locations            (l)  -> AssignedToLocation name
   - dbo.Users                (ub) -> AssignedBy user
   - dbo.Users                (uc) -> CreatedBy user
*/
$sql_base = "
SELECT TOP (1000)
  h.[HistoryID],
  h.[AssignmentRefID],
  h.[AssetID],
  a.[AssetTag], a.[AssetName],

  h.[AssignedToEmployeeID],
    LTRIM(RTRIM(e.FirstName + ' ' + ISNULL(e.LastName,''))) AS AssignedToEmployeeName,

  h.[AssignedToLocationID],
    l.[LocationName] AS AssignedToLocationName,

  h.[AssignedByUserID],
    ub.[Username] AS AssignedByUserName,
    ub.[Email]    AS AssignedByUserEmail,

  h.[AssignedAt],
  h.[ExpectedReturnDate],
  h.[ConditionAtAssign],
  h.[IsActive],
  h.[Notes],

  h.[CreatedAt], h.[CreatedBy],
    uc.[Username] AS CreatedByName,
    uc.[Email]    AS CreatedByEmail,

  h.[ActionType],
  h.[ChangeTimestamp]
FROM [dbo].[Assignments_history] AS h
LEFT JOIN [dbo].[Assets]     AS a  ON a.[AssetID]     = h.[AssetID]
LEFT JOIN [dbo].[Employees]  AS e  ON e.[EmployeeID]  = h.[AssignedToEmployeeID]
LEFT JOIN [dbo].[Locations]  AS l  ON l.[LocationID]  = h.[AssignedToLocationID]
LEFT JOIN [dbo].[Users]      AS ub ON ub.[UserID]     = h.[AssignedByUserID]
LEFT JOIN [dbo].[Users]      AS uc ON uc.[UserID]     = h.[CreatedBy]
";

try {
  if ($q !== '') {
    // Use positional ? placeholders (ODBC/PDO-friendly)
    $sql = $sql_base . "
      WHERE (
             a.[AssetTag]             LIKE ?
          OR a.[AssetName]            LIKE ?
          OR e.[FirstName]            LIKE ?
          OR e.[LastName]             LIKE ?
          OR l.[LocationName]         LIKE ?
          OR h.[ActionType]           LIKE ?
          OR h.[ConditionAtAssign]    LIKE ?
          OR h.[Notes]                LIKE ?
          OR ub.[Username]            LIKE ?
          OR uc.[Username]            LIKE ?
      )
      ORDER BY h.[ChangeTimestamp] DESC, h.[HistoryID] DESC
    ";
    $st = $conn->prepare($sql);
    $like = '%'.$q.'%';
    $params = array($like,$like,$like,$like,$like,$like,$like,$like,$like,$like);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $st = $conn->query($sql_base . "
      ORDER BY h.[ChangeTimestamp] DESC, h.[HistoryID] DESC
    ");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  }
} catch (PDOException $e) {
  $msg = 'Load assignment history failed: '.h($e->getMessage());
  $msg_type = 'danger';
}

/* -------- View -------- */
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
  @media (max-width: 992px){ .modal-wide .modal-dialog { max-width: 95%; } }
  .kv{ display:flex; gap:6px; padding:6px 0; border-bottom:1px dashed #e5e7eb; }
  .kv .k{ width:220px; color:#475569; font-weight:600; }
  @media (max-width: 576px){
    .kv{ flex-direction:column; }
    .kv .k{ width:auto; }
  }
</style>

<div class="page-wrap">
  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
    <h1 class="page-title mb-0">Assignment History</h1>
    <form method="get" class="w-100 w-md-auto" accept-charset="UTF-8">
      <div class="input-group">
        <input type="text" name="q" class="form-control" placeholder="Search tag/name/employee/location/action/notes/condition..." value="<?php echo h($q); ?>">
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
        <table class="table table-hover align-middle">
          <thead>
            <tr>
              <th class="nowrap">#History</th>
              <th class="nowrap">Asset Tag</th>
              <th>Asset Name</th>
              <th class="nowrap">Assigned To</th>
              <th class="nowrap">Location</th>
              <th class="nowrap">Assigned By</th>
              <th class="nowrap">Assigned At</th>
              <th class="nowrap">Return By</th>
              <th class="nowrap">Active</th>
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
                  $payload = array(
                    'HistoryID'               => (int)$r['HistoryID'],
                    'AssignmentRefID'         => (int)$r['AssignmentRefID'],

                    'AssetID'                 => isset($r['AssetID']) ? (int)$r['AssetID'] : null,
                    'AssetTag'                => (string)$r['AssetTag'],
                    'AssetName'               => (string)$r['AssetName'],

                    'AssignedToEmployeeID'    => isset($r['AssignedToEmployeeID']) ? (int)$r['AssignedToEmployeeID'] : null,
                    'AssignedToEmployeeName'  => (string)$r['AssignedToEmployeeName'],

                    'AssignedToLocationID'    => isset($r['AssignedToLocationID']) ? (int)$r['AssignedToLocationID'] : null,
                    'AssignedToLocationName'  => (string)$r['AssignedToLocationName'],

                    'AssignedByUserID'        => isset($r['AssignedByUserID']) ? (int)$r['AssignedByUserID'] : null,
                    'AssignedByUserName'      => (string)$r['AssignedByUserName'],
                    'AssignedByUserEmail'     => (string)$r['AssignedByUserEmail'],

                    'AssignedAt'              => (string)$r['AssignedAt'],
                    'ExpectedReturnDate'      => (string)$r['ExpectedReturnDate'],
                    'ConditionAtAssign'       => (string)$r['ConditionAtAssign'],
                    'IsActive'                => $r['IsActive'],
                    'Notes'                   => (string)$r['Notes'],

                    'CreatedAt'               => (string)$r['CreatedAt'],
                    'CreatedBy'               => isset($r['CreatedBy']) ? (int)$r['CreatedBy'] : null,
                    'CreatedByName'           => (string)$r['CreatedByName'],
                    'CreatedByEmail'          => (string)$r['CreatedByEmail'],

                    'ActionType'              => (string)$r['ActionType'],
                    'ChangeTimestamp'         => (string)$r['ChangeTimestamp']
                  );
                  $json = json_encode($payload, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
                ?>
                <tr>
                  <td class="text-mono"><?php echo (int)$r['HistoryID']; ?></td>
                  <td class="text-mono"><?php echo h($r['AssetTag']); ?></td>
                  <td><?php echo h($r['AssetName']); ?></td>
                  <td><?php echo h($r['AssignedToEmployeeName']); ?></td>
                  <td><?php echo h($r['AssignedToLocationName']); ?></td>
                  <td>
                    <?php
                      $nm = trim((string)$r['AssignedByUserName']);
                      $em = trim((string)$r['AssignedByUserEmail']);
                      if ($nm !== '') {
                        echo h($nm);
                        if ($em !== '') echo '<div class="small text-muted">'.h($em).'</div>';
                      } elseif (!empty($r['AssignedByUserID'])) {
                        echo '<span class="text-mono">#'.(int)$r['AssignedByUserID'].'</span>';
                      } else {
                        echo '<span class="text-muted">—</span>';
                      }
                    ?>
                  </td>
                  <td class="nowrap"><?php echo h(substr((string)$r['AssignedAt'],0,19)); ?></td>
                  <td class="nowrap"><?php echo h(substr((string)$r['ExpectedReturnDate'],0,19)); ?></td>
                  <td>
                    <?php
                      $b = $r['IsActive'];
                      echo ($b===1 || $b===true || $b==='1')
                        ? '<span class="badge-soft text-success">Yes</span>'
                        : '<span class="badge-soft text-secondary">No</span>';
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
              <tr><td colspan="13" class="text-center text-muted py-4">No history data</td></tr>
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
        <h5 class="modal-title">Assignment History Details</h5>
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
  function _closest(node, sel){
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
  function fmtBool(b){ return (b===1||b===true||b==='1') ? '<span class="badge-soft text-success">Yes</span>' : '<span class="badge-soft text-secondary">No</span>'; }

  var modalEl = document.getElementById('historyViewModal');
  var contentEl = document.getElementById('hist-content');

  function fillModal(data){
    var html = '';
    html += '<div class="mb-2"><strong>'+h(data.AssetTag||'')+'</strong> — '+h(data.AssetName||'')+'</div>';
    html += rowKV('HistoryID', h(data.HistoryID));
    html += rowKV('AssignmentRefID', h(data.AssignmentRefID));
    html += rowKV('Action', h(data.ActionType||'')); 
    html += rowKV('Changed At', fmtDateStr(data.ChangeTimestamp));
    html += rowKV('Assigned At', fmtDateStr(data.AssignedAt));
    html += rowKV('Expected Return', fmtDateStr(data.ExpectedReturnDate));
    html += rowKV('Assigned To', h(data.AssignedToEmployeeName||'') + (data.AssignedToEmployeeID?' <span class="text-muted">#'+h(data.AssignedToEmployeeID)+'</span>':''));
    html += rowKV('Location', h(data.AssignedToLocationName||'') + (data.AssignedToLocationID?' <span class="text-muted">#'+h(data.AssignedToLocationID)+'</span>':''));
    html += rowKV('Assigned By', h(data.AssignedByUserName||'') + (data.AssignedByUserEmail?' <span class="text-muted">('+h(data.AssignedByUserEmail)+')</span>':''));
    html += rowKV('Condition', h(data.ConditionAtAssign||''));
    html += rowKV('Active', fmtBool(data.IsActive));
    html += rowKV('Notes', h(data.Notes||''));
    html += rowKV('Created By', h(data.CreatedByName||'') + (data.CreatedByEmail?' <span class="text-muted">('+h(data.CreatedByEmail)+')</span>':''));
    html += rowKV('Created At', fmtDateStr(data.CreatedAt));
    contentEl.innerHTML = html;
  }

  function openModal(){
    if (window.bootstrap && typeof window.bootstrap.Modal === 'function') {
      var instance = bootstrap.Modal.getOrCreateInstance(modalEl);
      instance.show(); return;
    }
    if (window.jQuery && jQuery.fn && typeof jQuery.fn.modal === 'function') {
      jQuery(modalEl).modal('show'); return;
    }
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

  modalEl.addEventListener('click', function(e){
    if (e.target.matches('.btn-close') || e.target.getAttribute('data-bs-dismiss') === 'modal'){
      closeFallback();
    }
  });
})();
</script>

<?php require_once __DIR__ . '/../../include/footer.php'; ?>
