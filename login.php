<?php
require 'includes/db.php';
require 'includes/auth.php';

$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $pass === '') {
        flash('danger', 'Please enter a valid email and password.');
    } else {
        $stmt = $conn->prepare('SELECT user_id, password_hash, role FROM users WHERE email=?');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        if ($res && password_verify($pass, $res['password_hash'])) {
            $_SESSION['user_id'] = $res['user_id'];
            $_SESSION['role']    = $res['role'];
            $_SESSION['email']   = $email;
            flash('success', 'Welcome back!');
            $target = ['admin'=>'dashboard_admin.php','sme'=>'dashboard_sme.php','resident'=>'dashboard_resident.php'][$res['role']];
            header('Location: '.$target); exit;
        } else {
            flash('danger', 'Invalid credentials.');
        }
    }
}
require 'includes/header.php';
?>
<section class="auth-shell py-4">
  <div class="row justify-content-center">
    <div class="col-xl-10 col-lg-11">
      <div class="card auth-card border-0 shadow-sm overflow-hidden">
        <div class="row g-0">
          <div class="col-lg-5 auth-card-aside">
            <div class="auth-card-aside-inner">
              <a class="auth-brand-navbar mb-4" href="index.php" aria-label="CultureConnect home">
                <span class="auth-brand-navbar-icon" aria-hidden="true">🎭</span>
                <span class="auth-brand-wordmark">
                  <span class="auth-brand-title">CultureConnect</span>
                  <span class="auth-brand-tag">Local Creative Connections</span>
                </span>
              </a>
              <p class="auth-eyebrow mb-2">Welcome Back</p>
              <h1 class="auth-title mb-3">Sign In to CultureConnect</h1>
              <p class="auth-copy mb-4">Access your resident, SME, or council dashboard and continue exploring the cultural experiences, products, and services that matter most to your community.</p>

              <div class="auth-feature-list">
                <div class="auth-feature-item">
                  <i class="bi bi-speedometer2"></i>
                  <span>Residents return to their personalised voting and recommendation dashboard.</span>
                </div>
                <div class="auth-feature-item">
                  <i class="bi bi-shop-window"></i>
                  <span>SMEs manage creative listings, pricing, and cultural product details in one place.</span>
                </div>
                <div class="auth-feature-item">
                  <i class="bi bi-bar-chart-line"></i>
                  <span>Councils review area demand signals and monitor what communities value most.</span>
                </div>
              </div>
            </div>
          </div>

          <div class="col-lg-7">
            <div class="card-body p-4 p-lg-5">
              <div class="mb-4">
                <h2 class="auth-panel-title mb-1">Sign In</h2>
                <p class="auth-panel-copy mb-0">Use the email and password linked to your CultureConnect account.</p>
              </div>

              <form method="post">
                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label" for="login_email">Email Address</label>
                    <input
                      id="login_email"
                      type="email"
                      name="email"
                      class="form-control"
                      value="<?= htmlspecialchars($email) ?>"
                      autocomplete="email"
                      required
                    >
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="login_password">Password</label>
                    <input
                      id="login_password"
                      type="password"
                      name="password"
                      class="form-control"
                      minlength="6"
                      autocomplete="current-password"
                      required
                    >
                  </div>
                </div>

                <div class="d-flex justify-content-end mt-3">
                  <a href="forgot_password.php" class="small auth-inline-link">Forgot your password?</a>
                </div>

                <button class="btn btn-culture auth-submit-btn w-100 mt-4">Sign In</button>
              </form>

              <div class="auth-note-card mt-4">
                <div class="auth-note-icon"><i class="bi bi-key"></i></div>
                <div>
                  <div class="fw-semibold mb-1">Default Access</div>
                  <p class="mb-0 text-muted">Admin: <strong>admin@council.gov</strong> / <strong>admin123</strong><br>Sample SME and Resident accounts use <strong>password123</strong>.</p>
                </div>
              </div>

              <p class="text-center mt-4 mb-0">No account yet? <a href="register.php" class="auth-inline-link">Register here</a></p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
<?php require 'includes/footer.php'; ?>
