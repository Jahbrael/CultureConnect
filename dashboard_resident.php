<?php
require 'includes/db.php';
require 'includes/auth.php';
require_role('resident');

$ageOptions = ['Under 18', '18-25', '26-40', '41-60', '60+'];
$genderOptions = ['Male', 'Female', 'Non-binary', 'Prefer not to say'];

$currentVotesSql = "
   SELECT v.resident_id, v.product_id, v.vote_value, v.voted_at
        , v.vote_id
   FROM votes v
   JOIN (
      SELECT resident_id, product_id, MAX(vote_id) AS vote_id
      FROM votes
      GROUP BY resident_id, product_id
   ) latest ON latest.vote_id = v.vote_id
";

function resident_interest_rules(): array
{
    return [
        'Visual Arts' => [
            'categories' => ['Visual Arts', 'Workshops', 'Handmade Goods'],
            'keywords' => ['art', 'artist', 'painting', 'sculpture', 'ceramic', 'pottery', 'mural', 'gallery', 'print', 'visual'],
        ],
        'Theatre' => [
            'categories' => ['Performing Arts'],
            'keywords' => ['theatre', 'theater', 'stage', 'performance', 'play', 'drama', 'street performance'],
        ],
        'Literature' => [
            'categories' => ['Literary & Media', 'Media'],
            'keywords' => ['literature', 'book', 'poetry', 'story', 'storytelling', 'zine', 'publication', 'reading', 'writer'],
        ],
        'Digital Media' => [
            'categories' => ['Literary & Media', 'Media', 'Creative Services'],
            'keywords' => ['digital', 'media', 'video', 'film', 'documentary', 'screening', 'design', 'graphic', 'content'],
        ],
        'Heritage' => [
            'categories' => ['Cultural Experiences', 'Literary & Media', 'Media'],
            'keywords' => ['heritage', 'history', 'historical', 'museum', 'gallery', 'tour', 'trail', 'curator', 'documentary'],
        ],
        'Music' => [
            'categories' => ['Performing Arts', 'Workshops'],
            'keywords' => ['music', 'concert', 'open mic', 'instrument', 'guitar', 'piano', 'drums', 'vocal', 'song'],
        ],
        'Dance' => [
            'categories' => ['Performing Arts', 'Workshops'],
            'keywords' => ['dance', 'movement', 'choreography', 'rhythm'],
        ],
        'Health & Wellness' => [
            'categories' => ['Health & Wellness', 'Workshops'],
            'keywords' => ['wellness', 'mindful', 'mental wellbeing', 'health', 'retreat'],
        ],
    ];
}

function resident_product_match_details(array $product, array $selectedInterestNames, array $interestRules): array
{
    $haystack = strtolower(implode(' ', [
        (string)($product['name'] ?? ''),
        (string)($product['description'] ?? ''),
        (string)($product['category'] ?? ''),
        (string)($product['cultural_benefits'] ?? ''),
        (string)($product['company_name'] ?? ''),
    ]));

    $score = 0;
    $matchedInterests = [];

    foreach ($selectedInterestNames as $interestName) {
        $rule = $interestRules[$interestName] ?? null;
        if (!$rule) {
            continue;
        }

        $interestScore = 0;
        if (in_array((string)($product['category'] ?? ''), $rule['categories'], true)) {
            $interestScore += 4;
        }

        foreach ($rule['keywords'] as $keyword) {
            if (strpos($haystack, strtolower($keyword)) !== false) {
                $interestScore += 1;
            }
        }

        if ($interestScore > 0) {
            $score += min($interestScore, 6);
            $matchedInterests[] = $interestName;
        }
    }

    if ((float)($product['price_value'] ?? 0) > 0 && (float)$product['price_value'] < 200) {
        $score += 1;
    }

    return [
        'score' => $score,
        'matched_interests' => array_values(array_unique($matchedInterests)),
    ];
}

$uid = $_SESSION['user_id'];
$me = $conn->query("
   SELECT r.*, a.area_name, t.title_name
   FROM residents r
   JOIN areas a  ON a.area_id  = r.area_id
   JOIN titles t ON t.title_id = r.title_id
   WHERE r.user_id=$uid")->fetch_assoc();

if (!$me) {
    flash('danger', 'Resident profile missing.');
    header('Location: logout.php'); exit;
}

$areas = $conn->query('SELECT * FROM areas ORDER BY area_name')->fetch_all(MYSQLI_ASSOC);
$titles = $conn->query('SELECT * FROM titles ORDER BY title_name')->fetch_all(MYSQLI_ASSOC);
$interests = $conn->query('SELECT * FROM interests ORDER BY interest_name')->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $titleId = (int)($_POST['title_id'] ?? 0);
        $areaId = (int)($_POST['area_id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $ageGroup = $_POST['age_group'] ?? '';
        $gender = $_POST['gender'] ?? '';
        $selectedInterestIds = array_map('intval', $_POST['interests'] ?? []);

        $errors = [];
        if ($titleId <= 0) $errors[] = 'Please choose a title.';
        if ($areaId <= 0) $errors[] = 'Please choose an area.';
        if ($fullName === '') $errors[] = 'Full name is required.';
        if (!in_array($ageGroup, $ageOptions, true)) $errors[] = 'Please choose a valid age group.';
        if (!in_array($gender, $genderOptions, true)) $errors[] = 'Please choose a valid gender.';

        if (!$errors) {
            $conn->begin_transaction();
            try {
                $updateResident = $conn->prepare('UPDATE residents SET area_id=?, title_id=?, full_name=?, location=?, age_group=?, gender=? WHERE resident_id=? AND user_id=?');
                $updateResident->bind_param('iissssii', $areaId, $titleId, $fullName, $location, $ageGroup, $gender, $me['resident_id'], $uid);
                $updateResident->execute();

                $deleteInterests = $conn->prepare('DELETE FROM resident_interests WHERE resident_id=?');
                $deleteInterests->bind_param('i', $me['resident_id']);
                $deleteInterests->execute();

                if ($selectedInterestIds) {
                    $insertInterest = $conn->prepare('INSERT INTO resident_interests (resident_id, interest_id) VALUES (?, ?)');
                    foreach ($selectedInterestIds as $interestId) {
                        if ($interestId > 0) {
                            $insertInterest->bind_param('ii', $me['resident_id'], $interestId);
                            $insertInterest->execute();
                        }
                    }
                }

                $conn->commit();
                flash('success', 'Profile updated successfully.');
                header('Location: dashboard_resident.php');
                exit;
            } catch (Throwable $e) {
                $conn->rollback();
                flash('danger', 'Could not update your profile.');
            }
        } else {
            foreach ($errors as $error) flash('danger', $error);
        }

        header('Location: dashboard_resident.php#resident-profile'); exit;
    }

    if ($action === 'delete_vote') {
        $voteId = (int)($_POST['vote_id'] ?? 0);
        $deleteVote = $conn->prepare('DELETE FROM votes WHERE vote_id=? AND resident_id=?');
        $deleteVote->bind_param('ii', $voteId, $me['resident_id']);
        $deleteVote->execute();
        flash('success', 'Vote removed.');
        header('Location: dashboard_resident.php#resident-votes'); exit;
    }

    if ($action === 'delete_account') {
        $deleteUser = $conn->prepare("DELETE FROM users WHERE user_id=? AND role='resident'");
        $deleteUser->bind_param('i', $uid);
        if ($deleteUser->execute()) {
            session_unset();
            session_destroy();
            session_start();
            flash('success', 'Resident account deleted.');
            header('Location: index.php'); exit;
        }
        flash('danger', 'Could not delete resident account.');
        header('Location: dashboard_resident.php'); exit;
    }
}

$selectedInterests = $conn->query("SELECT interest_id FROM resident_interests WHERE resident_id={$me['resident_id']}")
    ->fetch_all(MYSQLI_ASSOC);
$selectedInterestIds = array_map(static fn($row) => (int)$row['interest_id'], $selectedInterests);
$interestNameMap = [];
foreach ($interests as $interest) {
    $interestNameMap[(int)$interest['interest_id']] = $interest['interest_name'];
}
$selectedInterestNames = [];
foreach ($selectedInterestIds as $interestId) {
    if (isset($interestNameMap[$interestId])) {
        $selectedInterestNames[] = $interestNameMap[$interestId];
    }
}

$myVotes = $conn->query("
   SELECT v.*, p.name AS product_name, c.category_name AS category, s.company_name
   FROM ($currentVotesSql) v
   JOIN products p ON p.product_id = v.product_id
   JOIN categories c ON c.category_id = p.category_id
   JOIN smes s ON s.sme_id = p.sme_id
   WHERE v.resident_id={$me['resident_id']}
   ORDER BY v.voted_at DESC")->fetch_all(MYSQLI_ASSOC);

$yesVoteCount = 0;
foreach ($myVotes as $vote) {
    if (($vote['vote_value'] ?? '') === 'Yes') {
        $yesVoteCount++;
    }
}

$interestCount = count($selectedInterestIds);
$voteCount = count($myVotes);
$votedProductLookup = [];
foreach ($myVotes as $vote) {
    $votedProductLookup[(int)$vote['product_id']] = true;
}

$interestRules = resident_interest_rules();
$recommendedCandidates = $conn->query("
   SELECT p.product_id, p.name, c.category_name AS category, p.description, p.cultural_benefits, p.price_category, p.price_value, s.company_name
   FROM products p
   JOIN categories c ON c.category_id = p.category_id
   JOIN smes s ON s.sme_id = p.sme_id
   ORDER BY p.created_at DESC, p.name ASC
")->fetch_all(MYSQLI_ASSOC);

$matchedInterestCoverage = [];
$recommendations = [];
foreach ($recommendedCandidates as $candidate) {
    $matchDetails = resident_product_match_details($candidate, $selectedInterestNames, $interestRules);
    if ((int)$matchDetails['score'] <= 0) {
        continue;
    }

    foreach ($matchDetails['matched_interests'] as $matchedInterest) {
        $matchedInterestCoverage[$matchedInterest] = true;
    }

    if (isset($votedProductLookup[(int)$candidate['product_id']])) {
        continue;
    }

    $candidate['match_score'] = (int)$matchDetails['score'];
    $candidate['matched_interests'] = $matchDetails['matched_interests'];
    $recommendations[] = $candidate;
}

usort($recommendations, static function (array $left, array $right): int {
    if ($left['match_score'] !== $right['match_score']) {
        return $right['match_score'] <=> $left['match_score'];
    }
    if ((float)$left['price_value'] !== (float)$right['price_value']) {
        return (float)$left['price_value'] <=> (float)$right['price_value'];
    }
    return strcmp($left['name'], $right['name']);
});

$recommendations = array_slice($recommendations, 0, 3);
$interestsMetNames = array_values(array_filter(
    $selectedInterestNames,
    static fn(string $interestName): bool => isset($matchedInterestCoverage[$interestName])
));
$interestsMetCount = count($interestsMetNames);

require 'includes/header.php';
?>
<section class="dashboard-page-header">
  <div class="dashboard-page-shell">
    <nav aria-label="breadcrumb" class="dashboard-breadcrumbs mb-3">
      <ol class="breadcrumb">
        <li class="breadcrumb-item">
          <a href="index.php"><i class="bi bi-house-door"></i><span>Home</span></a>
        </li>
        <li class="breadcrumb-item active" aria-current="page">Resident Dashboard</li>
      </ol>
    </nav>

    <div class="dashboard-page-top">
      <div class="dashboard-page-copy">
        <div class="dashboard-title-row">
          <span class="dashboard-title-icon" aria-hidden="true"><i class="bi bi-person-circle"></i></span>
          <div>
            <h1 class="dashboard-page-title"><?= htmlspecialchars($me['title_name'].' '.$me['full_name']) ?></h1>
            <p class="dashboard-page-subtitle">Track your cultural interests, update your resident profile, and keep your votes current.</p>
          </div>
        </div>
      </div>
      <div class="dashboard-page-actions">
        <a href="browse.php" class="btn btn-primary">Browse Offerings</a>
        <button
          type="button"
          class="btn btn-outline-primary"
          data-bs-toggle="collapse"
          data-bs-target="#residentProfileCollapse"
          aria-expanded="false"
          aria-controls="residentProfileCollapse"
          id="residentProfileToggle"
        >
          <span data-toggle-label>Edit Profile</span>
          <i class="bi bi-chevron-down ms-2"></i>
        </button>
      </div>
    </div>

    <div class="row g-3 mt-1">
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="dashboard-stat-card">
          <div class="dashboard-stat-label"><i class="bi bi-geo-alt"></i> Current Area</div>
          <div class="dashboard-stat-value dashboard-stat-value-text"><?= htmlspecialchars($me['area_name']) ?></div>
          <div class="dashboard-stat-note"><?= htmlspecialchars($me['age_group']) ?> · <?= htmlspecialchars($me['gender']) ?></div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="dashboard-stat-card">
          <div class="dashboard-stat-label"><i class="bi bi-stars"></i> Interests</div>
          <div class="dashboard-stat-value"><?= $interestCount ?></div>
          <div class="dashboard-stat-note">Creative categories saved to your profile.</div>
          <div class="resident-interest-tags">
            <?php if ($selectedInterestNames): ?>
              <?php foreach ($selectedInterestNames as $interestName): ?>
                <span class="badge rounded-pill resident-interest-badge"><?= htmlspecialchars($interestName) ?></span>
              <?php endforeach; ?>
            <?php else: ?>
              <span class="badge rounded-pill resident-interest-badge resident-interest-badge-muted">Add interests to personalise your dashboard.</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="dashboard-stat-card">
          <div class="dashboard-stat-label"><i class="bi bi-hand-thumbs-up"></i> Current Votes</div>
          <div class="dashboard-stat-value"><?= $voteCount ?></div>
          <div class="dashboard-stat-note">Offerings you have already reviewed.</div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="dashboard-stat-card">
          <div class="dashboard-stat-label"><i class="bi bi-heart"></i> Interests Met</div>
          <div class="dashboard-stat-value"><?= $interestsMetCount ?></div>
          <div class="dashboard-stat-note">Selected interests currently reflected in live offerings.</div>
          <?php if ($interestsMetNames): ?>
            <div class="resident-interest-tags">
              <?php foreach (array_slice($interestsMetNames, 0, 3) as $interestName): ?>
                <span class="badge rounded-pill resident-match-badge"><?= htmlspecialchars($interestName) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>
<section id="resident-profile" class="mb-4">
  <div class="collapse" id="residentProfileCollapse">
    <div class="card shadow-sm resident-profile-card">
      <div class="card-body p-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
          <div>
            <h4 class="mb-1">Edit Profile</h4>
            <p class="text-muted mb-0">Update your area, interests, and profile details only when something changes.</p>
          </div>
          <span class="badge text-bg-light resident-panel-badge">Hidden by default to keep your dashboard focused</span>
        </div>
        <form method="post" class="row g-3">
          <input type="hidden" name="action" value="update_profile">
          <div class="col-md-3">
            <label class="form-label">Title</label>
            <select name="title_id" class="form-select" required>
              <?php foreach ($titles as $title): ?>
                <option value="<?= $title['title_id'] ?>" <?= (int)$title['title_id'] === (int)$me['title_id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($title['title_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-9">
            <label class="form-label">Full Name</label>
            <input name="full_name" value="<?= htmlspecialchars($me['full_name']) ?>" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Area</label>
            <select name="area_id" class="form-select" required>
              <?php foreach ($areas as $area): ?>
                <option value="<?= $area['area_id'] ?>" <?= (int)$area['area_id'] === (int)$me['area_id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($area['area_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Location</label>
            <input name="location" value="<?= htmlspecialchars($me['location']) ?>" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label">Age Group</label>
            <select name="age_group" class="form-select" required>
              <?php foreach ($ageOptions as $ageOption): ?>
                <option <?= $ageOption === $me['age_group'] ? 'selected' : '' ?>><?= htmlspecialchars($ageOption) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Gender</label>
            <select name="gender" class="form-select" required>
              <?php foreach ($genderOptions as $genderOption): ?>
                <option <?= $genderOption === $me['gender'] ? 'selected' : '' ?>><?= htmlspecialchars($genderOption) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">Interests</label>
            <div class="resident-profile-interest-grid">
              <?php foreach ($interests as $interest): ?>
                <div class="form-check form-check-inline">
                  <input
                    type="checkbox"
                    class="form-check-input"
                    name="interests[]"
                    value="<?= $interest['interest_id'] ?>"
                    id="resident-interest-<?= $interest['interest_id'] ?>"
                    <?= in_array((int)$interest['interest_id'], $selectedInterestIds, true) ? 'checked' : '' ?>
                  >
                  <label class="form-check-label" for="resident-interest-<?= $interest['interest_id'] ?>">
                    <?= htmlspecialchars($interest['interest_name']) ?>
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="col-12 d-flex justify-content-end">
            <button class="btn btn-outline-primary">Save Profile</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</section>

<section class="card shadow-sm resident-votes-card mb-4" id="resident-votes">
  <div class="card-body p-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
      <div>
        <h4 class="mb-1">Your current votes</h4>
        <p class="text-muted mb-0">Keep track of the offerings and local businesses you are actively supporting.</p>
      </div>
      <a href="browse.php" class="btn btn-outline-primary">Discover More Offerings</a>
    </div>

    <div class="table-responsive">
      <table class="table align-middle mb-0">
        <thead><tr><th>Product</th><th>Category</th><th>Vote</th><th>When</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($myVotes as $v): ?>
            <tr>
              <td>
                <div class="resident-vote-product"><?= htmlspecialchars($v['product_name']) ?></div>
                <div class="resident-vote-company"><?= htmlspecialchars($v['company_name']) ?></div>
              </td>
              <td><?= htmlspecialchars($v['category']) ?></td>
              <td><span class="badge bg-<?= $v['vote_value']==='Yes'?'success':'danger' ?>"><?= $v['vote_value'] ?></span></td>
              <td><?= htmlspecialchars($v['voted_at']) ?></td>
              <td class="text-end">
                <form method="post" onsubmit="return confirm('Remove this vote?');">
                  <input type="hidden" name="action" value="delete_vote">
                  <input type="hidden" name="vote_id" value="<?= $v['vote_id'] ?>">
                  <button class="btn btn-outline-danger btn-sm">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$myVotes): ?><tr><td colspan="5" class="text-muted">No votes yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<section class="card shadow-sm resident-recommend-shell mb-4">
  <div class="card-body p-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
      <div>
        <h4 class="mb-1">Recommended for You</h4>
        <p class="text-muted mb-0">Suggestions based on the interest tags you selected in your resident profile.</p>
      </div>
      <a href="browse.php" class="btn btn-outline-primary">Browse All</a>
    </div>

    <?php if ($recommendations): ?>
      <div class="row g-3">
        <?php foreach ($recommendations as $product): ?>
          <?php $browseUrl = 'browse.php?' . http_build_query(['category' => $product['category'], 'keyword' => $product['name']]); ?>
          <div class="col-lg-4 col-md-6">
            <div class="card resident-recommend-card h-100 border-0">
              <div class="card-body p-4 d-flex flex-column">
                <div class="d-flex flex-wrap gap-2 mb-3">
                  <span class="badge rounded-pill text-bg-light"><?= htmlspecialchars($product['category']) ?></span>
                  <span class="badge rounded-pill resident-price-badge"><?= htmlspecialchars($product['price_category']) ?></span>
                </div>
                <h5 class="mb-1"><?= htmlspecialchars($product['name']) ?></h5>
                <p class="resident-vote-company mb-3"><?= htmlspecialchars($product['company_name']) ?></p>
                <p class="text-muted mb-3"><?= htmlspecialchars($product['description']) ?></p>
                <div class="resident-interest-tags mb-3">
                  <?php foreach ($product['matched_interests'] as $interestName): ?>
                    <span class="badge rounded-pill resident-match-badge"><?= htmlspecialchars($interestName) ?></span>
                  <?php endforeach; ?>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-auto">
                  <div>
                    <div class="resident-price-value">£<?= number_format((float)$product['price_value'], 2) ?></div>
                    <div class="small text-muted">Listed resident price</div>
                  </div>
                  <a href="<?= htmlspecialchars($browseUrl) ?>" class="btn btn-outline-primary btn-sm">View in Browse</a>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php elseif ($selectedInterestNames): ?>
      <div class="alert alert-light border mb-0">No new recommendations match your current interests just yet. Try browsing all offerings to explore what is newly available.</div>
    <?php else: ?>
      <div class="alert alert-light border mb-0">Add a few interests in your profile to unlock personalised recommendations here.</div>
    <?php endif; ?>
  </div>
</section>

<div class="card shadow-sm mt-4 resident-danger-card"><div class="card-body p-4">
  <h4 class="text-danger-emphasis mb-2">Delete Resident Account</h4>
  <p class="text-muted mb-3">If you leave the platform, this will remove your resident profile, interests, and voting history.</p>
  <button
    type="button"
    class="btn btn-outline-danger"
    data-bs-toggle="modal"
    data-bs-target="#residentDeleteModal"
    data-resident-name="<?= htmlspecialchars($me['full_name']) ?>"
  >
    Delete Account
  </button>
</div></div>

<div class="modal fade" id="residentDeleteModal" tabindex="-1" aria-labelledby="residentDeleteModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title" id="residentDeleteModalLabel">Delete Resident Account?</h5>
          <p class="small text-muted mb-0" id="residentDeleteModalCopy">This action cannot be undone.</p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        This will remove your profile details, saved interests, and all voting history from CultureConnect.
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <form method="post" class="mb-0">
          <input type="hidden" name="action" value="delete_account">
          <button class="btn btn-danger">Yes, Delete My Account</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var profileCollapse = document.getElementById('residentProfileCollapse');
  var profileToggle = document.getElementById('residentProfileToggle');
  var profileToggleLabel = profileToggle ? profileToggle.querySelector('[data-toggle-label]') : null;

  function syncProfileToggle(isOpen) {
    if (!profileToggle || !profileToggleLabel) {
      return;
    }
    profileToggle.classList.toggle('is-open', isOpen);
    profileToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    profileToggleLabel.textContent = isOpen ? 'Hide Profile Form' : 'Edit Profile';
  }

  if (profileCollapse && profileToggle) {
    profileCollapse.addEventListener('shown.bs.collapse', function () {
      syncProfileToggle(true);
      profileCollapse.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    profileCollapse.addEventListener('hidden.bs.collapse', function () {
      syncProfileToggle(false);
    });

    syncProfileToggle(profileCollapse.classList.contains('show'));

    if (window.location.hash === '#resident-profile' && window.bootstrap && window.bootstrap.Collapse) {
      window.bootstrap.Collapse.getOrCreateInstance(profileCollapse, { toggle: false }).show();
    }
  }

  var deleteModal = document.getElementById('residentDeleteModal');
  if (deleteModal) {
    deleteModal.addEventListener('show.bs.modal', function (event) {
      var trigger = event.relatedTarget;
      var residentName = trigger ? trigger.getAttribute('data-resident-name') : '';
      var copyTarget = document.getElementById('residentDeleteModalCopy');
      if (copyTarget) {
        copyTarget.textContent = residentName
          ? 'Delete ' + residentName + '\'s resident profile and voting history permanently.'
          : 'This action cannot be undone.';
      }
    });
  }
});
</script>
<?php require 'includes/footer.php'; ?>
