<?php
// login.php — glassmorphism card, compact width, icons, strong shadow
require_once __DIR__ . '/init.php';

if (current_user()) {
  $next = isset($_GET['next']) ? $_GET['next'] : 'dashboard.php';
  if (!is_safe_relative_path($next)) $next = 'dashboard.php';
  header('Location: ' . $next);
  exit;
}

$err  = '';
$next_from_query = isset($_GET['next']) ? $_GET['next'] : '';
if (!is_safe_relative_path($next_from_query)) $next_from_query = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = isset($_POST['username']) ? trim($_POST['username']) : '';
  $password = isset($_POST['password']) ? (string)$_POST['password'] : '';
  $next     = isset($_POST['next']) ? trim($_POST['next']) : '';

  // Instead of array destructuring
  $loginResult = try_login($conn, $username, $password);
  $ok  = isset($loginResult[0]) ? $loginResult[0] : false;
  $msg = isset($loginResult[1]) ? $loginResult[1] : '';

  if ($ok) {
    $target = (is_safe_relative_path($next) && $next !== '') ? $next : 'dashboard.php';
    header('Location: ' . $target);
    exit;
  } else {
    $err = $msg ? $msg : 'Invalid credentials.';
  }
}

if (!function_exists('h')) {
  function h($s){
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Sign in · PRF Asset Management</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- Font Awesome (icons) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
<!-- Bootstrap Icons (optional; used for subtle mix) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

<style>
  :root{
    --page:#0b1220;
    --ink:#e2e8f0;
    --muted:#94a3b8;
    --accent1:#6366f1;
    --accent2:#06b6d4;
    --field:#0b1220;
    --ring:rgba(99,102,241,.45);
    --border:rgba(255,255,255,.12);
    --error:#fecaca;
  }
  *{box-sizing:border-box}
  html,body{height:100%}
  body{
    margin:0; font-family:ui-sans-serif, system-ui, Segoe UI, Roboto, Arial; color:var(--ink);
    background: radial-gradient(60vw 40vw at 15% -10%, rgba(79,70,229,.35), transparent 60%),
                radial-gradient(55vw 40vw at 110% 0%, rgba(6,182,212,.28), transparent 60%),
                var(--page);
    display:flex; align-items:center; justify-content:center; padding:24px; overflow:hidden;
  }
  .blob{
    position:fixed; inset:auto -15% -35% auto; width:60vmax; height:60vmax; border-radius:50%;
    background: radial-gradient(closest-side, rgba(14,165,233,.28), transparent 70%);
    filter: blur(40px); opacity:.7; animation: drift 26s ease-in-out infinite alternate;
    transform: translateZ(0);
  }
  @keyframes drift{ from{ transform: translate(10%,10%) rotate(0deg) scale(1);} to{ transform: translate(-5%,-8%) rotate(20deg) scale(1.15);} }

  .card{
    width:100%; max-width:420px;
    border-radius:20px; padding:1px; position:relative;
    background: linear-gradient(140deg, rgba(99,102,241,.8), rgba(6,182,212,.8));
    box-shadow: 0 30px 80px rgba(0,0,0,.55);
  }
  .card::before{
    content:""; position:absolute; inset:0; padding:1.5px; border-radius:20px;
    background: linear-gradient(140deg, rgba(255,255,255,.55), rgba(255,255,255,.08));
    -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
    -webkit-mask-composite: xor; mask-composite: exclude;
  }
  .panel{
    border-radius:19px; padding:22px 20px; backdrop-filter: blur(14px) saturate(120%);
    background: rgba(15,23,42,.35);
    border:1px solid var(--border);
  }

  .brand{ display:flex; align-items:center; gap:12px; margin-bottom:6px; }
  .brand img{ height:64px; width:auto; border-radius:12px; }
  .brand .title{ font-weight:800; font-size:22px; letter-spacing:.2px; }
  .subtitle{ margin:0 0 16px; color:var(--muted); }

  .alert{ background:rgba(248,113,113,.15); color:#fecaca; border:1px solid rgba(248,113,113,.35);
          padding:10px 12px; border-radius:12px; font-size:13px; margin-bottom:14px; }

  .group{ margin-bottom:14px; }
  label{ display:block; font-size:12px; color:var(--muted); margin-bottom:6px; }

  .input-wrap{ position:relative; }
  .input-wrap i{ position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#93c5fd; font-size:15px; }
  .input{
    width:100%; padding:12px 44px 12px 40px; border:1px solid var(--border);
    border-radius:12px; background: rgba(2,6,23,.6); color:#e5e7eb; font-size:15px; outline:none;
    transition:border-color .15s ease, box-shadow .15s ease, background .15s ease;
  }
  .input::placeholder{ color:#9ca3af; }
  .input:focus{ border-color:var(--ring); box-shadow:0 0 0 4px rgba(99,102,241,.25); background: rgba(2,6,23,.72); }

  .toggle{
    position:absolute; right:6px; top:3px; bottom:6px;
    display:flex; align-items:center; justify-content:center;
    width:36px; height:36px; background: rgba(99,102,241,.18);
    border:1px solid rgba(148,163,184,.25); border-radius:10px; color:#e2e8f0; cursor:pointer; font-size:16px;
    backdrop-filter: blur(3px);
  }

  .btn{
    width:100%; padding:12px 14px; border:none; border-radius:14px; cursor:pointer; color:#0b1220; font-weight:800; letter-spacing:.2px; font-size:15px;
    background: linear-gradient(135deg, var(--accent1), var(--accent2));
    box-shadow: 0 16px 36px rgba(6,182,212,.25), 0 8px 20px rgba(99,102,241,.20);
    display:inline-flex; align-items:center; justify-content:center; gap:8px;
  }
  .btn i{ font-size:16px; }
  .btn:hover{ filter:brightness(1.05); transform: translateY(-1px); }
  .btn:active{ transform: translateY(0); }

  .row{ display:flex; justify-content:space-between; align-items:center; gap:8px; margin-top:12px; color:var(--muted); font-size:12px; }
  .foot{ margin-top:14px; text-align:center; color:#7c8aa5; font-size:12px; }
  a.link{ color:#7aa2ff; text-decoration:none; }
  a.link:hover{ text-decoration:underline; }

  @media (max-width:420px){ .panel{ padding:18px; } .brand img{ height:56px; } }
</style>
</head>
<body>
  <div class="blob" aria-hidden="true"></div>

  <div class="card" role="presentation">
    <div class="panel">
      <div class="brand">
        <img src="<?php echo htmlspecialchars(asset('/../assets/logo.png')); ?>" alt="Brand logo">
        <div class="title">Sign in</div>
      </div>
      <p class="subtitle">Use your credentials to access PRF Asset Management.</p>

      <?php if ($err): ?>
        <div class="alert"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> <?php echo h($err); ?></div>
      <?php endif; ?>

      <form method="post" action="<?php echo h(BASE_URL.'index.php'); ?>" autocomplete="off" novalidate>
        <input type="hidden" name="next" value="<?php echo h($next_from_query); ?>">

        <div class="group">
          <label for="username">Username</label>
          <div class="input-wrap">
            <i class="fa-solid fa-user" aria-hidden="true"></i>
            <input class="input" id="username" name="username" type="text" required autocomplete="username" placeholder="your.username" autofocus>
          </div>
        </div>

        <div class="group">
          <label for="password">Password</label>
          <div class="input-wrap">
            <i  class="fa-solid fa-lock" aria-hidden="true"></i>
            <input class="input" id="password" name="password" type="password" required autocomplete="current-password" placeholder="••••••••">
            <button type="button" class="toggle" aria-controls="password" aria-label="Show password" title="Show password" onclick="togglePw(this)">
              <i style="left: 10px!important;" class="fa-solid fa-eye" aria-hidden="true"></i>
            </button>
          </div>
        </div>

        <button class="btn" type="submit">
          <i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i>
          <span>Login</span>
        </button>

        <div class="row">
          <label style="display:flex; align-items:center; gap:6px; cursor:pointer;">
            <input type="checkbox" style="accent-color:#6366f1;"> <span>Remember me</span>
          </label>
          <a class="link" href="#">Forgot password?</a>
        </div>

        <div class="foot">© <?php echo date('Y'); ?> PRF Asset Management</div>
      </form>
    </div>
  </div>

<script>
function togglePw(btn){
  var input = document.getElementById(btn.getAttribute('aria-controls') || 'password');
  if(!input) return;
  var isText = input.type === 'text';
  input.type = isText ? 'password' : 'text';
  var icon = btn.querySelector('i');
  if(icon){ icon.className = isText ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash'; }
  btn.setAttribute('aria-label', isText ? 'Show password' : 'Hide password');
  btn.title = isText ? 'Show password' : 'Hide password';
  input.focus();
}
</script>

</body>
</html>
