<?php
// login.php — Modern responsive glassmorphism design with your logo
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
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<title>Sign in · PRF Portal</title>
<meta name="description" content="PRF Leave Management Portal - Secure Login">

<!-- Font Awesome Icons -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<!-- Google Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
  :root {
    /* Modern Color Palette */
    --primary: #6366f1;
    --primary-dark: #4f46e5;
    --secondary: #06b6d4;
    --secondary-dark: #0891b2;
    --success: #10b981;
    --error: #ef4444;
    --warning: #f59e0b;
    
    /* Background & Surface */
    --bg-primary: #0b1120;
    --bg-secondary: #1a2036;
    --bg-surface: rgba(30, 41, 59, 0.7);
    --bg-surface-solid: #1e293b;
    
    /* Text Colors */
    --text-primary: #f1f5f9;
    --text-secondary: #94a3b8;
    --text-muted: #64748b;
    
    /* Border & Shadow */
    --border-light: rgba(255, 255, 255, 0.1);
    --border-medium: rgba(255, 255, 255, 0.15);
    --border-strong: rgba(255, 255, 255, 0.2);
    --shadow-lg: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
    --shadow-xl: 0 35px 60px -15px rgba(0, 0, 0, 0.6);
    --shadow-primary: 0 0 40px rgba(99, 102, 241, 0.25);
    
    /* Animation */
    --transition-fast: 150ms cubic-bezier(0.4, 0, 0.2, 1);
    --transition-normal: 250ms cubic-bezier(0.4, 0, 0.2, 1);
    --transition-slow: 350ms cubic-bezier(0.4, 0, 0.2, 1);
  }

  * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
  }

  html {
    font-size: 16px;
    height: 100%;
  }

  body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: var(--bg-primary);
    color: var(--text-primary);
    min-height: 100vh;
    overflow-x: hidden;
    position: relative;
    line-height: 1.5;
    padding: 1rem;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  /* Animated Background */
  .background-animation {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: -1;
    overflow: hidden;
  }

  .gradient-orb {
    position: absolute;
    border-radius: 50%;
    filter: blur(60px);
    opacity: 0.6;
    animation: float 20s ease-in-out infinite;
  }

  .orb-1 {
    width: 60vmax;
    height: 60vmax;
    background: radial-gradient(circle at center, rgba(99, 102, 241, 0.4), transparent 70%);
    top: -20%;
    left: -10%;
    animation-delay: 0s;
  }

  .orb-2 {
    width: 50vmax;
    height: 50vmax;
    background: radial-gradient(circle at center, rgba(6, 182, 212, 0.3), transparent 70%);
    bottom: -20%;
    right: -10%;
    animation-delay: -5s;
  }

  .orb-3 {
    width: 40vmax;
    height: 40vmax;
    background: radial-gradient(circle at center, rgba(139, 92, 246, 0.2), transparent 70%);
    top: 50%;
    left: 80%;
    animation-delay: -10s;
  }

  @keyframes float {
    0%, 100% {
      transform: translate(0, 0) scale(1);
    }
    33% {
      transform: translate(5%, 10%) scale(1.05);
    }
    66% {
      transform: translate(-5%, -8%) scale(0.95);
    }
  }

  /* Login Container */
  .login-container {
    width: 100%;
    max-width: 420px;
    margin: 2rem auto;
    position: relative;
  }

  /* Glass Card */
  .glass-card {
    background: var(--bg-surface);
    backdrop-filter: blur(20px) saturate(180%);
    -webkit-backdrop-filter: blur(20px) saturate(180%);
    border: 1px solid var(--border-light);
    border-radius: 24px;
    padding: 2rem;
    box-shadow: var(--shadow-xl), var(--shadow-primary);
    position: relative;
    overflow: hidden;
  }

  .glass-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--primary), var(--secondary));
    border-radius: 24px 24px 0 0;
  }

  /* Keep your original brand styling */
  .brand {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 24px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--border-light);
  }

  .brand img {
    height: 64px;
    width: auto;
    border-radius: 12px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
    border: 2px solid rgba(255, 255, 255, 0.1);
  }

  .brand-text {
    flex: 1;
  }

  .brand-title {
    font-size: 24px;
    font-weight: 800;
    background: linear-gradient(135deg, var(--text-primary), var(--text-secondary));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    margin-bottom: 4px;
    letter-spacing: -0.5px;
  }

  .brand-subtitle {
    color: var(--text-muted);
    font-size: 14px;
    font-weight: 500;
  }

  /* Alert */
  .alert {
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.2);
    border-radius: 16px;
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    animation: slideIn 0.3s ease-out;
  }

  .alert i {
    color: var(--error);
    font-size: 1.125rem;
  }

  .alert p {
    color: #fecaca;
    font-size: 0.875rem;
    flex: 1;
  }

  @keyframes slideIn {
    from {
      opacity: 0;
      transform: translateY(-10px);
    }
    to {
      opacity: 1;
      transform: translateY(0);
    }
  }

  /* Form */
  .login-form {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
  }

  .form-group {
    position: relative;
  }

  .form-label {
    display: block;
    margin-bottom: 0.5rem;
    color: var(--text-secondary);
    font-size: 0.875rem;
    font-weight: 500;
  }

  .input-container {
    position: relative;
  }

  .input-icon {
    position: absolute;
    left: 1rem;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    font-size: 1.125rem;
    z-index: 2;
    transition: color var(--transition-fast);
  }

  .form-input {
    width: 100%;
    padding: 1rem 1rem 1rem 3rem;
    background: var(--bg-surface-solid);
    border: 2px solid var(--border-light);
    border-radius: 14px;
    color: var(--text-primary);
    font-size: 1rem;
    font-family: inherit;
    transition: all var(--transition-fast);
    outline: none;
  }

  .form-input:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.2);
    background: rgba(30, 41, 59, 0.9);
  }

  .form-input:focus + .input-icon {
    color: var(--primary);
  }

  .password-toggle {
    position: absolute;
    right: 1rem;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: var(--text-muted);
    cursor: pointer;
    font-size: 1.125rem;
    padding: 0.25rem;
    transition: color var(--transition-fast);
    border-radius: 6px;
  }

  .password-toggle:hover {
    color: var(--primary);
    background: rgba(99, 102, 241, 0.1);
  }

  /* Remember Me & Forgot Password */
  .form-options {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 0.5rem;
  }

  .remember-me {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
  }

  .remember-me input[type="checkbox"] {
    width: 18px;
    height: 18px;
    border-radius: 4px;
    border: 2px solid var(--border-medium);
    background: var(--bg-surface-solid);
    cursor: pointer;
    appearance: none;
    position: relative;
    transition: all var(--transition-fast);
  }

  .remember-me input[type="checkbox"]:checked {
    background: var(--primary);
    border-color: var(--primary);
  }

  .remember-me input[type="checkbox"]:checked::after {
    content: '✓';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    color: white;
    font-size: 12px;
    font-weight: bold;
  }

  .remember-me span {
    color: var(--text-secondary);
    font-size: 0.875rem;
    user-select: none;
  }

  .forgot-password {
    color: var(--secondary);
    text-decoration: none;
    font-size: 0.875rem;
    font-weight: 500;
    transition: color var(--transition-fast);
  }

  .forgot-password:hover {
    color: var(--secondary-dark);
    text-decoration: underline;
  }

  /* Submit Button */
  .submit-btn {
    position: relative;
    padding: 1rem 2rem;
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    border: none;
    border-radius: 14px;
    color: white;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: all var(--transition-normal);
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    margin-top: 0.5rem;
  }

  .submit-btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
    transition: left var(--transition-slow);
  }

  .submit-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 32px rgba(99, 102, 241, 0.4);
  }

  .submit-btn:hover::before {
    left: 100%;
  }

  .submit-btn:active {
    transform: translateY(0);
  }

  .submit-btn i {
    font-size: 1.125rem;
  }

  /* Footer */
  .login-footer {
    text-align: center;
    margin-top: 2rem;
    padding-top: 1.5rem;
    border-top: 1px solid var(--border-light);
  }

  .copyright {
    color: var(--text-muted);
    font-size: 0.75rem;
  }

  /* Responsive Design */
  @media (max-width: 640px) {
    body {
      padding: 1rem;
    }
    
    .glass-card {
      padding: 1.75rem;
      border-radius: 20px;
    }
    
    .brand {
      flex-direction: column;
      text-align: center;
      gap: 1rem;
      margin-bottom: 1.5rem;
    }
    
    .brand img {
      height: 56px;
    }
    
    .brand-title {
      font-size: 22px;
    }
    
    .brand-subtitle {
      font-size: 13px;
    }
    
    .form-options {
      flex-direction: column;
      align-items: flex-start;
      gap: 1rem;
    }
    
    .forgot-password {
      align-self: flex-end;
    }
  }

  @media (max-width: 480px) {
    .glass-card {
      padding: 1.5rem;
    }
    
    .submit-btn {
      padding: 0.875rem 1.5rem;
    }
    
    .form-input {
      padding: 0.875rem 0.875rem 0.875rem 2.75rem;
    }
    
    .brand img {
      height: 52px;
    }
    
    .brand-title {
      font-size: 20px;
    }
  }

  @media (max-width: 360px) {
    .glass-card {
      padding: 1.25rem;
    }
    
    .brand-title {
      font-size: 18px;
    }
    
    .brand-subtitle {
      font-size: 12px;
    }
  }

  /* Dark mode adjustments */
  @media (prefers-color-scheme: dark) {
    .glass-card {
      background: rgba(15, 23, 42, 0.8);
    }
  }

  /* High contrast mode */
  @media (prefers-contrast: high) {
    .glass-card {
      border: 2px solid var(--border-strong);
    }
    
    .form-input {
      border: 2px solid var(--border-medium);
    }
    
    .brand img {
      border: 2px solid var(--text-primary);
    }
  }

  /* Reduced motion */
  @media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
      animation-duration: 0.01ms !important;
      animation-iteration-count: 1 !important;
      transition-duration: 0.01ms !important;
    }
    
    .gradient-orb {
      animation: none;
    }
    
    .submit-btn::before {
      display: none;
    }
  }
</style>
</head>
<body>
  <!-- Animated Background -->
  <div class="background-animation">
    <div class="gradient-orb orb-1"></div>
    <div class="gradient-orb orb-2"></div>
    <div class="gradient-orb orb-3"></div>
  </div>

  <div class="login-container">
    <div class="glass-card">
      <!-- Keep your original brand/logo structure -->
      <div class="brand">
        <img src="<?php echo htmlspecialchars(asset('/../assets/logo.png')); ?>" alt="PRF Logo">
        <div class="brand-text">
          <div class="brand-title">Sign in</div>
          <div class="brand-subtitle">PRF Leave Management Portal</div>
        </div>
      </div>

      <!-- Error Alert -->
      <?php if ($err): ?>
        <div class="alert">
          <i class="fas fa-exclamation-circle"></i>
          <p><?php echo h($err); ?></p>
        </div>
      <?php endif; ?>

      <!-- Login Form -->
      <form class="login-form" method="post" action="<?php echo h(BASE_URL.'index.php'); ?>" autocomplete="off" novalidate>
        <input type="hidden" name="next" value="<?php echo h($next_from_query); ?>">

        <!-- Username Field -->
        <div class="form-group">
          <label class="form-label" for="username">Username</label>
          <div class="input-container">
            <i class="fas fa-user input-icon"></i>
            <input 
              class="form-input" 
              id="username" 
              name="username" 
              type="text" 
              required 
              autocomplete="username" 
              placeholder="your.username"
              autofocus
            >
          </div>
        </div>

        <!-- Password Field -->
        <div class="form-group">
          <label class="form-label" for="password">Password</label>
          <div class="input-container">
            <i class="fas fa-lock input-icon"></i>
            <input 
              class="form-input" 
              id="password" 
              name="password" 
              type="password" 
              required 
              autocomplete="current-password" 
              placeholder="••••••••"
            >
            <button 
              type="button" 
              class="password-toggle" 
              aria-label="Toggle password visibility"
              onclick="togglePasswordVisibility(this)"
            >
              <i class="fas fa-eye"></i>
            </button>
          </div>
        </div>

        <!-- Options -->
        <div class="form-options">
          <label class="remember-me">
            <input type="checkbox" name="remember">
            <span>Remember me</span>
          </label>
          <a href="#" class="forgot-password">Forgot password?</a>
        </div>

        <!-- Submit Button -->
        <button type="submit" class="submit-btn">
          <i class="fas fa-right-to-bracket"></i>
          <span>Login</span>
        </button>
      </form>

      <!-- Footer -->
      <div class="login-footer">
        <p class="copyright">© <?php echo date('Y'); ?> PRF Leave Management System</p>
      </div>
    </div>
  </div>

  <script>
    function togglePasswordVisibility(button) {
      const passwordInput = document.getElementById('password');
      const icon = button.querySelector('i');
      
      if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        icon.className = 'fas fa-eye-slash';
        button.setAttribute('aria-label', 'Hide password');
      } else {
        passwordInput.type = 'password';
        icon.className = 'fas fa-eye';
        button.setAttribute('aria-label', 'Show password');
      }
      
      // Focus back to input after toggle
      passwordInput.focus();
    }

    // Add floating animation to login card on mouse move
    document.addEventListener('mousemove', (e) => {
      const card = document.querySelector('.glass-card');
      const x = (e.clientX / window.innerWidth - 0.5) * 20;
      const y = (e.clientY / window.innerHeight - 0.5) * 20;
      
      card.style.transform = `perspective(1000px) rotateY(${x}deg) rotateX(${-y}deg)`;
    });

    // Reset card position when mouse leaves
    document.addEventListener('mouseleave', () => {
      const card = document.querySelector('.glass-card');
      card.style.transform = 'perspective(1000px) rotateY(0deg) rotateX(0deg)';
    });

    // Form validation
    document.querySelector('.login-form').addEventListener('submit', (e) => {
      const username = document.getElementById('username').value.trim();
      const password = document.getElementById('password').value;
      
      if (!username || !password) {
        e.preventDefault();
        if (!username) {
          document.getElementById('username').focus();
        } else {
          document.getElementById('password').focus();
        }
        return false;
      }
      
      // Add loading state to button
      const submitBtn = e.target.querySelector('.submit-btn');
      const originalText = submitBtn.innerHTML;
      submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Signing in...</span>';
      submitBtn.disabled = true;
      
      // Re-enable after 5 seconds (just in case)
      setTimeout(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
      }, 5000);
    });

    // Auto focus username on page load
    window.addEventListener('load', () => {
      const usernameInput = document.getElementById('username');
      if (usernameInput && !usernameInput.value) {
        usernameInput.focus();
      }
    });

    // Smooth scroll to form if there's an error
    <?php if ($err): ?>
    window.addEventListener('load', () => {
      document.querySelector('.login-form')?.scrollIntoView({
        behavior: 'smooth',
        block: 'center'
      });
    });
    <?php endif; ?>
  </script>
</body>
</html>