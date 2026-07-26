<?php
require 'includes/db.php';
require 'includes/auth.php';
require 'includes/product_media.php';

$databaseImageStorageEnabled = product_image_database_storage_supported($conn);

function browse_bind_params(mysqli_stmt $statement, string $types, array &$params): void
{
    $references = [];
    foreach ($params as $key => $value) {
        $references[$key] = &$params[$key];
    }
    array_unshift($references, $types);
    call_user_func_array([$statement, 'bind_param'], $references);
}

function browse_score_badge_class(int $score): string
{
    if ($score > 0) {
        return 'text-bg-success';
    }
    if ($score < 0) {
        return 'text-bg-danger';
    }
    return 'text-bg-secondary';
}

function browse_currency_label(float $amount): string
{
    $formatted = number_format($amount, 2, '.', '');
    $formatted = rtrim(rtrim($formatted, '0'), '.');

    return '£' . $formatted;
}

function browse_category_media(string $category): array
{
    $mediaMap = [
        'Workshops' => ['🎨', 'browse-media-workshops'],
        'Performing Arts' => ['🎭', 'browse-media-performance'],
        'Visual Arts' => ['🖌️', 'browse-media-visual'],
        'Handmade Goods' => ['🏺', 'browse-media-handmade'],
        'Literary & Media' => ['🎬', 'browse-media-media'],
        'Media' => ['🎬', 'browse-media-media'],
        'Health & Wellness' => ['🧘', 'browse-media-wellness'],
    ];

    return $mediaMap[$category] ?? ['✨', 'browse-media-generic'];
}

$currentVoteCountsSql = "
    SELECT v.product_id,
           SUM(CASE WHEN v.vote_value = 'Yes' THEN 1 ELSE 0 END) AS yes_votes,
           SUM(CASE WHEN v.vote_value = 'No' THEN 1 ELSE 0 END) AS no_votes
    FROM votes v
    JOIN (
        SELECT resident_id, product_id, MAX(vote_id) AS vote_id
        FROM votes
        GROUP BY resident_id, product_id
    ) latest ON latest.vote_id = v.vote_id
    GROUP BY v.product_id
";

$categoryAliases = [
    'Media' => ['Media', 'Literary & Media'],
    'Literary & Media' => ['Literary & Media', 'Media'],
];

$category = trim($_GET['category'] ?? '');
$keyword = trim($_GET['keyword'] ?? '');
$underPriceFilter = isset($_GET['under200']) && $_GET['under200'] === '1';
$minPriceInput = trim($_GET['min_price'] ?? '');
$maxPriceInput = trim($_GET['max_price'] ?? '');
$minPriceValue = null;
$maxPriceValue = null;
$priceFilterLabels = [];

if ($minPriceInput !== '') {
    if (is_numeric($minPriceInput)) {
        $minPriceValue = max(0, (float)$minPriceInput);
        $minPriceInput = (string)$minPriceValue;
    } else {
        $minPriceInput = '';
    }
}

if ($maxPriceInput !== '') {
    if (is_numeric($maxPriceInput)) {
        $maxPriceValue = max(0, (float)$maxPriceInput);
        $maxPriceInput = (string)$maxPriceValue;
    } else {
        $maxPriceInput = '';
    }
}

if ($minPriceValue !== null && $maxPriceValue !== null && $minPriceValue > $maxPriceValue) {
    [$minPriceValue, $maxPriceValue] = [$maxPriceValue, $minPriceValue];
    [$minPriceInput, $maxPriceInput] = [(string)$minPriceValue, (string)$maxPriceValue];
}

$cats = $conn->query('SELECT category_id, category_name AS category FROM categories ORDER BY category_name')->fetch_all(MYSQLI_ASSOC);
$validCategories = array_column($cats, 'category');
$categoryOptions = [];
if ($category !== '') {
    $categoryOptions = $categoryAliases[$category] ?? [$category];
    $categoryOptions = array_values(array_intersect($categoryOptions, $validCategories));
    if (!$categoryOptions) {
        $category = '';
    }
}

$productImageSelectSql = $databaseImageStorageEnabled
    ? "CASE WHEN p.product_image_data IS NOT NULL AND p.product_image_data <> '' THEN 1 ELSE 0 END AS product_image_has_data"
    : "0 AS product_image_has_data";

$sql = "
    SELECT
           p.product_id,
           p.sme_id,
           p.name,
           p.description,
           p.category_id,
           p.size_quantity,
           p.cultural_benefits,
           p.price_category,
           p.price_value,
           p.awards,
           p.memberships,
           p.exhibitions,
           p.product_image,
           p.created_at,
           {$productImageSelectSql},
           c.category_name AS category,
           s.company_name,
           COALESCE(vc.yes_votes, 0) AS yes_votes,
           COALESCE(vc.no_votes, 0) AS no_votes
    FROM products p
    JOIN categories c ON c.category_id = p.category_id
    JOIN smes s ON s.sme_id = p.sme_id
    LEFT JOIN ($currentVoteCountsSql) vc ON vc.product_id = p.product_id
";

$conditions = [];
$types = '';
$params = [];

if ($category !== '') {
    if (count($categoryOptions) === 1) {
        $conditions[] = 'c.category_name = ?';
        $types .= 's';
        $params[] = $categoryOptions[0];
    } elseif (count($categoryOptions) === 2) {
        $conditions[] = 'c.category_name IN (?, ?)';
        $types .= 'ss';
        $params[] = $categoryOptions[0];
        $params[] = $categoryOptions[1];
    }
}

if ($underPriceFilter) {
    $conditions[] = 'p.price_value < 200';
    $priceFilterLabels[] = 'price under £200';
}

if ($minPriceValue !== null) {
    $conditions[] = 'p.price_value >= ?';
    $types .= 'd';
    $params[] = $minPriceValue;
}

if ($maxPriceValue !== null) {
    $conditions[] = 'p.price_value <= ?';
    $types .= 'd';
    $params[] = $maxPriceValue;
}

if ($minPriceValue !== null && $maxPriceValue !== null) {
    $priceFilterLabels[] = browse_currency_label($minPriceValue) . ' to ' . browse_currency_label($maxPriceValue);
} elseif ($minPriceValue !== null) {
    $priceFilterLabels[] = 'from ' . browse_currency_label($minPriceValue);
} elseif ($maxPriceValue !== null) {
    $priceFilterLabels[] = 'up to ' . browse_currency_label($maxPriceValue);
}

if ($keyword !== '') {
    $conditions[] = '(p.name LIKE ? OR s.company_name LIKE ? OR p.description LIKE ?)';
    $keywordLike = '%' . $keyword . '%';
    $types .= 'sss';
    $params[] = $keywordLike;
    $params[] = $keywordLike;
    $params[] = $keywordLike;
}

if ($conditions) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}

$sql .= ' ORDER BY p.created_at DESC, p.name ASC';
$stmt = $conn->prepare($sql);
if ($types !== '') {
    browse_bind_params($stmt, $types, $params);
}
$stmt->execute();
$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$residentVoteMap = [];
$residentRole = ($_SESSION['role'] ?? '') === 'resident';
if ($residentRole && !empty($_SESSION['user_id'])) {
    $residentLookup = $conn->prepare('SELECT resident_id FROM residents WHERE user_id = ?');
    $residentLookup->bind_param('i', $_SESSION['user_id']);
    $residentLookup->execute();
    $residentId = (int)($residentLookup->get_result()->fetch_assoc()['resident_id'] ?? 0);

    if ($residentId > 0) {
        $residentVotes = $conn->prepare("
            SELECT v.product_id, v.vote_value
            FROM votes v
            JOIN (
                SELECT product_id, MAX(vote_id) AS vote_id
                FROM votes
                WHERE resident_id = ?
                GROUP BY product_id
            ) latest ON latest.vote_id = v.vote_id
        ");
        $residentVotes->bind_param('i', $residentId);
        $residentVotes->execute();
        $residentVoteRows = $residentVotes->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($residentVoteRows as $voteRow) {
            $residentVoteMap[(int)$voteRow['product_id']] = $voteRow['vote_value'];
        }
    }
}

$resultLabelParts = [];
if ($category !== '') {
    $resultLabelParts[] = 'category: ' . $category;
}
if ($priceFilterLabels) {
    $resultLabelParts[] = implode(' • ', $priceFilterLabels);
}
if ($keyword !== '') {
    $resultLabelParts[] = 'keyword: "' . $keyword . '"';
}

require 'includes/header.php';
?>
<section class="browse-shell">
  <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
    <div>
      <p class="section-eyebrow mb-2">Browse Cultural Offerings</p>
      <h2 class="section-heading mb-2">Find services and products that reflect local creative life</h2>
      <p class="section-copy mb-0">Explore local offerings by category, keywords, or price to discover the experiences that interest you most.</p>
    </div>
    <div class="browse-summary-pill">
      <?= count($products) ?> offering<?= count($products) === 1 ? '' : 's' ?>
      <?= $resultLabelParts ? 'matching ' . htmlspecialchars(implode(' • ', $resultLabelParts)) : 'available right now' ?>
    </div>
  </div>

  <div class="card border-0 shadow-sm browse-filter-card mb-4">
    <div class="card-body p-4">
      <form method="get" class="row g-3 align-items-end">
        <div class="col-12 col-lg-3">
          <label class="form-label fw-semibold" for="browse-category">Category</label>
          <select name="category" id="browse-category" class="form-select">
            <option value="">All categories</option>
            <?php foreach ($cats as $cat): ?>
              <option value="<?= htmlspecialchars($cat['category']) ?>" <?= $cat['category'] === $category ? 'selected' : '' ?>>
                <?= htmlspecialchars($cat['category']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 col-lg-4">
          <label class="form-label fw-semibold" for="browse-keyword">Search by keyword</label>
          <div class="input-group">
            <span class="input-group-text">🔎</span>
            <input
              type="search"
              id="browse-keyword"
              name="keyword"
              value="<?= htmlspecialchars($keyword) ?>"
              class="form-control"
              placeholder="Search products, descriptions, or SME names"
            >
          </div>
        </div>

        <div class="col-12 col-md-6 col-lg-3">
          <label class="form-label fw-semibold">Set price limits</label>
          <div class="row g-2">
            <div class="col-6">
              <div class="input-group">
                <span class="input-group-text">£</span>
                <input
                  type="number"
                  min="0"
                  step="0.01"
                  name="min_price"
                  value="<?= htmlspecialchars($minPriceInput) ?>"
                  class="form-control"
                  placeholder="Min"
                  inputmode="decimal"
                >
              </div>
            </div>
            <div class="col-6">
              <div class="input-group">
                <span class="input-group-text">£</span>
                <input
                  type="number"
                  min="0"
                  step="0.01"
                  name="max_price"
                  value="<?= htmlspecialchars($maxPriceInput) ?>"
                  class="form-control"
                  placeholder="Max"
                  inputmode="decimal"
                >
              </div>
            </div>
          </div>
        </div>

        <div class="col-12 col-md-6 col-lg-2">
          <div class="browse-filter-actions">
            <div class="form-check">
              <input type="checkbox" name="under200" value="1" id="browse-under200" class="form-check-input" <?= $underPriceFilter ? 'checked' : '' ?>>
              <label for="browse-under200" class="form-check-label fw-semibold">Under £200</label>
            </div>
            <div class="d-flex gap-2 flex-grow-1 justify-content-lg-end">
              <button class="btn btn-primary flex-grow-1 flex-lg-grow-0">Apply Filters</button>
              <a href="browse.php" class="btn btn-outline-secondary flex-grow-1 flex-lg-grow-0">Reset</a>
            </div>
          </div>
        </div>
      </form>

      <p class="browse-filter-note mb-0 mt-3">Browse by category to explore your interests, use the filter options to narrow the results.</p>
    </div>
  </div>

  <?php if (!$products): ?>
    <div class="browse-empty-state shadow-sm">
      <div class="browse-empty-icon">🔍</div>
      <h3 class="h4 mb-2">No offerings found matching your criteria</h3>
      <p class="section-copy mb-4">Try clearing the keyword, choosing a different category, or loosening the selected price filters to widen the search.</p>
      <a href="browse.php" class="btn btn-primary">View All Offerings</a>
    </div>
  <?php endif; ?>

  <div class="row g-4 align-items-start browse-product-grid">
    <?php foreach ($products as $product): ?>
      <?php
      [$mediaIcon, $mediaClass] = browse_category_media($product['category']);
      $productImageUrl = product_image_url($product);
      $productImageAlt = product_image_has_custom_image($product)
          ? $product['name'] . ' product image'
          : $product['name'] . ' placeholder artwork';
      $yesVotes = (int)$product['yes_votes'];
      $noVotes = (int)$product['no_votes'];
      $totalVotes = $yesVotes + $noVotes;
      $interestScore = $yesVotes - $noVotes;
      $yesPercent = $totalVotes > 0 ? (int)round(($yesVotes / $totalVotes) * 100) : 0;
      $currentResidentVote = $residentVoteMap[(int)$product['product_id']] ?? null;
      $returnTo = 'browse.php';
      if (!empty($_SERVER['QUERY_STRING'])) {
          $returnTo .= '?' . $_SERVER['QUERY_STRING'];
      }
      $returnTo .= '#offering-' . $product['product_id'];
      $hasMoreInfo = trim((string)$product['size_quantity']) !== ''
          || trim((string)$product['cultural_benefits']) !== ''
          || trim((string)$product['awards']) !== ''
          || trim((string)$product['memberships']) !== ''
          || trim((string)$product['exhibitions']) !== '';
      ?>
      <div class="col-md-6 col-lg-4" id="offering-<?= $product['product_id'] ?>">
        <div class="card browse-card h-100 border-0 shadow-sm">
          <div class="browse-card-media-frame">
            <a
              href="#imageModal"
              class="browse-card-image-trigger"
              data-bs-toggle="modal"
              data-bs-target="#imageModal"
              data-image-src="<?= htmlspecialchars($productImageUrl) ?>"
              data-image-alt="<?= htmlspecialchars($productImageAlt) ?>"
              data-product-name="<?= htmlspecialchars($product['name']) ?>"
              data-sme-name="<?= htmlspecialchars($product['company_name']) ?>"
              aria-label="View a larger image of <?= htmlspecialchars($product['name']) ?>"
            >
              <img
                src="<?= htmlspecialchars($productImageUrl) ?>"
                alt="<?= htmlspecialchars($productImageAlt) ?>"
                class="card-img-top browse-card-media"
                loading="lazy"
                decoding="async"
              >
              <div class="browse-card-badges">
                <span class="badge rounded-pill text-bg-light browse-card-badge"><?= htmlspecialchars($product['category']) ?></span>
                <span class="badge rounded-pill browse-price-badge"><?= htmlspecialchars($product['price_category']) ?></span>
              </div>
              <span class="browse-card-icon <?= htmlspecialchars($mediaClass) ?>" aria-hidden="true"><?= $mediaIcon ?></span>
            </a>
          </div>

          <div class="card-body p-4 d-flex flex-column">
            <div class="mb-3">
              <h3 class="browse-card-title mb-1"><?= htmlspecialchars($product['name']) ?></h3>
              <p class="browse-card-sme mb-0"><?= htmlspecialchars($product['company_name']) ?></p>
            </div>

            <p class="browse-card-description mb-3"><?= htmlspecialchars($product['description']) ?></p>

            <div class="browse-card-price mb-3">
              <span class="browse-card-price-value">£<?= number_format((float)$product['price_value'], 2) ?></span>
              <span class="browse-card-price-note">Listed price</span>
            </div>

            <div class="browse-score-panel mb-3">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-semibold">Total Interest Score</span>
                <span class="badge <?= browse_score_badge_class($interestScore) ?>">
                  <?= $interestScore > 0 ? '+' . $interestScore : $interestScore ?>
                </span>
              </div>
              <div class="progress browse-score-progress mb-2" role="progressbar" aria-label="Resident voting feedback" aria-valuenow="<?= $yesPercent ?>" aria-valuemin="0" aria-valuemax="100">
                <?php if ($totalVotes > 0): ?>
                  <div class="progress-bar bg-success" style="width: <?= $yesPercent ?>%"></div>
                  <div class="progress-bar bg-danger" style="width: <?= 100 - $yesPercent ?>%"></div>
                <?php else: ?>
                  <div class="progress-bar bg-secondary" style="width: 100%"></div>
                <?php endif; ?>
              </div>
              <div class="d-flex justify-content-between small text-muted">
                <span>👍 Yes <?= $yesVotes ?></span>
                <span><?= $totalVotes > 0 ? $yesPercent . '% positive' : 'No votes yet' ?></span>
                <span>👎 No <?= $noVotes ?></span>
              </div>
            </div>

            <?php if ($residentRole): ?>
              <form method="post" action="vote.php" class="mt-auto">
                <input type="hidden" name="product_id" value="<?= $product['product_id'] ?>">
                <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo) ?>">
                <div class="d-flex gap-2 flex-wrap">
                  <button name="vote" value="Yes" class="btn <?= $currentResidentVote === 'Yes' ? 'btn-success' : 'btn-outline-success' ?> flex-grow-1">
                    👍 Yes
                  </button>
                  <button name="vote" value="No" class="btn <?= $currentResidentVote === 'No' ? 'btn-danger' : 'btn-outline-danger' ?> flex-grow-1">
                    👎 No
                  </button>
                </div>
                <div class="small text-muted mt-2">
                  <?= $currentResidentVote ? 'Your current vote: ' . htmlspecialchars($currentResidentVote) : 'Cast your vote to help councils understand local demand.' ?>
                </div>
              </form>
            <?php elseif (logged_in()): ?>
              <div class="mt-auto small text-muted fw-semibold">Resident accounts can vote on offerings.</div>
            <?php else: ?>
              <div class="mt-auto">
                <a href="login.php" class="btn btn-outline-primary">Sign in to vote</a>
              </div>
            <?php endif; ?>
          </div>

          <?php if ($hasMoreInfo): ?>
            <div class="card-footer bg-transparent border-0 pt-0 px-4 pb-4">
              <button class="btn btn-sm btn-outline-secondary browse-more-btn" type="button" data-bs-toggle="collapse" data-bs-target="#browse-more-<?= $product['product_id'] ?>" aria-expanded="false" aria-controls="browse-more-<?= $product['product_id'] ?>" data-collapsed-label="More Info" data-expanded-label="Less Info">
                More Info
              </button>
              <div class="collapse mt-3" id="browse-more-<?= $product['product_id'] ?>">
                <div class="browse-more-panel">
                  <?php if (trim((string)$product['size_quantity']) !== ''): ?>
                    <div class="browse-detail-row">
                      <strong>Size / Quantity</strong>
                      <span><?= htmlspecialchars($product['size_quantity']) ?></span>
                    </div>
                  <?php endif; ?>
                  <?php if (trim((string)$product['cultural_benefits']) !== ''): ?>
                    <div class="browse-detail-row">
                      <strong>Cultural Benefits</strong>
                      <span><?= htmlspecialchars($product['cultural_benefits']) ?></span>
                    </div>
                  <?php endif; ?>

                  <?php if (
                      trim((string)$product['awards']) !== ''
                      || trim((string)$product['memberships']) !== ''
                      || trim((string)$product['exhibitions']) !== ''
                  ): ?>
                    <div class="browse-achievements">
                      <div class="browse-achievements-title">Achievements</div>
                      <div class="d-flex flex-wrap gap-2">
                        <?php if (trim((string)$product['awards']) !== ''): ?>
                          <span class="badge rounded-pill text-bg-warning">Awards: <?= htmlspecialchars($product['awards']) ?></span>
                        <?php endif; ?>
                        <?php if (trim((string)$product['memberships']) !== ''): ?>
                          <span class="badge rounded-pill text-bg-info">Memberships: <?= htmlspecialchars($product['memberships']) ?></span>
                        <?php endif; ?>
                        <?php if (trim((string)$product['exhibitions']) !== ''): ?>
                          <span class="badge rounded-pill text-bg-light">Exhibitions: <?= htmlspecialchars($product['exhibitions']) ?></span>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<div class="modal fade" id="imageModal" tabindex="-1" aria-labelledby="imageModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl browse-image-modal-dialog">
    <div class="modal-content browse-image-modal-content border-0">
      <div class="modal-header browse-image-modal-header">
        <div>
          <h2 class="modal-title h4 mb-1" id="imageModalTitle">Product Image</h2>
          <p class="browse-image-modal-meta mb-0" id="imageModalSme">Creative SME</p>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body browse-image-modal-body text-center">
        <img
          src="assets/images/product-placeholder.svg"
          alt="Expanded product image"
          class="img-fluid rounded browse-image-modal-image"
          id="imageModalImage"
          loading="eager"
          decoding="async"
        >
      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn browse-image-modal-close-btn" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var imageModal = document.getElementById('imageModal');
  if (imageModal) {
    var modalImage = document.getElementById('imageModalImage');
    var modalTitle = document.getElementById('imageModalTitle');
    var modalSme = document.getElementById('imageModalSme');
    var placeholderSrc = 'assets/images/product-placeholder.svg';

    document.querySelectorAll('.browse-card-image-trigger').forEach(function (trigger) {
      trigger.addEventListener('click', function (event) {
        event.preventDefault();
      });
    });

    imageModal.addEventListener('show.bs.modal', function (event) {
      var trigger = event.relatedTarget;
      if (!trigger) {
        return;
      }

      var imageSrc = trigger.getAttribute('data-image-src') || placeholderSrc;
      var imageAlt = trigger.getAttribute('data-image-alt') || 'Expanded product image';
      var productName = trigger.getAttribute('data-product-name') || 'Product Image';
      var smeName = trigger.getAttribute('data-sme-name') || 'Creative SME';

      modalImage.src = imageSrc;
      modalImage.alt = imageAlt;
      modalTitle.textContent = productName;
      modalSme.textContent = smeName;
    });

    imageModal.addEventListener('hidden.bs.modal', function () {
      modalImage.src = placeholderSrc;
      modalImage.alt = 'Expanded product image';
      modalTitle.textContent = 'Product Image';
      modalSme.textContent = 'Creative SME';
    });
  }

  document.querySelectorAll('.browse-more-btn').forEach(function (button) {
    var targetSelector = button.getAttribute('data-bs-target');
    if (!targetSelector) {
      return;
    }

    var targetPanel = document.querySelector(targetSelector);
    if (!targetPanel) {
      return;
    }

    var collapsedLabel = button.getAttribute('data-collapsed-label') || 'More Info';
    var expandedLabel = button.getAttribute('data-expanded-label') || 'Less Info';

    var syncButtonLabel = function (isExpanded) {
      button.textContent = isExpanded ? expandedLabel : collapsedLabel;
    };

    syncButtonLabel(targetPanel.classList.contains('show'));
    targetPanel.addEventListener('show.bs.collapse', function () {
      syncButtonLabel(true);
    });
    targetPanel.addEventListener('hide.bs.collapse', function () {
      syncButtonLabel(false);
    });
  });
});
</script>
<?php require 'includes/footer.php'; ?>
