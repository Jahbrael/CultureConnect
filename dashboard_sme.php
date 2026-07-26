<?php
require 'includes/db.php';
require 'includes/auth.php';
require 'includes/product_media.php';
require_role('sme');

$categories = $conn->query('SELECT category_id, category_name FROM categories ORDER BY category_name ASC')
    ->fetch_all(MYSQLI_ASSOC);
$categoryNameById = [];
$healthWellnessCategoryId = 0;
foreach ($categories as $categoryRow) {
    $categoryId = (int)$categoryRow['category_id'];
    $categoryNameById[$categoryId] = $categoryRow['category_name'];
    if (strcasecmp($categoryRow['category_name'], 'Health & Wellness') === 0) {
        $healthWellnessCategoryId = $categoryId;
    }
}

function sme_dashboard_url(string $anchor = ''): string
{
    $hash = $anchor !== '' ? '#' . rawurlencode($anchor) : '';
    return 'dashboard_sme.php' . $hash;
}

function find_sme_product_record(mysqli $conn, int $smeId, int $productId): array
{
    $sql = product_image_database_storage_supported($conn)
        ? 'SELECT product_id, product_image, product_image_mime, product_image_data FROM products WHERE product_id = ? AND sme_id = ?'
        : 'SELECT product_id, product_image FROM products WHERE product_id = ? AND sme_id = ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $productId, $smeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return [
        'exists' => $row !== null,
        'product_image' => $row['product_image'] ?? PRODUCT_IMAGE_DB_PLACEHOLDER,
        'product_image_mime' => $row['product_image_mime'] ?? null,
        'product_image_data' => $row['product_image_data'] ?? null,
    ];
}

function sme_product_image_names(mysqli $conn, int $smeId): array
{
    $stmt = $conn->prepare('SELECT product_image FROM products WHERE sme_id = ?');
    $stmt->bind_param('i', $smeId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    return array_map(
        static fn(array $row): string => (string)($row['product_image'] ?? PRODUCT_IMAGE_DB_PLACEHOLDER),
        $rows
    );
}

function product_form_data(array $source): array
{
    return [
        'name' => trim($source['name'] ?? ''),
        'description' => trim($source['description'] ?? ''),
        'category_id' => (int)($source['category_id'] ?? 0),
        'size_quantity' => trim($source['size_quantity'] ?? ''),
        'cultural_benefits' => trim($source['cultural_benefits'] ?? ''),
        'price_category' => $source['price_category'] ?? '',
        'price_value' => (float)($source['price_value'] ?? 0),
        'awards' => trim($source['awards'] ?? ''),
        'memberships' => trim($source['memberships'] ?? ''),
        'exhibitions' => trim($source['exhibitions'] ?? ''),
    ];
}

function validate_product_data(
    mysqli $conn,
    int $smeId,
    array $product,
    array $categoryNameById,
    int $healthWellnessCategoryId,
    ?int $excludeProductId = null
): array
{
    $errors = [];

    if ($product['name'] === '') $errors[] = 'Product or service name is required.';
    if ($product['description'] === '') $errors[] = 'Description is required.';
    if ($product['cultural_benefits'] === '') $errors[] = 'Cultural benefits are required.';
    if (!$categoryNameById) {
        $errors[] = 'No council-managed categories are available yet. Ask the council to add categories first.';
    }
    if ($product['category_id'] <= 0 || !isset($categoryNameById[$product['category_id']])) {
        $errors[] = 'Please choose a valid category.';
    }
    if (!in_array($product['price_category'], ['Affordable', 'Moderate', 'Premium'], true)) {
        $errors[] = 'Invalid price category.';
    }
    if ($product['price_value'] <= 0) $errors[] = 'Please enter a positive price.';

    if ($healthWellnessCategoryId > 0 && $product['category_id'] === $healthWellnessCategoryId) {
        if ($excludeProductId) {
            $stmt = $conn->prepare('SELECT product_id FROM products WHERE sme_id = ? AND category_id = ? AND product_id <> ?');
            $stmt->bind_param('iii', $smeId, $healthWellnessCategoryId, $excludeProductId);
        } else {
            $stmt = $conn->prepare('SELECT product_id FROM products WHERE sme_id = ? AND category_id = ?');
            $stmt->bind_param('ii', $smeId, $healthWellnessCategoryId);
        }
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $errors[] = 'You already have a Health & Wellness listing. Each SME can register only one Health & Wellness offering at a time.';
        }
    }

    return $errors;
}

$uid = $_SESSION['user_id'];
$sme = $conn->query("SELECT * FROM smes WHERE user_id=$uid")->fetch_assoc();
$email = $_SESSION['email'] ?? '';
$openProductModalId = (int)($_SESSION['sme_open_modal_id'] ?? 0);
$databaseImageStorageEnabled = product_image_database_storage_supported($conn);
unset($_SESSION['sme_open_modal_id']);

if (!$sme) {
    flash('danger', 'SME profile missing.');
    header('Location: logout.php'); exit;
}

$sid = $sme['sme_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_sme_profile') {
    $company = trim($_POST['company_name'] ?? '');
    $contact = trim($_POST['contact_name'] ?? '');
    $phone = trim($_POST['contact_phone'] ?? '');
    $portfolio = trim($_POST['portfolio_url'] ?? '');

    if ($company === '') {
        flash('danger', 'Company or artist name is required.');
    } else {
        $s = $conn->prepare('UPDATE smes SET company_name=?, contact_name=?, contact_phone=?, portfolio_url=? WHERE sme_id=? AND user_id=?');
        $s->bind_param('ssssii', $company, $contact, $phone, $portfolio, $sid, $uid);
        if ($s->execute()) {
            flash('success', 'SME profile updated successfully.');
        } else {
            flash('danger', 'Could not update the SME profile. Please try again.');
        }
    }

    header('Location: ' . sme_dashboard_url('sme-profile-panel')); exit;
}

// Add new product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_product'])) {
    $product = product_form_data($_POST);
    $errors = validate_product_data($conn, $sid, $product, $categoryNameById, $healthWellnessCategoryId);
    $productImage = PRODUCT_IMAGE_DB_PLACEHOLDER;
    $productImageMime = null;
    $productImageData = null;

    $uploadError = (int)(($_FILES['product_image']['error'] ?? UPLOAD_ERR_NO_FILE));
    if (!$errors && $uploadError !== UPLOAD_ERR_NO_FILE) {
        if (!$databaseImageStorageEnabled) {
            $errors[] = 'Database image storage is not enabled yet. Run sql/add_product_image_storage_columns.sql once and try again.';
        } else {
            $imageError = null;
            $imagePayload = prepare_uploaded_product_image_data($_FILES['product_image'] ?? [], $imageError);
            if ($imagePayload === null) {
                $errors[] = $imageError ?? 'Image upload failed. Please try again.';
            } else {
                $productImageMime = $imagePayload['mime'];
                $productImageData = $imagePayload['data'];
            }
        }
    }

    if (!$errors && $uploadError === UPLOAD_ERR_NO_FILE) {
        $productImage = PRODUCT_IMAGE_DB_PLACEHOLDER;
    }

    if (!$errors && $databaseImageStorageEnabled) {
        $s = $conn->prepare('INSERT INTO products (sme_id,name,description,category_id,size_quantity,cultural_benefits,price_category,price_value,awards,memberships,exhibitions,product_image,product_image_mime,product_image_data) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $s->bind_param(
            'ississsdssssss',
            $sid,
            $product['name'],
            $product['description'],
            $product['category_id'],
            $product['size_quantity'],
            $product['cultural_benefits'],
            $product['price_category'],
            $product['price_value'],
            $product['awards'],
            $product['memberships'],
            $product['exhibitions'],
            $productImage,
            $productImageMime,
            $productImageData
        );
        if ($s->execute()) {
            flash(
                'success',
                $productImageData === null
                    ? 'Listing added successfully.'
                    : 'Image uploaded successfully and listing added.'
            );
        } else {
            flash('danger', 'Could not add the listing. Please try again.');
        }
    } elseif (!$errors) {
        $imageError = null;
        $s = $conn->prepare('INSERT INTO products (sme_id,name,description,category_id,size_quantity,cultural_benefits,price_category,price_value,awards,memberships,exhibitions,product_image) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $s->bind_param(
            'ississsdssss',
            $sid,
            $product['name'],
            $product['description'],
            $product['category_id'],
            $product['size_quantity'],
            $product['cultural_benefits'],
            $product['price_category'],
            $product['price_value'],
            $product['awards'],
            $product['memberships'],
            $product['exhibitions'],
            $productImage
        );
        if ($s->execute()) {
            flash(
                'success',
                $productImage === PRODUCT_IMAGE_DB_PLACEHOLDER
                    ? 'Listing added successfully.'
                    : 'Image uploaded successfully and listing added.'
            );
        } else {
            flash('danger', 'Could not add the listing. Please try again.');
        }
    } else {
        foreach ($errors as $e) flash('danger', $e);
    }
    header('Location: ' . sme_dashboard_url('sme-add-listing')); exit;
}

// Edit product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_product'])) {
    $pid = (int)($_POST['product_id'] ?? 0);
    $product = product_form_data($_POST);
    $errors = validate_product_data($conn, $sid, $product, $categoryNameById, $healthWellnessCategoryId, $pid);
    $currentProduct = find_sme_product_record($conn, $sid, $pid);

    if (!$currentProduct['exists']) {
        flash('danger', 'Listing not found.');
        header('Location: ' . sme_dashboard_url('sme-product-catalog')); exit;
    }

    $uploadError = (int)(($_FILES['product_image']['error'] ?? UPLOAD_ERR_NO_FILE));
    $replacementImageMime = null;
    $replacementImageData = null;
    if (!$errors && $uploadError !== UPLOAD_ERR_NO_FILE) {
        if (!$databaseImageStorageEnabled) {
            $errors[] = 'Database image storage is not enabled yet. Run sql/add_product_image_storage_columns.sql once and try again.';
        } else {
            $imageError = null;
            $imagePayload = prepare_uploaded_product_image_data($_FILES['product_image'], $imageError);
            if ($imagePayload === null) {
                $errors[] = $imageError ?? 'Image upload failed. Please try again.';
            } else {
                $replacementImageMime = $imagePayload['mime'];
                $replacementImageData = $imagePayload['data'];
            }
        }
    }

    if (!$errors && $databaseImageStorageEnabled) {
        $imageToStore = $replacementImageData !== null
            ? PRODUCT_IMAGE_DB_PLACEHOLDER
            : (string)$currentProduct['product_image'];
        $imageMimeToStore = $replacementImageMime ?? $currentProduct['product_image_mime'];
        $imageDataToStore = $replacementImageData ?? $currentProduct['product_image_data'];
        $s = $conn->prepare('UPDATE products SET name=?, description=?, category_id=?, size_quantity=?, cultural_benefits=?, price_category=?, price_value=?, awards=?, memberships=?, exhibitions=?, product_image=?, product_image_mime=?, product_image_data=? WHERE product_id=? AND sme_id=?');
        $s->bind_param(
            'ssisssdssssssii',
            $product['name'],
            $product['description'],
            $product['category_id'],
            $product['size_quantity'],
            $product['cultural_benefits'],
            $product['price_category'],
            $product['price_value'],
            $product['awards'],
            $product['memberships'],
            $product['exhibitions'],
            $imageToStore,
            $imageMimeToStore,
            $imageDataToStore,
            $pid,
            $sid
        );
        if ($s->execute()) {
            if ($replacementImageData !== null) {
                delete_product_image_file((string)$currentProduct['product_image']);
            }
            flash(
                'success',
                $replacementImageData === null
                    ? 'Listing updated successfully.'
                    : 'Image uploaded successfully and listing updated.'
            );
        } else {
            flash('danger', 'Could not update the listing. Please try again.');
            $_SESSION['sme_open_modal_id'] = $pid;
        }
    } elseif (!$errors) {
        $imageToStore = (string)$currentProduct['product_image'];
        $s = $conn->prepare('UPDATE products SET name=?, description=?, category_id=?, size_quantity=?, cultural_benefits=?, price_category=?, price_value=?, awards=?, memberships=?, exhibitions=?, product_image=? WHERE product_id=? AND sme_id=?');
        $s->bind_param(
            'ssisssdssssii',
            $product['name'],
            $product['description'],
            $product['category_id'],
            $product['size_quantity'],
            $product['cultural_benefits'],
            $product['price_category'],
            $product['price_value'],
            $product['awards'],
            $product['memberships'],
            $product['exhibitions'],
            $imageToStore,
            $pid,
            $sid
        );
        if ($s->execute()) {
            flash('success', 'Listing updated successfully.');
        } else {
            flash('danger', 'Could not update the listing. Please try again.');
            $_SESSION['sme_open_modal_id'] = $pid;
        }
    } else {
        foreach ($errors as $e) flash('danger', $e);
        $_SESSION['sme_open_modal_id'] = $pid;
    }
    header('Location: ' . sme_dashboard_url('sme-product-catalog')); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_product'])) {
    $pid = (int)($_POST['product_id'] ?? 0);
    $productRecord = find_sme_product_record($conn, $sid, $pid);

    if (!$productRecord['exists']) {
        flash('danger', 'Listing not found.');
        header('Location: ' . sme_dashboard_url('sme-product-catalog')); exit;
    }

    $s = $conn->prepare('DELETE FROM products WHERE product_id=? AND sme_id=?');
    $s->bind_param('ii', $pid, $sid);
    if ($s->execute()) {
        delete_product_image_file((string)$productRecord['product_image']);
        flash('success', 'Listing deleted successfully.');
    } else {
        flash('danger', 'Could not delete the listing.');
    }
    header('Location: ' . sme_dashboard_url('sme-product-catalog')); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_account'])) {
    $productImages = sme_product_image_names($conn, $sid);
    $deleteUser = $conn->prepare("DELETE FROM users WHERE user_id=? AND role='sme'");
    $deleteUser->bind_param('i', $uid);
    if ($deleteUser->execute()) {
        foreach ($productImages as $productImage) {
            delete_product_image_file($productImage);
        }
        session_unset();
            session_destroy();
            session_start();
            flash('success', 'SME account deleted.');
            header('Location: index.php'); exit;
        }
    flash('danger', 'Could not delete SME account.');
    header('Location: ' . sme_dashboard_url('sme-danger-zone')); exit;
}

$productImageSelectSql = $databaseImageStorageEnabled
    ? "CASE WHEN p.product_image_data IS NOT NULL AND p.product_image_data <> '' THEN 1 ELSE 0 END AS product_image_has_data"
    : "0 AS product_image_has_data";
$products = $conn->query("
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
        c.category_name AS category
    FROM products p
    JOIN categories c ON c.category_id = p.category_id
    WHERE p.sme_id = $sid
    ORDER BY p.created_at DESC
")->fetch_all(MYSQLI_ASSOC);
$listingCount = count($products);
$categoryCount = count(array_unique(array_filter(array_map(static fn($product) => $product['category'] ?? '', $products))));
$premiumCount = 0;
$affordableCount = 0;
foreach ($products as $product) {
    if (($product['price_category'] ?? '') === 'Premium') {
        $premiumCount++;
    }
    if (($product['price_category'] ?? '') === 'Affordable') {
        $affordableCount++;
    }
}

require 'includes/header.php';
?>
<section class="dashboard-page-header">
  <div class="dashboard-page-shell">
    <nav aria-label="breadcrumb" class="dashboard-breadcrumbs mb-3">
      <ol class="breadcrumb">
        <li class="breadcrumb-item">
          <a href="index.php"><i class="bi bi-house-door"></i><span>Home</span></a>
        </li>
        <li class="breadcrumb-item active" aria-current="page">SME Dashboard</li>
      </ol>
    </nav>

    <div class="dashboard-page-top">
      <div class="dashboard-page-copy">
        <div class="dashboard-title-row">
          <span class="dashboard-title-icon" aria-hidden="true"><i class="bi bi-shop-window"></i></span>
          <div>
            <h1 class="dashboard-page-title"><?= htmlspecialchars($sme['company_name']) ?></h1>
            <p class="dashboard-page-subtitle">Manage your creative business profile, keep your listings current, and spotlight the work residents can discover through CultureConnect. Contact details and catalogue updates stay close at hand.</p>
          </div>
        </div>
      </div>
      <div class="dashboard-page-actions">
        <button
          type="button"
          class="btn btn-primary dashboard-collapse-toggle"
          data-bs-toggle="collapse"
          data-bs-target="#smeListingCollapse"
          aria-expanded="false"
          aria-controls="smeListingCollapse"
          id="smeListingToggle"
        >
          <span data-toggle-label>Add Listing</span>
          <i class="bi bi-chevron-down ms-2"></i>
        </button>
        <button
          type="button"
          class="btn btn-outline-primary dashboard-collapse-toggle"
          data-bs-toggle="collapse"
          data-bs-target="#smeProfileCollapse"
          aria-expanded="false"
          aria-controls="smeProfileCollapse"
          id="smeProfileToggle"
        >
          <span data-toggle-label>Edit Profile</span>
          <i class="bi bi-chevron-down ms-2"></i>
        </button>
      </div>
    </div>

    <div class="row g-3 mt-1">
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="dashboard-stat-card">
          <div class="dashboard-stat-label"><i class="bi bi-collection"></i> Live Listings</div>
          <div class="dashboard-stat-value"><?= $listingCount ?></div>
          <div class="dashboard-stat-note">Products and services currently on the platform.</div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="dashboard-stat-card">
          <div class="dashboard-stat-label"><i class="bi bi-grid"></i> Categories</div>
          <div class="dashboard-stat-value"><?= $categoryCount ?></div>
          <div class="dashboard-stat-note">Different cultural categories you cover.</div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="dashboard-stat-card">
          <div class="dashboard-stat-label"><i class="bi bi-stars"></i> Premium Listings</div>
          <div class="dashboard-stat-value"><?= $premiumCount ?></div>
          <div class="dashboard-stat-note">Offerings positioned at premium pricing.</div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="dashboard-stat-card">
          <div class="dashboard-stat-label"><i class="bi bi-wallet2"></i> Affordable Listings</div>
          <div class="dashboard-stat-value"><?= $affordableCount ?></div>
          <div class="dashboard-stat-note">Lower-cost options residents can access quickly.</div>
        </div>
      </div>
    </div>
  </div>
</section>

<section id="sme-profile-panel" class="my-4">
  <div class="collapse" id="smeProfileCollapse">
    <div class="card shadow-sm sme-panel-card">
      <div class="card-body p-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
          <div>
            <h5 class="mb-1">Edit SME Profile</h5>
            <p class="text-muted mb-0">Update your business contact details only when something changes.</p>
          </div>
          <span class="badge text-bg-light sme-panel-badge">Kept collapsed by default to reduce scroll fatigue</span>
        </div>
        <form method="post" class="row g-3">
          <input type="hidden" name="action" value="update_sme_profile">
          <div class="col-md-6">
            <label class="form-label">Company / Artist Name</label>
            <input name="company_name" value="<?= htmlspecialchars($sme['company_name']) ?>" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Account Email</label>
            <input value="<?= htmlspecialchars($email) ?>" class="form-control" disabled>
          </div>
          <div class="col-md-4">
            <label class="form-label">Contact Name</label>
            <input name="contact_name" value="<?= htmlspecialchars($sme['contact_name']) ?>" class="form-control">
          </div>
          <div class="col-md-4">
            <label class="form-label">Phone</label>
            <input name="contact_phone" value="<?= htmlspecialchars($sme['contact_phone']) ?>" class="form-control">
          </div>
          <div class="col-md-4">
            <label class="form-label">Portfolio URL</label>
            <input name="portfolio_url" type="url" value="<?= htmlspecialchars($sme['portfolio_url']) ?>" class="form-control" placeholder="https://...">
          </div>
          <div class="col-12 d-flex justify-content-end">
            <button class="btn btn-outline-primary">Save SME Profile</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</section>

<section id="sme-add-listing" class="mb-4">
  <div class="collapse" id="smeListingCollapse">
    <div class="card shadow-sm sme-panel-card">
      <div class="card-body p-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
          <div>
            <h5 class="mb-1">Add a New Product / Service</h5>
            <p class="text-muted mb-0">Expand your cultural portfolio while maintaining a clear overview of your active listings.</p>
          </div>
          <span class="badge text-bg-light sme-panel-badge">Each SME can register only one Health & Wellness listing</span>
        </div>
        <form method="post" enctype="multipart/form-data" class="row g-3">
          <input type="hidden" name="add_product" value="1">
          <input type="hidden" name="MAX_FILE_SIZE" value="<?= PRODUCT_IMAGE_MAX_BYTES ?>">
          <div class="col-md-6">
            <label class="form-label">Name</label>
            <input name="name" class="form-control" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Category</label>
            <select name="category_id" class="form-select" required>
              <option value="">Choose a council-managed category</option>
              <?php foreach ($categories as $category): ?>
                <option value="<?= (int)$category['category_id'] ?>"><?= htmlspecialchars($category['category_name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">
              <?= $categories ? 'Choose carefully. Duplicate Health & Wellness listings for the same SME are blocked.' : 'No categories are available yet. Ask the council to add categories first.' ?>
            </div>
          </div>
          <div class="col-md-3">
            <label class="form-label">Size / Quantity</label>
            <input name="size_quantity" class="form-control" placeholder="e.g. 10 seats">
          </div>
          <div class="col-12">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="2" required></textarea>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="product-image-upload">Product Image</label>
            <input
              type="file"
              name="product_image"
              id="product-image-upload"
              class="form-control"
              accept=".jpg,.jpeg,.png,.gif,image/jpeg,image/png,image/gif"
            >
            <div class="form-text">Upload one JPEG, PNG, or GIF image up to 2MB. If you skip this, a branded placeholder will be shown.</div>
          </div>
          <div class="col-12">
            <label class="form-label">Cultural Benefits</label>
            <textarea name="cultural_benefits" class="form-control" rows="2" required></textarea>
          </div>
          <div class="col-md-3">
            <label class="form-label">Price Category</label>
            <select name="price_category" class="form-select" required>
              <option>Affordable</option><option>Moderate</option><option>Premium</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Price (£)</label>
            <input name="price_value" type="number" min="0.01" step="0.01" class="form-control" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Awards (optional)</label>
            <input name="awards" class="form-control">
          </div>
          <div class="col-md-3">
            <label class="form-label">Memberships (optional)</label>
            <input name="memberships" class="form-control">
          </div>
          <div class="col-12">
            <label class="form-label">Exhibitions (optional)</label>
            <input name="exhibitions" class="form-control" placeholder="Past or upcoming exhibitions / showcases">
          </div>
          <div class="col-12 d-flex justify-content-end">
            <button class="btn btn-primary" <?= !$categories ? 'disabled' : '' ?>>Add Listing</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</section>

<section class="card shadow-sm sme-catalog-card mb-4" id="sme-product-catalog">
  <div class="card-body p-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
      <div>
        <h4 class="mb-1">Your Listings</h4>
        <p class="text-muted mb-0">Scan your current products and services quickly, then open the full record only when you need to edit it.</p>
      </div>
      <div class="small text-muted fw-semibold"><?= $listingCount ?> listing<?= $listingCount === 1 ? '' : 's' ?> currently live</div>
    </div>

    <?php if ($products): ?>
      <div class="row g-3">
        <?php foreach ($products as $p): ?>
          <?php
          $dashboardProductImageUrl = product_image_url($p);
          $dashboardProductImageAlt = product_image_has_custom_image($p)
              ? $p['name'] . ' product image'
              : $p['name'] . ' placeholder image';
          ?>
          <div class="col-lg-4 col-md-6">
            <div class="card sme-product-card h-100 border-0">
              <div class="sme-product-media">
                <img
                  src="<?= htmlspecialchars($dashboardProductImageUrl) ?>"
                  alt="<?= htmlspecialchars($dashboardProductImageAlt) ?>"
                  class="card-img-top sme-product-image"
                  loading="lazy"
                  decoding="async"
                >
              </div>
              <div class="card-body p-4 d-flex flex-column">
                <div class="d-flex flex-wrap gap-2 mb-3">
                  <span class="badge rounded-pill text-bg-light"><?= htmlspecialchars($p['category']) ?></span>
                  <span class="badge rounded-pill sme-price-badge"><?= htmlspecialchars($p['price_category']) ?></span>
                </div>
                <h5 class="mb-1"><?= htmlspecialchars($p['name']) ?></h5>
                <p class="sme-product-description mb-3"><?= htmlspecialchars($p['description']) ?></p>
                <div class="sme-product-meta mb-3">
                  <div>
                    <span class="sme-price-value">£<?= number_format((float)$p['price_value'], 2) ?></span>
                    <span class="small text-muted d-block">Resident-facing listed price</span>
                  </div>
                  <?php if (trim((string)$p['size_quantity']) !== ''): ?>
                    <span class="badge rounded-pill text-bg-secondary"><?= htmlspecialchars($p['size_quantity']) ?></span>
                  <?php endif; ?>
                </div>
                <div class="sme-product-footnote small text-muted mb-3">
                  <?= htmlspecialchars($p['awards'] || $p['memberships'] || $p['exhibitions'] ? 'Achievements and memberships are available in the full edit form.' : 'Open the edit form to add awards, memberships, or exhibitions.') ?>
                </div>
                <div class="mt-auto d-flex gap-2 flex-wrap">
                  <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#productEditModal<?= $p['product_id'] ?>">
                    Edit
                  </button>
                  <button class="btn btn-outline-danger btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#productDeleteModal<?= $p['product_id'] ?>">
                    Delete
                  </button>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="alert alert-info mb-0 sme-catalog-empty">No products or services added yet. Use the Add Listing button above to create your first offering.</div>
    <?php endif; ?>
  </div>
</section>

<?php foreach ($products as $p): ?>
  <?php
  $modalProductImageUrl = product_image_url($p);
  $modalProductImageAlt = product_image_has_custom_image($p)
      ? $p['name'] . ' current product image'
      : $p['name'] . ' placeholder image';
  ?>
  <div class="modal fade" id="productEditModal<?= $p['product_id'] ?>" tabindex="-1" aria-labelledby="productEditModalLabel<?= $p['product_id'] ?>" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <h5 class="modal-title" id="productEditModalLabel<?= $p['product_id'] ?>">Edit Listing</h5>
            <p class="small text-muted mb-0"><?= htmlspecialchars($p['name']) ?> · <?= htmlspecialchars($p['category']) ?></p>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <form method="post" enctype="multipart/form-data" class="row g-3 dashboard-modal-form" id="product-edit-<?= $p['product_id'] ?>">
            <input type="hidden" name="edit_product" value="1">
            <input type="hidden" name="product_id" value="<?= $p['product_id'] ?>">
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= PRODUCT_IMAGE_MAX_BYTES ?>">
            <div class="col-md-6">
              <label class="form-label">Name</label>
              <input name="name" value="<?= htmlspecialchars($p['name']) ?>" class="form-control" required>
            </div>
            <div class="col-md-3">
              <label class="form-label">Category</label>
              <select name="category_id" class="form-select" required>
                <?php foreach ($categories as $category): ?>
                  <option value="<?= (int)$category['category_id'] ?>" <?= (int)$category['category_id'] === (int)$p['category_id'] ? 'selected' : '' ?>><?= htmlspecialchars($category['category_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Size / Quantity</label>
              <input name="size_quantity" value="<?= htmlspecialchars($p['size_quantity']) ?>" class="form-control" placeholder="e.g. 10 seats">
            </div>
            <div class="col-12">
              <label class="form-label">Description</label>
              <textarea name="description" class="form-control" rows="3" required><?= htmlspecialchars($p['description']) ?></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label d-block">Current Product Image</label>
              <div class="sme-product-preview">
                <img
                  src="<?= htmlspecialchars($modalProductImageUrl) ?>"
                  alt="<?= htmlspecialchars($modalProductImageAlt) ?>"
                  class="img-fluid sme-product-preview-image"
                >
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="product-image-edit-<?= $p['product_id'] ?>">Replace Product Image</label>
              <input
                type="file"
                name="product_image"
                id="product-image-edit-<?= $p['product_id'] ?>"
                class="form-control"
                accept=".jpg,.jpeg,.png,.gif,image/jpeg,image/png,image/gif"
              >
              <div class="form-text">
                <?= product_image_has_custom_image($p) ? 'A custom product image is already attached.' : 'Currently using the branded placeholder.' ?>
                Leave this blank to keep the current image.
              </div>
            </div>
            <div class="col-12">
              <label class="form-label">Cultural Benefits</label>
              <textarea name="cultural_benefits" class="form-control" rows="3" required><?= htmlspecialchars($p['cultural_benefits']) ?></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label">Price Category</label>
              <select name="price_category" class="form-select" required>
                <?php foreach (['Affordable', 'Moderate', 'Premium'] as $pc): ?>
                  <option value="<?= htmlspecialchars($pc) ?>" <?= $pc === $p['price_category'] ? 'selected' : '' ?>><?= htmlspecialchars($pc) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Price (£)</label>
              <input name="price_value" type="number" min="0.01" step="0.01" value="<?= htmlspecialchars((string)$p['price_value']) ?>" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Awards</label>
              <input name="awards" value="<?= htmlspecialchars($p['awards']) ?>" class="form-control" placeholder="Awards">
            </div>
            <div class="col-md-4">
              <label class="form-label">Memberships</label>
              <input name="memberships" value="<?= htmlspecialchars($p['memberships']) ?>" class="form-control" placeholder="Memberships">
            </div>
            <div class="col-md-4">
              <label class="form-label">Exhibitions</label>
              <input name="exhibitions" value="<?= htmlspecialchars($p['exhibitions']) ?>" class="form-control" placeholder="Exhibitions">
            </div>
          </form>
        </div>
        <div class="modal-footer justify-content-between">
          <button class="btn btn-outline-danger" type="button" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#productDeleteModal<?= $p['product_id'] ?>">
            Delete Listing
          </button>
          <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary" form="product-edit-<?= $p['product_id'] ?>">Save Changes</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="productDeleteModal<?= $p['product_id'] ?>" tabindex="-1" aria-labelledby="productDeleteModalLabel<?= $p['product_id'] ?>" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <h5 class="modal-title" id="productDeleteModalLabel<?= $p['product_id'] ?>">Delete Listing?</h5>
            <p class="small text-muted mb-0"><?= htmlspecialchars($p['name']) ?> will be removed from the platform.</p>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          Residents will no longer be able to browse or vote on this offering after deletion.
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <form method="post" class="mb-0">
            <input type="hidden" name="delete_product" value="1">
            <input type="hidden" name="product_id" value="<?= $p['product_id'] ?>">
            <button class="btn btn-danger">Yes, Delete Listing</button>
          </form>
        </div>
      </div>
    </div>
  </div>
<?php endforeach; ?>

<div class="card shadow-sm mt-4 sme-danger-card" id="sme-danger-zone"><div class="card-body p-4">
  <h4 class="text-danger-emphasis mb-2">Delete SME Account</h4>
  <p class="text-muted mb-3">This will remove your SME profile and every product or service you have listed.</p>
  <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#smeDeleteAccountModal">
    Delete Account
  </button>
</div></div>

<div class="modal fade" id="smeDeleteAccountModal" tabindex="-1" aria-labelledby="smeDeleteAccountModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title" id="smeDeleteAccountModalLabel">Delete SME Account?</h5>
          <p class="small text-muted mb-0"><?= htmlspecialchars($sme['company_name']) ?> and all linked listings will be permanently removed.</p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        This action cannot be undone. Residents will also lose access to any listings you currently have on the platform.
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <form method="post" class="mb-0">
          <input type="hidden" name="delete_account" value="1">
          <button class="btn btn-danger">Yes, Delete My SME Account</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  function registerCollapseToggle(buttonId, collapseId, closedLabel, openLabel) {
    var toggleButton = document.getElementById(buttonId);
    var collapseElement = document.getElementById(collapseId);
    var labelTarget = toggleButton ? toggleButton.querySelector('[data-toggle-label]') : null;

    if (!toggleButton || !collapseElement || !labelTarget) {
      return;
    }

    var syncState = function (isOpen) {
      toggleButton.classList.toggle('is-open', isOpen);
      toggleButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      labelTarget.textContent = isOpen ? openLabel : closedLabel;
    };

    collapseElement.addEventListener('shown.bs.collapse', function () {
      syncState(true);
      collapseElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    collapseElement.addEventListener('hidden.bs.collapse', function () {
      syncState(false);
    });

    syncState(collapseElement.classList.contains('show'));
  }

  registerCollapseToggle('smeProfileToggle', 'smeProfileCollapse', 'Edit Profile', 'Hide Profile Form');
  registerCollapseToggle('smeListingToggle', 'smeListingCollapse', 'Add Listing', 'Hide Add Listing Form');

  if (window.location.hash && window.bootstrap && window.bootstrap.Collapse) {
    if (window.location.hash === '#sme-profile-panel') {
      window.bootstrap.Collapse.getOrCreateInstance(document.getElementById('smeProfileCollapse'), { toggle: false }).show();
    }
    if (window.location.hash === '#sme-add-listing') {
      window.bootstrap.Collapse.getOrCreateInstance(document.getElementById('smeListingCollapse'), { toggle: false }).show();
    }
  }

  <?php if ($openProductModalId > 0): ?>
    var openProductModal = document.getElementById('productEditModal<?= $openProductModalId ?>');
    if (openProductModal && window.bootstrap && window.bootstrap.Modal) {
      window.bootstrap.Modal.getOrCreateInstance(openProductModal).show();
    }
  <?php endif; ?>
});
</script>
<?php require 'includes/footer.php'; ?>
