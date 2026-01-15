<?php
/***********************************************
 * Asset History (SQL Server, PDO) [PHP 5.6]
 ***********************************************/
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
   IMPORTANT: table names aligned to your DB
   - Models        => dbo.AssetModels (alias m)
   - Categories    => dbo.AssetCategories (alias c)
   - Locations     => dbo.Locations
   - Employees     => dbo.Employees (holder)
   - Users         => dbo.Users
*/
$sql_base = "
SELECT TOP (1000)
  h.[HistoryID], h.[AssetRefID],
  h.[AssetTag], h.[SerialNumber], h.[AssetName],
  h.[ModelID], m.[ModelName],
  h.[CategoryID], c.[CategoryName],
  h.[CurrentLocationID], l.[LocationName],
  h.[CurrentHolderEmployeeID],
     LTRIM(RTRIM(e.FirstName + ' ' + ISNULL(e.LastName,''))) AS CurrentHolderName,
  h.[Description], h.[PurchaseDate], h.[PurchasePrice],
  h.[Status], h.[IsConsumable], h.[IsRechargeable],
  h.[CreatedAt], h.[CreatedBy],
  u.[Username] AS CreatedByName, u.[Email] AS CreatedByEmail,
  h.[ActionType], h.[ChangeTimestamp]
FROM [dbo].[Assets_history] AS h
LEFT JOIN [dbo].[AssetModels]     AS m ON m.[ModelID]    = h.[ModelID]
LEFT JOIN [dbo].[AssetCategories] AS c ON c.[CategoryID] = h.[CategoryID]
LEFT JOIN [dbo].[Locations]       AS l ON l.[LocationID] = h.[CurrentLocationID]
LEFT JOIN [dbo].[Employees]       AS e ON e.[EmployeeID] = h.[CurrentHolderEmployeeID]
LEFT JOIN [dbo].[Users]           AS u ON u.[UserID]     = h.[CreatedBy]
";

try {
  if ($q !== '') {
    // Use positional ? placeholders (sqlsrv/odbc best practice)
    $sql = $sql_base . "
      WHERE (h.[AssetTag]         LIKE ?
         OR  h.[SerialNumber]     LIKE ?
         OR  h.[AssetName]        LIKE ?
         OR  h.[Status]           LIKE ?
         OR  h.[ActionType]       LIKE ?
         OR  m.[ModelName]        LIKE ?
         OR  c.[CategoryName]     LIKE ?
         OR  l.[LocationName]     LIKE ?
         OR  u.[Username]         LIKE ?
         OR  e.[FirstName]        LIKE ?
         OR  e.[LastName]         LIKE ?)
      ORDER BY h.[ChangeTimestamp] DESC, h.[HistoryID] DESC
    ";
    $st = $conn->prepare($sql);
    $like = '%'.$q.'%';
    $params = array($like,$like,$like,$like,$like,$like,$like,$like,$like,$like,$like);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $st = $conn->query($sql_base . "
      ORDER BY h.[ChangeTimestamp] DESC, h.[HistoryID] DESC
    ");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  }
} catch (PDOException $e) {
  $msg = 'Load asset history failed: '.h($e->getMessage());
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
    <h1 class="page-title mb-0">Asset History</h1>
    <form method="get" class="w-100 w-md-auto" accept-charset="UTF-8">
      <div class="input-group">
        <input type="text" name="q" class="form-control" placeholder="Search tag/serial/name/status/action/model/category/location/holder..." value="<?php echo h($q); ?>">
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
              <th class="nowrap">Model</th>
              <th class="nowrap">Category</th>
              <th class="nowrap">Location</th>
              <th class="nowrap">Holder</th>
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
                  $payload = array(
                    'HistoryID' => (int)$r['HistoryID'],
                    'AssetRefID' => (int)$r['AssetRefID'],
                    'AssetTag' => (string)$r['AssetTag'],
                    'SerialNumber' => (string)$r['SerialNumber'],
                    'AssetName' => (string)$r['AssetName'],
                    'ModelID' => isset($r['ModelID']) ? (int)$r['ModelID'] : null,
                    'ModelName' => (string)$r['ModelName'],
                    'CategoryID' => isset($r['CategoryID']) ? (int)$r['CategoryID'] : null,
                    'CategoryName' => (string)$r['CategoryName'],
                    'CurrentLocationID' => isset($r['CurrentLocationID']) ? (int)$r['CurrentLocationID'] : null,
                    'LocationName' => (string)$r['LocationName'],
                    'CurrentHolderEmployeeID' => isset($r['CurrentHolderEmployeeID']) ? (int)$r['CurrentHolderEmployeeID'] : null,
                    'CurrentHolderName' => (string)$r['CurrentHolderName'],
                    'Description' => (string)$r['Description'],
                    'PurchaseDate' => (string)$r['PurchaseDate'],
                    'PurchasePrice' => (string)$r['PurchasePrice'],
                    'Status' => (string)$r['Status'],
                    'IsConsumable' => $r['IsConsumable'],
                    'IsRechargeable' => $r['IsRechargeable'],
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
                  <td class="text-mono"><?php echo h($r['AssetTag']); ?></td>
                  <td><?php echo h($r['AssetName']); ?></td>
                  <td><?php echo h($r['ModelName']); ?></td>
                  <td><?php echo h($r['CategoryName']); ?></td>
                  <td><?php echo h($r['LocationName']); ?></td>
                  <td><?php echo h($r['CurrentHolderName']); ?></td>
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
              <tr><td colspan="12" class="text-center text-muted py-4">No history data</td></tr>
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
        <h5 class="modal-title">Asset History Details</h5>
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
    html += rowKV('AssetRefID', h(data.AssetRefID));
    html += rowKV('Action', h(data.ActionType||'')); 
    html += rowKV('Changed At', fmtDateStr(data.ChangeTimestamp));
    html += rowKV('Created By', h(data.CreatedByName||'') + (data.CreatedByEmail?' <span class="text-muted">('+h(data.CreatedByEmail)+')</span>':''));
    html += rowKV('Status', h(data.Status||''));
    html += rowKV('Model', h(data.ModelName||'') + (data.ModelID?' <span class="text-muted">#'+h(data.ModelID)+'</span>':''));
    html += rowKV('Category', h(data.CategoryName||'') + (data.CategoryID?' <span class="text-muted">#'+h(data.CategoryID)+'</span>':''));
    html += rowKV('Location', h(data.LocationName||'') + (data.CurrentLocationID?' <span class="text-muted">#'+h(data.CurrentLocationID)+'</span>':''));
    html += rowKV('Holder', h(data.CurrentHolderName||'') + (data.CurrentHolderEmployeeID?' <span class="text-muted">#'+h(data.CurrentHolderEmployeeID)+'</span>':''));
    html += rowKV('Serial Number', h(data.SerialNumber||''));
    html += rowKV('Description', h(data.Description||''));
    html += rowKV('Purchase Date', fmtDateStr(data.PurchaseDate));
    html += rowKV('Purchase Price', h(data.PurchasePrice||''));
    html += rowKV('Consumable', fmtBool(data.IsConsumable));
    html += rowKV('Rechargeable', fmtBool(data.IsRechargeable));
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
