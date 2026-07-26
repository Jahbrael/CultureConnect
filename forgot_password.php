<?php
require 'includes/db.php';
require 'includes/auth.php';

function recovery_table_has_column(mysqli $conn, string $table, string $column): bool
{
    $statement = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $statement->bind_param('ss', $table, $column);
    $statement->execute();
    return (int)($statement->get_result()->fetch_assoc()['total'] ?? 0) > 0;
}

function recovery_clear_state(): void
{
    unset($_SESSION['password_recovery']);
}

function recovery_get_state(): array
{
    return $_SESSION['password_recovery'] ?? [];
}

function recovery_set_state(array $state): void
{
    $_SESSION['password_recovery'] = $state;
}

function recovery_profile(mysqli $conn, int $userId, string $role): ?array
{
    if ($role === 'resident') {
        $statement = $conn->prepare("
            SELECT r.security_question_id, r.security_answer_hash, sq.question_text
            FROM residents r
            LEFT JOIN security_questions sq ON sq.question_id = r.security_question_id
            WHERE r.user_id = ?
            LIMIT 1
        ");
    } elseif ($role === 'sme') {
        $statement = $conn->prepare("
            SELECT s.security_question_id, s.security_answer_hash, sq.question_text
            FROM smes s
            LEFT JOIN security_questions sq ON sq.question_id = s.security_question_id
            WHERE s.user_id = ?
            LIMIT 1
        ");
    } else {
        return null;
    }

    $statement->bind_param('i', $userId);
    $statement->execute();
    return $statement->get_result()->fetch_assoc() ?: null;
}

$recoverySchemaReady = recovery_table_has_column($conn, 'security_questions', 'question_id')
    && recovery_table_has_column($conn, 'security_questions', 'question_text')
    && recovery_table_has_column($conn, 'residents', 'security_question_id')
    && recovery_table_has_column($conn, 'residents', 'security_answer_hash')
    && recovery_table_has_column($conn, 'smes', 'security_question_id')
    && recovery_table_has_column($conn, 'smes', 'security_answer_hash');

if (isset($_GET['restart']) && $_GET['restart'] === '1') {
    recovery_clear_state();
    header('Location: forgot_password.php');
    exit;
}

$state = recovery_get_state();
$identifyEmail = $state['email'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'identify') {
        recovery_clear_state();
        $email = trim($_POST['email'] ?? '');
        $identifyEmail = $email;

        if (!$recoverySchemaReady) {
            flash('danger', 'Password recovery is not configured in this database yet. Import the security question recovery SQL first.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('danger', 'Enter a valid email address.');
        } else {
            $statement = $conn->prepare('SELECT user_id, email, role FROM users WHERE email = ? LIMIT 1');
            $statement->bind_param('s', $email);
            $statement->execute();
            $user = $statement->get_result()->fetch_assoc();

            if (!$user) {
                flash('danger', 'No account was found for that email address.');
            } elseif (!in_array($user['role'], ['resident', 'sme'], true)) {
                flash('danger', 'Password recovery with security questions is only available for resident and SME accounts.');
            } else {
                $profile = recovery_profile($conn, (int)$user['user_id'], $user['role']);
                if (!$profile || empty($profile['security_question_id']) || empty($profile['security_answer_hash']) || empty($profile['question_text'])) {
                    flash('danger', 'Password recovery is not configured for this account yet.');
                } else {
                    recovery_set_state([
                        'user_id' => (int)$user['user_id'],
                        'email' => $user['email'],
                        'role' => $user['role'],
                        'question_text' => $profile['question_text'],
                        'verified' => false,
                    ]);
                    header('Location: forgot_password.php');
                    exit;
                }
            }
        }
    }

    if ($action === 'verify_answer') {
        $state = recovery_get_state();
        $answer = trim($_POST['security_answer'] ?? '');

        if (!$state || empty($state['user_id']) || !empty($state['verified'])) {
            flash('warning', 'Start the password recovery process from step 1.');
            header('Location: forgot_password.php');
            exit;
        }

        if ($answer === '') {
            flash('danger', 'Enter your security answer to continue.');
        } else {
            $profile = recovery_profile($conn, (int)$state['user_id'], $state['role']);
            if ($profile && !empty($profile['security_answer_hash']) && password_verify($answer, $profile['security_answer_hash'])) {
                $state['verified'] = true;
                recovery_set_state($state);
                header('Location: forgot_password.php');
                exit;
            }

            flash('danger', 'Incorrect answer, please try again.');
        }
    }

    if ($action === 'reset_password') {
        $state = recovery_get_state();
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $errors = [];

        if (!$state || empty($state['user_id']) || empty($state['verified'])) {
            flash('warning', 'Verify your identity before choosing a new password.');
            header('Location: forgot_password.php');
            exit;
        }

        if (strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters.';
        }
        if ($password !== $confirmPassword) {
            $errors[] = 'Passwords do not match.';
        }

        if (!$errors) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $statement = $conn->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?');
            $statement->bind_param('si', $hash, $state['user_id']);

            if ($statement->execute()) {
                recovery_clear_state();
                flash('success', 'Password reset successful. Please sign in with your new password.');
                header('Location: login.php');
                exit;
            }

            flash('danger', 'Could not update your password. Please try again.');
        } else {
            foreach ($errors as $error) {
                flash('danger', $error);
            }
        }
    }

    $state = recovery_get_state();
}

$currentStep = 1;
if ($state && !empty($state['verified'])) {
    $currentStep = 3;
} elseif ($state && !empty($state['question_text'])) {
    $currentStep = 2;
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
              <p class="auth-eyebrow mb-2">Password Recovery</p>
              <h1 class="auth-title mb-3">Recover Your Account</h1>
              <p class="auth-copy mb-4">Use your saved security question to verify your identity and set a new password without relying on email delivery.</p>

              <div class="auth-feature-list">
                <div class="auth-feature-item">
                  <i class="bi bi-1-circle"></i>
                  <span>Step 1 identifies your resident or SME account using the registered email address.</span>
                </div>
                <div class="auth-feature-item">
                  <i class="bi bi-2-circle"></i>
                  <span>Step 2 asks the security question you chose during registration.</span>
                </div>
                <div class="auth-feature-item">
                  <i class="bi bi-3-circle"></i>
                  <span>Step 3 lets you choose a new password once your answer is verified.</span>
                </div>
              </div>
            </div>
          </div>

          <div class="col-lg-7">
            <div class="card-body p-4 p-lg-5">
              <div class="reset-stepper mb-4" aria-label="Password reset progress">
                <div class="reset-step <?= $currentStep === 1 ? 'is-active' : ($currentStep > 1 ? 'is-complete' : '') ?>">
                  <span class="reset-step-number">1</span>
                  <span class="reset-step-copy">Identify</span>
                </div>
                <div class="reset-step <?= $currentStep === 2 ? 'is-active' : ($currentStep > 2 ? 'is-complete' : '') ?>">
                  <span class="reset-step-number">2</span>
                  <span class="reset-step-copy">Verify Identity</span>
                </div>
                <div class="reset-step <?= $currentStep === 3 ? 'is-active' : '' ?>">
                  <span class="reset-step-number">3</span>
                  <span class="reset-step-copy">Reset Password</span>
                </div>
              </div>

              <?php if ($currentStep === 1): ?>
                <?php if (!$recoverySchemaReady): ?>
                  <div class="alert alert-warning">
                    Password recovery setup is incomplete in this database. Import <code>sql/add_security_question_recovery.sql</code> first.
                  </div>
                <?php endif; ?>
                <div class="mb-4">
                  <p class="section-eyebrow mb-2">Step 1 of 3</p>
                  <h2 class="mb-2">Find Your Account</h2>
                  <p class="text-muted mb-0">Enter the email address you used when registering your resident or SME account.</p>
                </div>

                <form method="post">
                  <input type="hidden" name="action" value="identify">
                  <div class="mb-3">
                    <label class="form-label" for="recovery_email">Registered Email</label>
                    <input
                      id="recovery_email"
                      type="email"
                      name="email"
                      class="form-control"
                      value="<?= htmlspecialchars($identifyEmail) ?>"
                      autocomplete="email"
                      required
                    >
                  </div>
                  <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3">
                    <a href="login.php" class="btn btn-outline-secondary">Back to Sign In</a>
                    <button class="btn btn-primary" <?= !$recoverySchemaReady ? 'disabled' : '' ?>>Continue to Security Question</button>
                  </div>
                </form>
              <?php elseif ($currentStep === 2): ?>
                <div class="mb-4">
                  <p class="section-eyebrow mb-2">Step 2 of 3</p>
                  <h2 class="mb-2">Verify Identity</h2>
                  <p class="text-muted mb-0">Answer the security question tied to <strong><?= htmlspecialchars($state['email']) ?></strong>.</p>
                </div>

                <div class="auth-note-card mb-4">
                  <div class="auth-note-icon"><i class="bi bi-shield-lock"></i></div>
                  <div>
                    <div class="fw-semibold mb-1">Security Question</div>
                    <p class="mb-0"><?= htmlspecialchars($state['question_text']) ?></p>
                  </div>
                </div>

                <form method="post">
                  <input type="hidden" name="action" value="verify_answer">
                  <div class="mb-3">
                    <label class="form-label" for="security_answer">Your Answer</label>
                    <input
                      id="security_answer"
                      type="text"
                      name="security_answer"
                      class="form-control"
                      autocomplete="off"
                      required
                    >
                  </div>
                  <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3">
                    <a href="forgot_password.php?restart=1" class="btn btn-outline-secondary">Use a Different Email</a>
                    <button class="btn btn-primary">Verify Answer</button>
                  </div>
                </form>
              <?php else: ?>
                <div class="mb-4">
                  <p class="section-eyebrow mb-2">Step 3 of 3</p>
                  <h2 class="mb-2">Choose a New Password</h2>
                  <p class="text-muted mb-0">Identity confirmed for <strong><?= htmlspecialchars($state['email']) ?></strong>. Set a new password to finish recovery.</p>
                </div>

                <form method="post" id="resetPasswordForm" class="needs-validation" novalidate>
                  <input type="hidden" name="action" value="reset_password">
                  <div class="row g-3">
                    <div class="col-md-6">
                      <label class="form-label" for="reset_password">New Password</label>
                      <input
                        id="reset_password"
                        type="password"
                        name="password"
                        class="form-control"
                        minlength="6"
                        autocomplete="new-password"
                        required
                      >
                    </div>
                    <div class="col-md-6">
                      <label class="form-label" for="reset_confirm_password">Confirm New Password</label>
                      <input
                        id="reset_confirm_password"
                        type="password"
                        name="confirm_password"
                        class="form-control"
                        minlength="6"
                        autocomplete="new-password"
                        required
                      >
                      <div class="invalid-feedback">Passwords must match.</div>
                    </div>
                  </div>
                  <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mt-4">
                    <a href="forgot_password.php?restart=1" class="btn btn-outline-secondary">Start Over</a>
                    <button class="btn btn-primary">Save New Password</button>
                  </div>
                </form>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var form = document.getElementById('resetPasswordForm');
  if (!form) {
    return;
  }

  var passwordInput = document.getElementById('reset_password');
  var confirmPasswordInput = document.getElementById('reset_confirm_password');

  function syncPasswordValidation() {
    if (!passwordInput || !confirmPasswordInput) {
      return true;
    }

    var matches = confirmPasswordInput.value === '' || passwordInput.value === confirmPasswordInput.value;
    confirmPasswordInput.setCustomValidity(matches ? '' : 'Passwords do not match.');
    return matches;
  }

  passwordInput.addEventListener('input', syncPasswordValidation);
  confirmPasswordInput.addEventListener('input', syncPasswordValidation);

  form.addEventListener('submit', function (event) {
    var passwordsValid = syncPasswordValidation();
    if (!form.checkValidity() || !passwordsValid) {
      event.preventDefault();
      event.stopPropagation();
    }
    form.classList.add('was-validated');
  });

  syncPasswordValidation();
});
</script>
<?php require 'includes/footer.php'; ?>
