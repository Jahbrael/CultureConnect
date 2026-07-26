<?php
require 'includes/db.php';
require 'includes/auth.php';

function register_table_has_column(mysqli $conn, string $table, string $column): bool
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

$allowedRoles = ['resident', 'sme'];
$registerRole = $_GET['role'] ?? 'resident';
if (!in_array($registerRole, $allowedRoles, true)) {
    $registerRole = 'resident';
}

$ageOptions = ['Under 18', '18-25', '26-40', '41-60', '60+'];
$genderOptions = ['Male', 'Female', 'Non-binary', 'Prefer not to say'];

$areas = $conn->query('SELECT area_id, area_name FROM areas ORDER BY area_name')->fetch_all(MYSQLI_ASSOC);
$titles = $conn->query('SELECT title_id, title_name FROM titles ORDER BY title_name')->fetch_all(MYSQLI_ASSOC);
$interests = $conn->query('SELECT interest_id, interest_name FROM interests ORDER BY interest_name')->fetch_all(MYSQLI_ASSOC);
$securityQuestionTableReady = register_table_has_column($conn, 'security_questions', 'question_id')
    && register_table_has_column($conn, 'security_questions', 'question_text');
$residentRecoveryReady = register_table_has_column($conn, 'residents', 'security_question_id')
    && register_table_has_column($conn, 'residents', 'security_answer_hash');
$smeRecoveryReady = register_table_has_column($conn, 'smes', 'security_question_id')
    && register_table_has_column($conn, 'smes', 'security_answer_hash');
$securityQuestions = $securityQuestionTableReady
    ? $conn->query('SELECT question_id, question_text FROM security_questions ORDER BY question_id')->fetch_all(MYSQLI_ASSOC)
    : [];

$validAreaIds = array_map('intval', array_column($areas, 'area_id'));
$validTitleIds = array_map('intval', array_column($titles, 'title_id'));
$validInterestIds = array_map('intval', array_column($interests, 'interest_id'));
$validSecurityQuestionIds = array_map('intval', array_column($securityQuestions, 'question_id'));
$securityQuestionReady = !empty($securityQuestions);
$residentLookupReady = !empty($areas) && !empty($titles) && !empty($interests) && $securityQuestionReady && $residentRecoveryReady;
$registrationReady = $registerRole === 'resident'
    ? $residentLookupReady
    : ($securityQuestionReady && $smeRecoveryReady);

$form = [
    'email' => '',
    'title_id' => '',
    'full_name' => '',
    'area_id' => '',
    'location' => '',
    'age_group' => '',
    'gender' => '',
    'interests' => [],
    'company_name' => '',
    'contact_name' => '',
    'contact_phone' => '',
    'portfolio_url' => '',
    'security_question_id' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $registerRole = $_POST['register_role'] ?? $registerRole;
    if (!in_array($registerRole, $allowedRoles, true)) {
        $registerRole = 'resident';
    }

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $securityQuestionId = (int)($_POST['security_question_id'] ?? 0);
    $securityAnswer = trim($_POST['security_answer'] ?? '');
    $errors = [];

    $form['email'] = $email;
    $form['security_question_id'] = $securityQuestionId > 0 ? (string)$securityQuestionId : '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }
    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }
    if (!$securityQuestionReady || !$residentRecoveryReady || !$smeRecoveryReady) {
        $errors[] = 'Password recovery setup is incomplete. Import the security question recovery SQL first.';
    }
    if (!in_array($securityQuestionId, $validSecurityQuestionIds, true)) {
        $errors[] = 'Please choose a valid security question.';
    }
    if ($securityAnswer === '') {
        $errors[] = 'Security answer is required.';
    }

    if ($registerRole === 'resident') {
        $titleId = (int)($_POST['title_id'] ?? 0);
        $areaId = (int)($_POST['area_id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $ageGroup = $_POST['age_group'] ?? '';
        $gender = $_POST['gender'] ?? '';
        $interestIds = array_values(array_unique(array_filter(array_map('intval', $_POST['interests'] ?? []))));

        $form['title_id'] = $titleId > 0 ? (string)$titleId : '';
        $form['area_id'] = $areaId > 0 ? (string)$areaId : '';
        $form['full_name'] = $fullName;
        $form['location'] = $location;
        $form['age_group'] = $ageGroup;
        $form['gender'] = $gender;
        $form['interests'] = $interestIds;

        if (!$residentLookupReady) {
            $errors[] = 'Resident registration is temporarily unavailable because titles, areas, or interests have not been set up yet.';
        }
        if (!in_array($titleId, $validTitleIds, true)) {
            $errors[] = 'Please choose a valid title.';
        }
        if (!in_array($areaId, $validAreaIds, true)) {
            $errors[] = 'Please choose a valid area.';
        }
        if ($fullName === '') {
            $errors[] = 'Full name is required.';
        }
        if (!in_array($ageGroup, $ageOptions, true)) {
            $errors[] = 'Please choose a valid age group.';
        }
        if (!in_array($gender, $genderOptions, true)) {
            $errors[] = 'Please choose a valid gender.';
        }
        if (!$interestIds) {
            $errors[] = 'Choose at least one interest.';
        } elseif (array_diff($interestIds, $validInterestIds)) {
            $errors[] = 'Please choose valid interests from the list.';
        }
    } else {
        $companyName = trim($_POST['company_name'] ?? '');
        $contactName = trim($_POST['contact_name'] ?? '');
        $contactPhone = trim($_POST['contact_phone'] ?? '');
        $portfolioUrl = trim($_POST['portfolio_url'] ?? '');

        $form['company_name'] = $companyName;
        $form['contact_name'] = $contactName;
        $form['contact_phone'] = $contactPhone;
        $form['portfolio_url'] = $portfolioUrl;

        if ($companyName === '') {
            $errors[] = 'Company or artist name is required.';
        }
        if ($portfolioUrl !== '' && !filter_var($portfolioUrl, FILTER_VALIDATE_URL)) {
            $errors[] = 'Portfolio URL must be a valid URL.';
        }
    }

    $checkUser = $conn->prepare('SELECT user_id FROM users WHERE email = ?');
    $checkUser->bind_param('s', $email);
    $checkUser->execute();
    if ($checkUser->get_result()->num_rows > 0) {
        $errors[] = 'Email already registered.';
    }

    if (!$errors) {
        $conn->begin_transaction();

        try {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $securityAnswerHash = password_hash($securityAnswer, PASSWORD_BCRYPT);
            $insertUser = $conn->prepare('INSERT INTO users (email, password_hash, role) VALUES (?, ?, ?)');
            $insertUser->bind_param('sss', $email, $hash, $registerRole);
            $insertUser->execute();
            $userId = $conn->insert_id;

            if ($registerRole === 'resident') {
                $titleId = (int)$form['title_id'];
                $areaId = (int)$form['area_id'];
                $fullName = $form['full_name'];
                $location = $form['location'];
                $ageGroup = $form['age_group'];
                $gender = $form['gender'];
                $interestIds = $form['interests'];
                $securityQuestionId = (int)$form['security_question_id'];

                $insertResident = $conn->prepare('INSERT INTO residents (user_id, area_id, title_id, full_name, location, age_group, gender, security_question_id, security_answer_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $insertResident->bind_param('iiissssis', $userId, $areaId, $titleId, $fullName, $location, $ageGroup, $gender, $securityQuestionId, $securityAnswerHash);
                $insertResident->execute();
                $residentId = $conn->insert_id;

                $insertInterest = $conn->prepare('INSERT INTO resident_interests (resident_id, interest_id) VALUES (?, ?)');
                foreach ($interestIds as $interestId) {
                    $insertInterest->bind_param('ii', $residentId, $interestId);
                    $insertInterest->execute();
                }
            } else {
                $companyName = $form['company_name'];
                $contactName = $form['contact_name'];
                $contactPhone = $form['contact_phone'];
                $portfolioUrl = $form['portfolio_url'];
                $securityQuestionId = (int)$form['security_question_id'];

                $insertSme = $conn->prepare('INSERT INTO smes (user_id, company_name, contact_name, contact_phone, portfolio_url, security_question_id, security_answer_hash) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $insertSme->bind_param('issssis', $userId, $companyName, $contactName, $contactPhone, $portfolioUrl, $securityQuestionId, $securityAnswerHash);
                $insertSme->execute();
            }

            $conn->commit();
            flash('success', 'Account created successfully! Please sign in.');
            header('Location: login.php');
            exit;
        } catch (Throwable $exception) {
            $conn->rollback();
            flash('danger', 'Could not complete registration. Please try again.');
        }
    } else {
        foreach ($errors as $error) {
            flash('danger', $error);
        }
    }
}

require 'includes/header.php';
?>
<section class="auth-shell register-shell py-4">
  <div class="row justify-content-center">
    <div class="col-xl-10 col-lg-11">
      <div class="card auth-card auth-register-card border-0 shadow-sm overflow-hidden">
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
              <p class="auth-eyebrow mb-2">Create Your Account</p>
              <h1 class="auth-title mb-3">Join CultureConnect</h1>
              <p class="auth-copy mb-4">Register as a resident to vote on local cultural offerings, or sign up as an SME to showcase your creative products and services.</p>

              <div class="auth-feature-list">
                <div class="auth-feature-item">
                  <i class="bi bi-geo-alt"></i>
                  <span>Areas come directly from the council-managed area list.</span>
                </div>
                <div class="auth-feature-item">
                  <i class="bi bi-tags"></i>
                  <span>Resident interests are saved for personalised browsing and dashboard features.</span>
                </div>
                <div class="auth-feature-item">
                  <i class="bi bi-shield-check"></i>
                  <span>Secure signup with password confirmation and lookup-driven fields.</span>
                </div>
              </div>
            </div>
          </div>

          <div class="col-lg-7">
            <div class="card-body p-4 p-lg-5">
              <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-end gap-3 mb-4">
                <div>
                  <h2 class="auth-panel-title mb-1"><?= $registerRole === 'resident' ? 'Register as Resident' : 'Register as SME' ?></h2>
                  <p class="auth-panel-copy mb-0"><?= $registerRole === 'resident'
                    ? 'Tell us about where you live and the cultural interests you care about.'
                    : 'Create your business profile so you can list unique cultural offerings.' ?></p>
                </div>
                <div class="register-role-switch" role="tablist" aria-label="Registration role">
                  <a class="register-role-link <?= $registerRole === 'resident' ? 'active' : '' ?>" href="?role=resident">Resident</a>
                  <a class="register-role-link <?= $registerRole === 'sme' ? 'active' : '' ?>" href="?role=sme">SME</a>
                </div>
              </div>

              <?php if (!$registrationReady): ?>
                <div class="alert alert-warning">
                  <?= $registerRole === 'resident'
                    ? 'Resident registration needs titles, areas, interests, and the security recovery setup. Add the missing lookup data or import the recovery SQL first.'
                    : 'SME registration needs the security recovery setup before new accounts can be created.' ?>
                </div>
              <?php endif; ?>

              <form method="post" id="registerForm" class="needs-validation register-form" data-register-role="<?= htmlspecialchars($registerRole) ?>">
                <input type="hidden" name="register_role" value="<?= htmlspecialchars($registerRole) ?>">

                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label" for="register_email">Email Address <span class="text-danger">*</span></label>
                    <input
                      id="register_email"
                      type="email"
                      name="email"
                      class="form-control"
                      value="<?= htmlspecialchars($form['email']) ?>"
                      autocomplete="email"
                      required
                    >
                  </div>

                  <div class="col-md-6">
                    <label class="form-label" for="register_password">Password <span class="text-danger">*</span></label>
                    <input
                      id="register_password"
                      type="password"
                      name="password"
                      class="form-control"
                      minlength="6"
                      autocomplete="new-password"
                      required
                    >
                    <div class="form-text">Use at least 6 characters.</div>
                  </div>

                  <div class="col-md-6">
                    <label class="form-label" for="register_confirm_password">Confirm Password <span class="text-danger">*</span></label>
                    <input
                      id="register_confirm_password"
                      type="password"
                      name="confirm_password"
                      class="form-control"
                      minlength="6"
                      autocomplete="new-password"
                      required
                    >
                    <div class="invalid-feedback">Passwords must match.</div>
                  </div>

                  <div class="col-md-6">
                    <label class="form-label" for="register_security_question_id">Security Question <span class="text-danger">*</span></label>
                    <select
                      id="register_security_question_id"
                      name="security_question_id"
                      class="form-select"
                      <?= $securityQuestionReady ? 'required' : 'disabled' ?>
                    >
                      <option value=""><?= $securityQuestionReady ? 'Choose a security question' : 'No security questions available yet' ?></option>
                      <?php foreach ($securityQuestions as $securityQuestion): ?>
                        <option value="<?= $securityQuestion['question_id'] ?>" <?= (string)$securityQuestion['question_id'] === $form['security_question_id'] ? 'selected' : '' ?>>
                          <?= htmlspecialchars($securityQuestion['question_text']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="col-md-6">
                    <label class="form-label" for="register_security_answer">Security Answer <span class="text-danger">*</span></label>
                    <input
                      id="register_security_answer"
                      type="text"
                      name="security_answer"
                      class="form-control"
                      autocomplete="off"
                      required
                    >
                    <div class="form-text">Your answer is stored securely with password hashing.</div>
                  </div>

                  <?php if ($registerRole === 'resident'): ?>
                    <div class="col-md-6">
                      <label class="form-label" for="register_title_id">Title <span class="text-danger">*</span></label>
                      <select id="register_title_id" name="title_id" class="form-select" required>
                        <option value="">Choose a title</option>
                        <?php foreach ($titles as $title): ?>
                          <option value="<?= $title['title_id'] ?>" <?= (string)$title['title_id'] === $form['title_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($title['title_name']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>

                    <div class="col-md-6">
                      <label class="form-label" for="register_full_name">Full Name <span class="text-danger">*</span></label>
                      <input
                        id="register_full_name"
                        name="full_name"
                        class="form-control"
                        value="<?= htmlspecialchars($form['full_name']) ?>"
                        autocomplete="name"
                        required
                      >
                    </div>

                    <div class="col-md-6">
                      <label class="form-label" for="register_area_id">Area <span class="text-danger">*</span></label>
                      <select id="register_area_id" name="area_id" class="form-select" <?= $residentLookupReady ? 'required' : 'disabled' ?>>
                        <option value=""><?= $residentLookupReady ? 'Choose your current area' : 'No areas available yet' ?></option>
                        <?php foreach ($areas as $area): ?>
                          <option value="<?= $area['area_id'] ?>" <?= (string)$area['area_id'] === $form['area_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($area['area_name']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <div class="form-text">Select from the list of available areas.</div>
                    </div>

                    <div class="col-md-6">
                      <label class="form-label" for="register_location">Location / Street</label>
                      <input
                        id="register_location"
                        name="location"
                        class="form-control"
                        value="<?= htmlspecialchars($form['location']) ?>"
                        autocomplete="street-address"
                      >
                    </div>

                    <div class="col-md-6">
                      <label class="form-label" for="register_age_group">Age Group <span class="text-danger">*</span></label>
                      <select id="register_age_group" name="age_group" class="form-select" required>
                        <option value="">Choose an age group</option>
                        <?php foreach ($ageOptions as $ageOption): ?>
                          <option value="<?= htmlspecialchars($ageOption) ?>" <?= $ageOption === $form['age_group'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ageOption) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>

                    <div class="col-md-6">
                      <label class="form-label" for="register_gender">Gender <span class="text-danger">*</span></label>
                      <select id="register_gender" name="gender" class="form-select" required>
                        <option value="">Choose a gender</option>
                        <?php foreach ($genderOptions as $genderOption): ?>
                          <option value="<?= htmlspecialchars($genderOption) ?>" <?= $genderOption === $form['gender'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($genderOption) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>

                    <div class="col-12">
                      <label class="form-label d-block mb-2">Interests <span class="text-danger">*</span></label>
                      <div class="register-interest-grid" data-interest-group>
                        <?php foreach ($interests as $interest): ?>
                          <?php $interestId = (int)$interest['interest_id']; ?>
                          <input
                            type="checkbox"
                            class="btn-check"
                            name="interests[]"
                            value="<?= $interestId ?>"
                            id="interest-<?= $interestId ?>"
                            autocomplete="off"
                            <?= in_array($interestId, $form['interests'], true) ? 'checked' : '' ?>
                          >
                          <label class="register-interest-chip" for="interest-<?= $interestId ?>">
                            <?= htmlspecialchars($interest['interest_name']) ?>
                          </label>
                        <?php endforeach; ?>
                      </div>
                      <div class="form-text">Choose at least one interest so we can personalise your resident experience later.</div>
                      <div class="invalid-feedback d-block js-interest-feedback d-none">Choose at least one interest.</div>
                    </div>
                  <?php else: ?>
                    <div class="col-md-6">
                      <label class="form-label" for="register_company_name">Company / Artist Name <span class="text-danger">*</span></label>
                      <input
                        id="register_company_name"
                        name="company_name"
                        class="form-control"
                        value="<?= htmlspecialchars($form['company_name']) ?>"
                        autocomplete="organization"
                        required
                      >
                    </div>

                    <div class="col-md-6">
                      <label class="form-label" for="register_contact_name">Contact Name</label>
                      <input
                        id="register_contact_name"
                        name="contact_name"
                        class="form-control"
                        value="<?= htmlspecialchars($form['contact_name']) ?>"
                        autocomplete="name"
                      >
                    </div>

                    <div class="col-md-6">
                      <label class="form-label" for="register_contact_phone">Phone</label>
                      <input
                        id="register_contact_phone"
                        name="contact_phone"
                        class="form-control"
                        value="<?= htmlspecialchars($form['contact_phone']) ?>"
                        autocomplete="tel"
                      >
                    </div>

                    <div class="col-md-6">
                      <label class="form-label" for="register_portfolio_url">Portfolio URL</label>
                      <input
                        id="register_portfolio_url"
                        type="url"
                        name="portfolio_url"
                        class="form-control"
                        value="<?= htmlspecialchars($form['portfolio_url']) ?>"
                        placeholder="https://example.com"
                        inputmode="url"
                      >
                      <div class="form-text">Enter a full valid URL so residents and councils can review your work easily.</div>
                    </div>

                    <div class="col-12">
                      <div class="register-info-panel">
                        <div class="register-info-icon"><i class="bi bi-info-circle"></i></div>
                        <div>
                          <div class="fw-semibold mb-1">Listing Notice</div>
                          <p class="mb-0">After registering, you can list unique cultural products and services. Each SME can register only one <strong>Health &amp; Wellness</strong> offering at a time.</p>
                        </div>
                      </div>
                    </div>
                  <?php endif; ?>
                </div>

                <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mt-4">
                  <p class="text-muted small mb-0">Fields marked with <span class="text-danger">*</span> are required.</p>
                  <button class="btn auth-submit-btn register-submit-btn" <?= !$registrationReady ? 'disabled' : '' ?>>Create Account</button>
                </div>
              </form>

              <p class="text-center mt-4 mb-0">Already have an account? <a href="login.php" class="auth-inline-link">Sign in here</a></p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var form = document.getElementById('registerForm');
  if (!form) {
    return;
  }

  var passwordInput = document.getElementById('register_password');
  var confirmPasswordInput = document.getElementById('register_confirm_password');
  var interestInputs = form.querySelectorAll('input[name="interests[]"]');
  var interestFeedback = form.querySelector('.js-interest-feedback');
  var registerRole = form.getAttribute('data-register-role');

  function syncPasswordValidation() {
    if (!passwordInput || !confirmPasswordInput) {
      return true;
    }

    var matches = confirmPasswordInput.value === '' || passwordInput.value === confirmPasswordInput.value;
    confirmPasswordInput.setCustomValidity(matches ? '' : 'Passwords do not match.');
    return matches;
  }

  function syncInterestValidation() {
    if (registerRole !== 'resident' || !interestInputs.length) {
      return true;
    }

    var hasSelectedInterest = Array.prototype.some.call(interestInputs, function (input) {
      return input.checked;
    });

    interestInputs[0].setCustomValidity(hasSelectedInterest ? '' : 'Choose at least one interest.');
    if (interestFeedback) {
      interestFeedback.classList.toggle('d-none', hasSelectedInterest);
    }
    return hasSelectedInterest;
  }

  if (passwordInput && confirmPasswordInput) {
    passwordInput.addEventListener('input', syncPasswordValidation);
    confirmPasswordInput.addEventListener('input', syncPasswordValidation);
  }

  interestInputs.forEach(function (input) {
    input.addEventListener('change', syncInterestValidation);
  });

  form.addEventListener('submit', function (event) {
    var passwordsValid = syncPasswordValidation();
    var interestsValid = syncInterestValidation();

    if (!form.checkValidity() || !passwordsValid || !interestsValid) {
      event.preventDefault();
      event.stopPropagation();
    }

    form.classList.add('was-validated');
  });

  syncPasswordValidation();
  syncInterestValidation();
});
</script>
<?php require 'includes/footer.php'; ?>
