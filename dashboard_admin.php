<?php
require 'includes/db.php';
require 'includes/auth.php';
require_role('admin');

$ageOptions = ['Under 18', '18-25', '26-40', '41-60', '60+'];
$genderOptions = ['Male', 'Female', 'Non-binary', 'Prefer not to say'];
$perPage = 10;

$currentVotesSql = "
    SELECT v.resident_id, v.product_id, v.vote_value
    FROM votes v
    JOIN (
        SELECT resident_id, product_id, MAX(vote_id) AS vote_id
        FROM votes
        GROUP BY resident_id, product_id
    ) latest ON latest.vote_id = v.vote_id
";

function admin_int_page($value): int
{
    $page = (int)$value;
    return $page > 0 ? $page : 1;
}

function admin_total_pages(int $totalRows, int $perPage): int
{
    return max(1, (int)ceil($totalRows / $perPage));
}

function admin_pagination_window(int $currentPage, int $totalPages): array
{
    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);
    return range($start, $end);
}

function admin_query_string(array $overrides = []): string
{
    $params = $_GET;
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
            continue;
        }
        $params[$key] = $value;
    }
    return http_build_query($params);
}

function admin_page_url(array $overrides = [], string $anchor = ''): string
{
    $query = admin_query_string($overrides);
    $hash = $anchor !== '' ? '#' . rawurlencode($anchor) : '';
    return 'dashboard_admin.php' . ($query !== '' ? '?' . $query : '') . $hash;
}

function admin_hidden_query_inputs(array $exclude = []): string
{
    $html = '';
    foreach ($_GET as $key => $value) {
        if (in_array($key, $exclude, true) || is_array($value)) {
            continue;
        }
        $html .= '<input type="hidden" name="' . htmlspecialchars((string)$key, ENT_QUOTES) . '" value="'
            . htmlspecialchars((string)$value, ENT_QUOTES) . '">' . PHP_EOL;
    }
    return $html;
}

function admin_bind_params(mysqli_stmt $statement, string $types, array &$params): void
{
    $references = [];
    foreach ($params as $key => $value) {
        $references[$key] = &$params[$key];
    }
    array_unshift($references, $types);
    call_user_func_array([$statement, 'bind_param'], $references);
}

function admin_score_badge_class(int $score): string
{
    if ($score >= 5) {
        return 'badge-brand-strong';
    }
    if ($score > 0) {
        return 'badge-brand';
    }
    if ($score < 0) {
        return 'badge-brand-danger';
    }
    return 'badge-brand-muted';
}

function admin_vote_badge_class(int $count, string $type): string
{
    if ($count <= 0) {
        return 'badge-brand-muted';
    }
    return $type === 'no' ? 'badge-brand-danger' : 'badge-brand-success';
}

function admin_score_value(array $row): int
{
    return (int)($row['score'] ?? ((int)($row['yes_votes'] ?? 0) - (int)($row['no_votes'] ?? 0)));
}

function admin_category_exists(mysqli $conn, string $name, ?int $excludeId = null): bool
{
    if ($excludeId !== null) {
        $statement = $conn->prepare('SELECT category_id FROM categories WHERE category_name = ? AND category_id <> ?');
        $statement->bind_param('si', $name, $excludeId);
    } else {
        $statement = $conn->prepare('SELECT category_id FROM categories WHERE category_name = ?');
        $statement->bind_param('s', $name);
    }
    $statement->execute();
    return $statement->get_result()->num_rows > 0;
}

$areasPage = admin_int_page($_GET['areas_page'] ?? 1);
$leaderboardPage = admin_int_page($_GET['leaderboard_page'] ?? 1);
$residentsPage = admin_int_page($_GET['residents_page'] ?? 1);
$smesPage = admin_int_page($_GET['smes_page'] ?? 1);
$areasSearch = trim($_GET['areas_query'] ?? '');
$leaderboardSearch = trim($_GET['leaderboard_query'] ?? '');
$residentsSearch = trim($_GET['residents_query'] ?? '');
$smesSearch = trim($_GET['smes_query'] ?? '');
$peoplePerPage = 5;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_area') {
        $name = trim($_POST['area_name'] ?? '');
        $council = trim($_POST['council'] ?? '');
        if ($name === '') {
            flash('danger', 'Area name required.');
        } else {
            $statement = $conn->prepare('INSERT INTO areas (area_name, council) VALUES (?, ?)');
            $statement->bind_param('ss', $name, $council);
            if ($statement->execute()) {
                flash('success', 'Area added successfully.');
            } else {
                flash('danger', 'Could not add area. Try a different name if that area already exists.');
            }
        }
        header('Location: ' . admin_page_url([], 'manage-areas'));
        exit;
    }

    if ($action === 'update_area') {
        $areaId = (int)($_POST['area_id'] ?? 0);
        $name = trim($_POST['area_name'] ?? '');
        $council = trim($_POST['council'] ?? '');
        if (!$areaId || $name === '') {
            flash('danger', 'A valid area name is required.');
        } else {
            $statement = $conn->prepare('UPDATE areas SET area_name = ?, council = ? WHERE area_id = ?');
            $statement->bind_param('ssi', $name, $council, $areaId);
            if ($statement->execute()) {
                flash('success', 'Area updated successfully.');
            } else {
                flash('danger', 'Could not update area. Try a different name if that area already exists.');
            }
        }
        header('Location: ' . admin_page_url([], 'manage-areas'));
        exit;
    }

    if ($action === 'delete_area') {
        $areaId = (int)($_POST['area_id'] ?? 0);
        if (!$areaId) {
            flash('danger', 'Invalid area selected.');
            header('Location: ' . admin_page_url([], 'manage-areas'));
            exit;
        }

        $usage = $conn->prepare('SELECT COUNT(*) AS resident_count FROM residents WHERE area_id = ?');
        $usage->bind_param('i', $areaId);
        $usage->execute();
        $residentCount = (int)($usage->get_result()->fetch_assoc()['resident_count'] ?? 0);

        if ($residentCount > 0) {
            $noun = $residentCount === 1 ? 'resident is' : 'residents are';
            flash('danger', "This area cannot be deleted because {$residentCount} {$noun} still assigned to it. Reassign or remove them first.");
        } else {
            $statement = $conn->prepare('DELETE FROM areas WHERE area_id = ?');
            $statement->bind_param('i', $areaId);
            if ($statement->execute()) {
                flash('success', 'Area deleted successfully.');
            } else {
                flash('danger', 'Could not delete area.');
            }
        }
        header('Location: ' . admin_page_url([], 'manage-areas'));
        exit;
    }

    if ($action === 'add_category') {
        $name = trim($_POST['category_name'] ?? '');
        if ($name === '') {
            flash('danger', 'Category name required.');
        } elseif (admin_category_exists($conn, $name)) {
            flash('danger', 'That category already exists. Choose a different name.');
        } else {
            $statement = $conn->prepare('INSERT INTO categories (category_name) VALUES (?)');
            $statement->bind_param('s', $name);
            if ($statement->execute()) {
                flash('success', 'Category added successfully.');
            } else {
                flash('danger', 'Could not add category.');
            }
        }
        header('Location: ' . admin_page_url([], 'manage-categories'));
        exit;
    }

    if ($action === 'update_category') {
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $name = trim($_POST['category_name'] ?? '');
        if (!$categoryId || $name === '') {
            flash('danger', 'A valid category name is required.');
        } elseif (admin_category_exists($conn, $name, $categoryId)) {
            flash('danger', 'That category name is already in use.');
        } else {
            $statement = $conn->prepare('UPDATE categories SET category_name = ? WHERE category_id = ?');
            $statement->bind_param('si', $name, $categoryId);
            if ($statement->execute()) {
                flash('success', 'Category updated successfully.');
            } else {
                flash('danger', 'Could not update category.');
            }
        }
        header('Location: ' . admin_page_url([], 'manage-categories'));
        exit;
    }

    if ($action === 'delete_category') {
        $categoryId = (int)($_POST['category_id'] ?? 0);
        if (!$categoryId) {
            flash('danger', 'Invalid category selected.');
            header('Location: ' . admin_page_url([], 'manage-categories'));
            exit;
        }

        $usage = $conn->prepare('SELECT COUNT(*) AS product_count FROM products WHERE category_id = ?');
        $usage->bind_param('i', $categoryId);
        $usage->execute();
        $productCount = (int)($usage->get_result()->fetch_assoc()['product_count'] ?? 0);

        if ($productCount > 0) {
            $noun = $productCount === 1 ? 'product or service is' : 'products or services are';
            flash('danger', "This category cannot be deleted because {$productCount} {$noun} still assigned to it.");
        } else {
            $statement = $conn->prepare('DELETE FROM categories WHERE category_id = ?');
            $statement->bind_param('i', $categoryId);
            if ($statement->execute()) {
                flash('success', 'Category deleted successfully.');
            } else {
                flash('danger', 'Could not delete category.');
            }
        }
        header('Location: ' . admin_page_url([], 'manage-categories'));
        exit;
    }

    if ($action === 'update_resident') {
        $residentId = (int)($_POST['resident_id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        $email = trim($_POST['email'] ?? '');
        $titleId = (int)($_POST['title_id'] ?? 0);
        $areaId = (int)($_POST['area_id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $ageGroup = $_POST['age_group'] ?? '';
        $gender = $_POST['gender'] ?? '';
        $selectedInterestIds = array_values(array_unique(array_filter(array_map('intval', $_POST['interests'] ?? []))));

        $errors = [];
        if (!$residentId || !$userId) {
            $errors[] = 'Invalid resident selected.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Valid email required.';
        }
        if ($titleId <= 0) {
            $errors[] = 'Please choose a title.';
        }
        if ($areaId <= 0) {
            $errors[] = 'Please choose an area.';
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

        if (!$errors) {
            $duplicate = $conn->prepare('SELECT user_id FROM users WHERE email = ? AND user_id <> ?');
            $duplicate->bind_param('si', $email, $userId);
            $duplicate->execute();
            if ($duplicate->get_result()->num_rows > 0) {
                $errors[] = 'Email already belongs to another account.';
            }
        }

        if (!$errors) {
            $conn->begin_transaction();
            try {
                $updateUser = $conn->prepare("UPDATE users SET email = ? WHERE user_id = ? AND role = 'resident'");
                $updateUser->bind_param('si', $email, $userId);
                $updateUser->execute();

                $updateResident = $conn->prepare('UPDATE residents SET title_id = ?, area_id = ?, full_name = ?, location = ?, age_group = ?, gender = ? WHERE resident_id = ? AND user_id = ?');
                $updateResident->bind_param('iissssii', $titleId, $areaId, $fullName, $location, $ageGroup, $gender, $residentId, $userId);
                $updateResident->execute();

                $deleteInterests = $conn->prepare('DELETE FROM resident_interests WHERE resident_id = ?');
                $deleteInterests->bind_param('i', $residentId);
                $deleteInterests->execute();

                if ($selectedInterestIds) {
                    $insertInterest = $conn->prepare('INSERT INTO resident_interests (resident_id, interest_id) VALUES (?, ?)');
                    foreach ($selectedInterestIds as $interestId) {
                        $insertInterest->bind_param('ii', $residentId, $interestId);
                        $insertInterest->execute();
                    }
                }

                $conn->commit();
                flash('success', 'Resident updated successfully.');
            } catch (Throwable $exception) {
                $conn->rollback();
                flash('danger', 'Could not update resident.');
            }
        } else {
            foreach ($errors as $error) {
                flash('danger', $error);
            }
        }
        header('Location: ' . admin_page_url([], 'residents-management'));
        exit;
    }

    if ($action === 'delete_resident') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $deleteUser = $conn->prepare("DELETE FROM users WHERE user_id = ? AND role = 'resident'");
        $deleteUser->bind_param('i', $userId);
        if ($deleteUser->execute()) {
            flash('success', 'Resident deleted successfully.');
        } else {
            flash('danger', 'Could not delete resident.');
        }
        header('Location: ' . admin_page_url([], 'residents-management'));
        exit;
    }

    if ($action === 'update_sme') {
        $smeId = (int)($_POST['sme_id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        $email = trim($_POST['email'] ?? '');
        $companyName = trim($_POST['company_name'] ?? '');
        $contactName = trim($_POST['contact_name'] ?? '');
        $contactPhone = trim($_POST['contact_phone'] ?? '');
        $portfolioUrl = trim($_POST['portfolio_url'] ?? '');

        $errors = [];
        if (!$smeId || !$userId) {
            $errors[] = 'Invalid SME selected.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Valid email required.';
        }
        if ($companyName === '') {
            $errors[] = 'Company or artist name is required.';
        }
        if ($portfolioUrl !== '' && !filter_var($portfolioUrl, FILTER_VALIDATE_URL)) {
            $errors[] = 'Portfolio URL must be a valid URL.';
        }

        if (!$errors) {
            $duplicate = $conn->prepare('SELECT user_id FROM users WHERE email = ? AND user_id <> ?');
            $duplicate->bind_param('si', $email, $userId);
            $duplicate->execute();
            if ($duplicate->get_result()->num_rows > 0) {
                $errors[] = 'Email already belongs to another account.';
            }
        }

        if (!$errors) {
            $conn->begin_transaction();
            try {
                $updateUser = $conn->prepare("UPDATE users SET email = ? WHERE user_id = ? AND role = 'sme'");
                $updateUser->bind_param('si', $email, $userId);
                $updateUser->execute();

                $updateSme = $conn->prepare('UPDATE smes SET company_name = ?, contact_name = ?, contact_phone = ?, portfolio_url = ? WHERE sme_id = ? AND user_id = ?');
                $updateSme->bind_param('ssssii', $companyName, $contactName, $contactPhone, $portfolioUrl, $smeId, $userId);
                $updateSme->execute();

                $conn->commit();
                flash('success', 'SME updated successfully.');
            } catch (Throwable $exception) {
                $conn->rollback();
                flash('danger', 'Could not update SME.');
            }
        } else {
            foreach ($errors as $error) {
                flash('danger', $error);
            }
        }
        header('Location: ' . admin_page_url([], 'smes-management'));
        exit;
    }

    if ($action === 'delete_sme') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $deleteUser = $conn->prepare("DELETE FROM users WHERE user_id = ? AND role = 'sme'");
        $deleteUser->bind_param('i', $userId);
        if ($deleteUser->execute()) {
            flash('success', 'SME deleted successfully.');
        } else {
            flash('danger', 'Could not delete SME.');
        }
        header('Location: ' . admin_page_url([], 'smes-management'));
        exit;
    }
}

$allAreasForSelect = $conn->query('SELECT area_id, area_name FROM areas ORDER BY area_name')->fetch_all(MYSQLI_ASSOC);
$categoriesForAdmin = $conn->query("
    SELECT c.category_id, c.category_name, COUNT(p.product_id) AS product_count
    FROM categories c
    LEFT JOIN products p ON p.category_id = c.category_id
    GROUP BY c.category_id, c.category_name, c.created_at
    ORDER BY c.category_name ASC
")->fetch_all(MYSQLI_ASSOC);

$areasLike = '%' . $areasSearch . '%';
$areaCountStatement = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM areas
    WHERE area_name LIKE ? OR council LIKE ?
");
$areaCountStatement->bind_param('ss', $areasLike, $areasLike);
$areaCountStatement->execute();
$totalAreaMatches = (int)($areaCountStatement->get_result()->fetch_assoc()['total'] ?? 0);
$areaTotalPages = admin_total_pages($totalAreaMatches, $perPage);
$areasPage = min($areasPage, $areaTotalPages);
$areasOffset = ($areasPage - 1) * $perPage;

$areaStatement = $conn->prepare("
    SELECT a.area_id, a.area_name, a.council, a.created_at, COUNT(r.resident_id) AS resident_count
    FROM areas a
    LEFT JOIN residents r ON r.area_id = a.area_id
    WHERE a.area_name LIKE ? OR a.council LIKE ?
    GROUP BY a.area_id, a.area_name, a.council, a.created_at
    ORDER BY a.area_name ASC
    LIMIT ? OFFSET ?
");
$areaStatement->bind_param('ssii', $areasLike, $areasLike, $perPage, $areasOffset);
$areaStatement->execute();
$areas = $areaStatement->get_result()->fetch_all(MYSQLI_ASSOC);

$titles = $conn->query('SELECT * FROM titles ORDER BY title_name')->fetch_all(MYSQLI_ASSOC);
$interests = $conn->query('SELECT * FROM interests ORDER BY interest_name')->fetch_all(MYSQLI_ASSOC);

$residentsLike = '%' . $residentsSearch . '%';
$residentCountStatement = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM residents r
    JOIN users u ON u.user_id = r.user_id
    WHERE r.full_name LIKE ? OR u.email LIKE ?
");
$residentCountStatement->bind_param('ss', $residentsLike, $residentsLike);
$residentCountStatement->execute();
$totalResidentMatches = (int)($residentCountStatement->get_result()->fetch_assoc()['total'] ?? 0);
$residentTotalPages = admin_total_pages($totalResidentMatches, $peoplePerPage);
$residentsPage = min($residentsPage, $residentTotalPages);
$residentsOffset = ($residentsPage - 1) * $peoplePerPage;

$residentStatement = $conn->prepare("
    SELECT r.resident_id, r.user_id, r.title_id, r.area_id, r.full_name, r.location, r.age_group, r.gender,
           u.email, a.area_name, t.title_name, COUNT(ri.interest_id) AS interest_count
    FROM residents r
    JOIN users u ON u.user_id = r.user_id
    JOIN areas a ON a.area_id = r.area_id
    JOIN titles t ON t.title_id = r.title_id
    LEFT JOIN resident_interests ri ON ri.resident_id = r.resident_id
    WHERE r.full_name LIKE ? OR u.email LIKE ?
    GROUP BY r.resident_id, r.user_id, r.title_id, r.area_id, r.full_name, r.location, r.age_group, r.gender,
             u.email, a.area_name, t.title_name, r.created_at
    ORDER BY r.created_at DESC, r.resident_id DESC
    LIMIT ? OFFSET ?
");
$residentStatement->bind_param('ssii', $residentsLike, $residentsLike, $peoplePerPage, $residentsOffset);
$residentStatement->execute();
$residents = $residentStatement->get_result()->fetch_all(MYSQLI_ASSOC);

$residentIds = array_map(static fn(array $resident): int => (int)$resident['resident_id'], $residents);
$residentInterestRows = [];
if ($residentIds) {
    $residentInterestSql = 'SELECT resident_id, interest_id FROM resident_interests WHERE resident_id IN ('
        . implode(',', array_fill(0, count($residentIds), '?')) . ') ORDER BY resident_id, interest_id';
    $residentInterestStatement = $conn->prepare($residentInterestSql);
    $residentInterestParams = $residentIds;
    admin_bind_params($residentInterestStatement, str_repeat('i', count($residentInterestParams)), $residentInterestParams);
    $residentInterestStatement->execute();
    $residentInterestRows = $residentInterestStatement->get_result()->fetch_all(MYSQLI_ASSOC);
}
$residentInterestMap = [];
foreach ($residentInterestRows as $row) {
    $residentInterestMap[(int)$row['resident_id']][] = (int)$row['interest_id'];
}

$smesLike = '%' . $smesSearch . '%';
$smeCountStatement = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM smes s
    JOIN users u ON u.user_id = s.user_id
    WHERE s.company_name LIKE ? OR COALESCE(s.contact_name, '') LIKE ? OR u.email LIKE ?
");
$smeCountStatement->bind_param('sss', $smesLike, $smesLike, $smesLike);
$smeCountStatement->execute();
$totalSmeMatches = (int)($smeCountStatement->get_result()->fetch_assoc()['total'] ?? 0);
$smeTotalPages = admin_total_pages($totalSmeMatches, $peoplePerPage);
$smesPage = min($smesPage, $smeTotalPages);
$smesOffset = ($smesPage - 1) * $peoplePerPage;

$smeStatement = $conn->prepare("
    SELECT s.sme_id, s.user_id, s.company_name, s.contact_name, s.contact_phone, s.portfolio_url,
           u.email, COUNT(p.product_id) AS product_count
    FROM smes s
    JOIN users u ON u.user_id = s.user_id
    LEFT JOIN products p ON p.sme_id = s.sme_id
    WHERE s.company_name LIKE ? OR COALESCE(s.contact_name, '') LIKE ? OR u.email LIKE ?
    GROUP BY s.sme_id, s.user_id, s.company_name, s.contact_name, s.contact_phone, s.portfolio_url, u.email, s.created_at
    ORDER BY s.created_at DESC, s.sme_id DESC
    LIMIT ? OFFSET ?
");
$smeStatement->bind_param('sssii', $smesLike, $smesLike, $smesLike, $peoplePerPage, $smesOffset);
$smeStatement->execute();
$smes = $smeStatement->get_result()->fetch_all(MYSQLI_ASSOC);

$leaderboardLike = '%' . $leaderboardSearch . '%';
$leaderboardCountStatement = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM products p
    JOIN categories c ON c.category_id = p.category_id
    WHERE p.name LIKE ? OR c.category_name LIKE ?
");
$leaderboardCountStatement->bind_param('ss', $leaderboardLike, $leaderboardLike);
$leaderboardCountStatement->execute();
$totalLeaderboardMatches = (int)($leaderboardCountStatement->get_result()->fetch_assoc()['total'] ?? 0);
$leaderboardTotalPages = admin_total_pages($totalLeaderboardMatches, $perPage);
$leaderboardPage = min($leaderboardPage, $leaderboardTotalPages);
$leaderboardOffset = ($leaderboardPage - 1) * $perPage;

$rankingStatement = $conn->prepare("
    SELECT p.product_id, p.name, p.price_value, c.category_name AS category,
           COALESCE(SUM(CASE WHEN v.vote_value = 'Yes' THEN 1 ELSE 0 END), 0) AS yes_votes,
           COALESCE(SUM(CASE WHEN v.vote_value = 'No' THEN 1 ELSE 0 END), 0) AS no_votes,
           COALESCE(SUM(CASE WHEN v.vote_value = 'Yes' THEN 1 ELSE 0 END), 0)
             - COALESCE(SUM(CASE WHEN v.vote_value = 'No' THEN 1 ELSE 0 END), 0) AS score
    FROM products p
    JOIN categories c ON c.category_id = p.category_id
    LEFT JOIN ($currentVotesSql) v ON v.product_id = p.product_id
    WHERE p.name LIKE ? OR c.category_name LIKE ?
    GROUP BY p.product_id, p.name, p.price_value, c.category_name
    ORDER BY score DESC, yes_votes DESC, p.name ASC
    LIMIT ? OFFSET ?
");
$rankingStatement->bind_param('ssii', $leaderboardLike, $leaderboardLike, $perPage, $leaderboardOffset);
$rankingStatement->execute();
$ranking = $rankingStatement->get_result()->fetch_all(MYSQLI_ASSOC);

$categoryDemand = $conn->query("
    SELECT a.area_name, c.category_name AS category,
           SUM(CASE WHEN v.vote_value = 'Yes' THEN 1 ELSE 0 END) AS yes_votes,
           SUM(CASE WHEN v.vote_value = 'No' THEN 1 ELSE 0 END) AS no_votes,
           SUM(CASE WHEN v.vote_value = 'Yes' THEN 1 ELSE 0 END)
             - SUM(CASE WHEN v.vote_value = 'No' THEN 1 ELSE 0 END) AS score
    FROM ($currentVotesSql) v
    JOIN residents r ON r.resident_id = v.resident_id
    JOIN areas a ON a.area_id = r.area_id
    JOIN products p ON p.product_id = v.product_id
    JOIN categories c ON c.category_id = p.category_id
    GROUP BY a.area_id, a.area_name, c.category_name
    ORDER BY a.area_name ASC, score DESC, yes_votes DESC, c.category_name ASC
")->fetch_all(MYSQLI_ASSOC);

$productDemand = $conn->query("
    SELECT a.area_name, p.name AS product_name, c.category_name AS category,
           SUM(CASE WHEN v.vote_value = 'Yes' THEN 1 ELSE 0 END) AS yes_votes,
           SUM(CASE WHEN v.vote_value = 'No' THEN 1 ELSE 0 END) AS no_votes,
           SUM(CASE WHEN v.vote_value = 'Yes' THEN 1 ELSE 0 END)
             - SUM(CASE WHEN v.vote_value = 'No' THEN 1 ELSE 0 END) AS score
    FROM ($currentVotesSql) v
    JOIN residents r ON r.resident_id = v.resident_id
    JOIN areas a ON a.area_id = r.area_id
    JOIN products p ON p.product_id = v.product_id
    JOIN categories c ON c.category_id = p.category_id
    GROUP BY a.area_id, a.area_name, p.product_id, p.name, c.category_name
    ORDER BY a.area_name ASC, score DESC, yes_votes DESC, p.name ASC
")->fetch_all(MYSQLI_ASSOC);

$topCategoryByArea = [];
foreach ($categoryDemand as $row) {
    if (!isset($topCategoryByArea[$row['area_name']])) {
        $topCategoryByArea[$row['area_name']] = $row;
    }
}

$topProductByArea = [];
foreach ($productDemand as $row) {
    if (!isset($topProductByArea[$row['area_name']])) {
        $topProductByArea[$row['area_name']] = $row;
    }
}

$interestsByArea = $conn->query("
    SELECT a.area_name, i.interest_name, COUNT(*) AS c
    FROM resident_interests ri
    JOIN residents r ON r.resident_id = ri.resident_id
    JOIN areas a ON a.area_id = r.area_id
    JOIN interests i ON i.interest_id = ri.interest_id
    GROUP BY a.area_id, a.area_name, i.interest_id, i.interest_name
    ORDER BY a.area_name ASC, c DESC, i.interest_name ASC
")->fetch_all(MYSQLI_ASSOC);

$areaStart = $totalAreaMatches > 0 ? $areasOffset + 1 : 0;
$areaEnd = min($areasOffset + $perPage, $totalAreaMatches);
$leaderboardStart = $totalLeaderboardMatches > 0 ? $leaderboardOffset + 1 : 0;
$leaderboardEnd = min($leaderboardOffset + $perPage, $totalLeaderboardMatches);
$residentStart = $totalResidentMatches > 0 ? $residentsOffset + 1 : 0;
$residentEnd = min($residentsOffset + $peoplePerPage, $totalResidentMatches);
$smeStart = $totalSmeMatches > 0 ? $smesOffset + 1 : 0;
$smeEnd = min($smesOffset + $peoplePerPage, $totalSmeMatches);

$totalAreasOverall = (int)($conn->query('SELECT COUNT(*) AS total FROM areas')->fetch_assoc()['total'] ?? 0);
$totalResidentsOverall = (int)($conn->query('SELECT COUNT(*) AS total FROM residents')->fetch_assoc()['total'] ?? 0);
$totalSmesOverall = (int)($conn->query('SELECT COUNT(*) AS total FROM smes')->fetch_assoc()['total'] ?? 0);
$totalProductsOverall = (int)($conn->query('SELECT COUNT(*) AS total FROM products')->fetch_assoc()['total'] ?? 0);

require 'includes/header.php';
?>
<section class="dashboard-page-header">
  <div class="dashboard-page-shell">
    <nav aria-label="breadcrumb" class="dashboard-breadcrumbs mb-3">
      <ol class="breadcrumb">
        <li class="breadcrumb-item">
          <a href="index.php"><i class="bi bi-house-door"></i><span>Home</span></a>
        </li>
        <li class="breadcrumb-item active" aria-current="page">Admin Dashboard</li>
      </ol>
    </nav>

    <div class="dashboard-page-top">
      <div class="dashboard-page-copy">
        <div class="dashboard-title-row">
          <span class="dashboard-title-icon" aria-hidden="true"><i class="bi bi-speedometer2"></i></span>
          <div>
            <h1 class="dashboard-page-title">Admin Dashboard</h1>
            <p class="dashboard-page-subtitle">Manage jurisdictional areas, monitor community voting trends, and leverage data-driven insights to optimize resource allocation and support local creative SMEs.</p>
          </div>
        </div>
      </div>
      <div class="dashboard-page-actions">
        <a href="#manage-areas" class="btn btn-culture">Manage Areas</a>
        <a href="#leaderboard-tab" class="btn btn-outline-culture">Open Reports</a>
      </div>
    </div>

    <div class="row g-3 mt-1">
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="dashboard-stat-card">
          <div class="dashboard-stat-label"><i class="bi bi-geo-alt"></i> Total Areas</div>
          <div class="dashboard-stat-value"><?= $totalAreasOverall ?></div>
          <div class="dashboard-stat-note">Council-managed regions on the platform.</div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="dashboard-stat-card">
          <div class="dashboard-stat-label"><i class="bi bi-people"></i> Active Residents</div>
          <div class="dashboard-stat-value"><?= $totalResidentsOverall ?></div>
          <div class="dashboard-stat-note">Registered residents contributing demand signals.</div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="dashboard-stat-card">
          <div class="dashboard-stat-label"><i class="bi bi-shop-window"></i> Creative SMEs</div>
          <div class="dashboard-stat-value"><?= $totalSmesOverall ?></div>
          <div class="dashboard-stat-note">Businesses and artists currently listed.</div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="dashboard-stat-card">
          <div class="dashboard-stat-label"><i class="bi bi-collection"></i> Live Offerings</div>
          <div class="dashboard-stat-value"><?= $totalProductsOverall ?></div>
          <div class="dashboard-stat-note">Products and services available to residents.</div>
        </div>
      </div>
    </div>
  </div>
</section>

<div class="row g-4">
  <div class="col-12" id="manage-areas">
    <div class="card shadow-sm h-100">
      <div class="card-header bg-white border-0 pb-0">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
          <div>
            <h5 class="mb-1">Manage Areas</h5>
            <p class="text-muted small mb-0">Add new council areas, edit or remove without losing track of assigned residents.</p>
          </div>
          <span class="badge badge-brand-muted align-self-start align-self-md-center"><?= (int)$totalAreaMatches ?> match<?= $totalAreaMatches === 1 ? '' : 'es' ?></span>
        </div>
      </div>
      <div class="card-body dashboard-card-body">
        <form method="post" class="row g-2 align-items-end">
          <input type="hidden" name="action" value="add_area">
          <div class="col-12 col-md-5">
            <label class="form-label" for="area_name">Area name</label>
            <input id="area_name" name="area_name" class="form-control" placeholder="Add a new area" required>
          </div>
          <div class="col-12 col-md-5">
            <label class="form-label" for="council">Council</label>
            <input id="council" name="council" class="form-control" placeholder="Council or region owner">
          </div>
          <div class="col-12 col-md-2">
            <button class="btn btn-culture w-100">Add Area</button>
          </div>
        </form>

        <form method="get" class="row g-2 align-items-end js-filter-form">
          <?= admin_hidden_query_inputs(['areas_query', 'areas_page']) ?>
          <input type="hidden" name="areas_page" value="1">
          <div class="col-12 col-md-8">
            <label class="form-label" for="areas_query">Search areas or councils</label>
            <input
              id="areas_query"
              name="areas_query"
              type="search"
              value="<?= htmlspecialchars($areasSearch) ?>"
              class="form-control js-live-filter"
              data-target-table="areas-table"
              data-empty-target="areas-page-empty"
              placeholder="Type an area name or council"
              autocomplete="off"
            >
          </div>
          <div class="col-6 col-md-2">
            <button class="btn btn-culture w-100">Search</button>
          </div>
          <div class="col-6 col-md-2">
            <a class="btn btn-outline-secondary w-100" href="<?= htmlspecialchars(admin_page_url(['areas_query' => null, 'areas_page' => 1], 'manage-areas')) ?>">Clear</a>
          </div>
          <div class="col-12">
            <small class="dashboard-search-hint">Typing filters the current page immediately and then refreshes the results after a short pause.</small>
          </div>
        </form>

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
          <p class="text-muted small mb-0" role="status">
            Showing <?= $areaStart ?>-<?= $areaEnd ?> of <?= (int)$totalAreaMatches ?> areas.
          </p>
          <?php if ($totalAreaMatches > 0): ?>
            <nav aria-label="Areas pagination">
              <ul class="pagination pagination-sm mb-0 flex-wrap">
                <li class="page-item <?= $areasPage <= 1 ? 'disabled' : '' ?>">
                  <a class="page-link" href="<?= htmlspecialchars(admin_page_url(['areas_page' => $areasPage - 1], 'manage-areas')) ?>">Previous</a>
                </li>
                <?php foreach (admin_pagination_window($areasPage, $areaTotalPages) as $pageNumber): ?>
                  <li class="page-item <?= $pageNumber === $areasPage ? 'active' : '' ?>">
                    <a class="page-link" href="<?= htmlspecialchars(admin_page_url(['areas_page' => $pageNumber], 'manage-areas')) ?>"><?= $pageNumber ?></a>
                  </li>
                <?php endforeach; ?>
                <li class="page-item <?= $areasPage >= $areaTotalPages ? 'disabled' : '' ?>">
                  <a class="page-link" href="<?= htmlspecialchars(admin_page_url(['areas_page' => $areasPage + 1], 'manage-areas')) ?>">Next</a>
                </li>
              </ul>
            </nav>
          <?php endif; ?>
        </div>

        <div class="dashboard-table-wrap">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="areas-table">
              <thead>
                <tr>
                  <th>Area</th>
                  <th>Council</th>
                  <th>Residents</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($areas as $area): ?>
                  <tr data-filter-row data-filter-text="<?= htmlspecialchars(strtolower($area['area_name'] . ' ' . $area['council'])) ?>">
                    <td>
                      <input
                        form="area-update-<?= $area['area_id'] ?>"
                        name="area_name"
                        value="<?= htmlspecialchars($area['area_name']) ?>"
                        class="form-control"
                        required
                      >
                    </td>
                    <td>
                      <input
                        form="area-update-<?= $area['area_id'] ?>"
                        name="council"
                        value="<?= htmlspecialchars($area['council']) ?>"
                        class="form-control"
                      >
                    </td>
                    <td>
                      <span class="badge <?= (int)$area['resident_count'] > 0 ? 'badge-brand' : 'badge-brand-muted' ?>">
                        <?= (int)$area['resident_count'] ?>
                      </span>
                    </td>
                    <td class="text-end">
                      <div class="d-inline-flex flex-wrap justify-content-end gap-2">
                        <form method="post" id="area-update-<?= $area['area_id'] ?>">
                          <input type="hidden" name="action" value="update_area">
                          <input type="hidden" name="area_id" value="<?= $area['area_id'] ?>">
                          <button class="btn btn-culture btn-sm">Save</button>
                        </form>
                        <?php if ((int)$area['resident_count'] > 0): ?>
                          <button class="btn btn-outline-secondary btn-sm" type="button" disabled title="Reassign residents before deleting this area.">In Use</button>
                        <?php else: ?>
                          <button
                            type="button"
                            class="btn btn-outline-danger btn-sm"
                            data-bs-toggle="modal"
                            data-bs-target="#deleteAreaModal"
                            data-area-id="<?= $area['area_id'] ?>"
                            data-area-name="<?= htmlspecialchars($area['area_name'], ENT_QUOTES) ?>"
                          >
                            Delete
                          </button>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$areas): ?>
                  <tr>
                    <td colspan="4" class="text-muted"><?= $areasSearch !== '' ? 'No areas matched your search.' : 'No areas added yet.' ?></td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
        <div id="areas-page-empty" class="small text-muted d-none">No matching areas on this page.</div>
      </div>
    </div>
  </div>
  <div class="col-12" id="manage-categories">
    <div class="card shadow-sm h-100">
      <div class="card-header bg-white border-0 pb-0">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
          <div>
            <h5 class="mb-1">Manage Categories</h5>
            <p class="text-muted small mb-0">Managed categories feed the SME listing dropdown and keep reporting labels consistent across the platform.</p>
          </div>
          <span class="badge badge-brand-muted align-self-start align-self-md-center"><?= count($categoriesForAdmin) ?> <?= count($categoriesForAdmin) === 1 ? 'category' : 'categories' ?></span>
        </div>
      </div>
      <div class="card-body dashboard-card-body">
        <form method="post" class="row g-2 align-items-end">
          <input type="hidden" name="action" value="add_category">
          <div class="col-12 col-md-9">
            <label class="form-label" for="category_name">Category name</label>
            <input id="category_name" name="category_name" class="form-control" placeholder="Add a council-managed category" required>
          </div>
          <div class="col-12 col-md-3">
            <button class="btn btn-culture w-100">Add Category</button>
          </div>
        </form>

        <div class="dashboard-table-wrap">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead>
                <tr>
                  <th>Category</th>
                  <th>Live Listings</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($categoriesForAdmin as $category): ?>
                  <tr>
                    <td>
                      <input
                        form="category-update-<?= $category['category_id'] ?>"
                        name="category_name"
                        value="<?= htmlspecialchars($category['category_name']) ?>"
                        class="form-control"
                        required
                      >
                    </td>
                    <td>
                      <span class="badge <?= (int)$category['product_count'] > 0 ? 'badge-brand' : 'badge-brand-muted' ?>">
                        <?= (int)$category['product_count'] ?>
                      </span>
                    </td>
                    <td class="text-end">
                      <div class="d-inline-flex flex-wrap justify-content-end gap-2">
                        <form method="post" id="category-update-<?= $category['category_id'] ?>">
                          <input type="hidden" name="action" value="update_category">
                          <input type="hidden" name="category_id" value="<?= $category['category_id'] ?>">
                          <button class="btn btn-culture btn-sm">Save</button>
                        </form>
                        <?php if ((int)$category['product_count'] > 0): ?>
                          <button class="btn btn-outline-secondary btn-sm" type="button" disabled title="Remove or reassign linked products before deleting this category.">In Use</button>
                        <?php else: ?>
                          <form method="post" onsubmit="return confirm('Delete this category? SMEs will no longer be able to choose it.');">
                            <input type="hidden" name="action" value="delete_category">
                            <input type="hidden" name="category_id" value="<?= $category['category_id'] ?>">
                            <button class="btn btn-outline-danger btn-sm">Delete</button>
                          </form>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$categoriesForAdmin): ?>
                  <tr>
                    <td colspan="3" class="text-muted">No categories added yet.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-12" id="reports">
    <div class="card shadow-sm h-100">
      <div class="card-header bg-white border-0 pb-0">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
          <div>
            <h5 class="mb-1">Council Reporting</h5>
            <p class="text-muted small mb-0">Use the leaderboard for quick status checks, switch to area analytics for deeper trends.</p>
          </div>
          <span class="badge badge-brand-muted align-self-start align-self-md-center"><?= count($ranking) ?> visible row<?= count($ranking) === 1 ? '' : 's' ?></span>
        </div>
      </div>
      <div class="card-body dashboard-card-body">
        <ul class="nav nav-tabs dashboard-tabs" id="reportTabs" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link active" id="leaderboard-tab" data-bs-toggle="tab" data-bs-target="#leaderboard-pane" type="button" role="tab" aria-controls="leaderboard-pane" aria-selected="true">
              Leaderboard
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="analytics-tab" data-bs-toggle="tab" data-bs-target="#analytics-pane" type="button" role="tab" aria-controls="analytics-pane" aria-selected="false">
              Area Analytics
            </button>
          </li>
        </ul>

        <div class="tab-content pt-3">
          <div class="tab-pane fade show active" id="leaderboard-pane" role="tabpanel" aria-labelledby="leaderboard-tab" tabindex="0">
            <form method="get" class="row g-2 align-items-end js-filter-form">
              <?= admin_hidden_query_inputs(['leaderboard_query', 'leaderboard_page']) ?>
              <input type="hidden" name="leaderboard_page" value="1">
              <div class="col-12 col-md-8">
                <label class="form-label" for="leaderboard_query">Search products or categories</label>
                <input
                  id="leaderboard_query"
                  name="leaderboard_query"
                  type="search"
                  value="<?= htmlspecialchars($leaderboardSearch) ?>"
                  class="form-control js-live-filter"
                  data-target-table="leaderboard-table"
                  data-empty-target="leaderboard-page-empty"
                  placeholder="Type a product name or category"
                  autocomplete="off"
                >
              </div>
              <div class="col-6 col-md-2">
                <button class="btn btn-culture w-100">Search</button>
              </div>
              <div class="col-6 col-md-2">
                <a class="btn btn-outline-secondary w-100" href="<?= htmlspecialchars(admin_page_url(['leaderboard_query' => null, 'leaderboard_page' => 1], 'leaderboard-pane')) ?>">Clear</a>
              </div>
              <div class="col-12">
                <small class="dashboard-search-hint">Search narrows the leaderboard to the products or categories.</small>
              </div>
            </form>

            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-2">
              <p class="text-muted small mb-0" role="status">
                Showing <?= $leaderboardStart ?>-<?= $leaderboardEnd ?> of <?= (int)$totalLeaderboardMatches ?> products.
              </p>
              <?php if ($totalLeaderboardMatches > 0): ?>
                <nav aria-label="Leaderboard pagination">
                  <ul class="pagination pagination-sm mb-0 flex-wrap">
                    <li class="page-item <?= $leaderboardPage <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= htmlspecialchars(admin_page_url(['leaderboard_page' => $leaderboardPage - 1], 'leaderboard-pane')) ?>">Previous</a>
                    </li>
                    <?php foreach (admin_pagination_window($leaderboardPage, $leaderboardTotalPages) as $pageNumber): ?>
                      <li class="page-item <?= $pageNumber === $leaderboardPage ? 'active' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars(admin_page_url(['leaderboard_page' => $pageNumber], 'leaderboard-pane')) ?>"><?= $pageNumber ?></a>
                      </li>
                    <?php endforeach; ?>
                    <li class="page-item <?= $leaderboardPage >= $leaderboardTotalPages ? 'disabled' : '' ?>">
                      <a class="page-link" href="<?= htmlspecialchars(admin_page_url(['leaderboard_page' => $leaderboardPage + 1], 'leaderboard-pane')) ?>">Next</a>
                    </li>
                  </ul>
                </nav>
              <?php endif; ?>
            </div>

            <div class="dashboard-table-wrap">
              <div class="table-responsive">
                <table class="table table-striped align-middle mb-0" id="leaderboard-table">
                  <thead>
                    <tr>
                      <th>#</th>
                      <th>Product</th>
                      <th>Category</th>
                      <th>Price</th>
                      <th>Yes</th>
                      <th>No</th>
                      <th>Score</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($ranking as $index => $product): ?>
                      <?php
                      $score = admin_score_value($product);
                      $rank = $leaderboardOffset + $index + 1;
                      ?>
                      <tr data-filter-row data-filter-text="<?= htmlspecialchars(strtolower($product['name'] . ' ' . $product['category'])) ?>">
                        <td><?= $rank ?></td>
                        <td><?= htmlspecialchars($product['name']) ?></td>
                        <td><?= htmlspecialchars($product['category']) ?></td>
                        <td>£<?= number_format((float)$product['price_value'], 2) ?></td>
                        <td><span class="badge <?= admin_vote_badge_class((int)$product['yes_votes'], 'yes') ?>"><?= (int)$product['yes_votes'] ?></span></td>
                        <td><span class="badge <?= admin_vote_badge_class((int)$product['no_votes'], 'no') ?>"><?= (int)$product['no_votes'] ?></span></td>
                        <td><span class="badge <?= admin_score_badge_class($score) ?>"><?= $score ?></span></td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if (!$ranking): ?>
                      <tr>
                        <td colspan="7" class="text-muted"><?= $leaderboardSearch !== '' ? 'No products matched your search.' : 'No products available yet.' ?></td>
                      </tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
            <div id="leaderboard-page-empty" class="small text-muted d-none">No matching products on this page.</div>
          </div>

          <div class="tab-pane fade" id="analytics-pane" role="tabpanel" aria-labelledby="analytics-tab" tabindex="0">
            <div class="row g-4">
              <div class="col-12 col-md-6">
                <div class="card border-0 bg-body-tertiary h-100">
                  <div class="card-body dashboard-card-body">
                    <div>
                      <h6 class="mb-1">Top Categories By Area</h6>
                      <p class="text-muted small mb-0">Quickly spot which cultural categories are leading each area.</p>
                    </div>
                    <div class="dashboard-table-wrap">
                      <div class="table-responsive">
                        <table class="table align-middle mb-0">
                          <thead>
                            <tr>
                              <th>Area</th>
                              <th>Category</th>
                              <th>Yes</th>
                              <th>Score</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach ($topCategoryByArea as $row): ?>
                              <?php $score = admin_score_value($row); ?>
                              <tr>
                                <td><?= htmlspecialchars($row['area_name']) ?></td>
                                <td><?= htmlspecialchars($row['category']) ?></td>
                                <td><span class="badge <?= admin_vote_badge_class((int)$row['yes_votes'], 'yes') ?>"><?= (int)$row['yes_votes'] ?></span></td>
                                <td><span class="badge <?= admin_score_badge_class($score) ?>"><?= $score ?></span></td>
                              </tr>
                            <?php endforeach; ?>
                            <?php if (!$topCategoryByArea): ?>
                              <tr><td colspan="4" class="text-muted">No area voting data yet.</td></tr>
                            <?php endif; ?>
                          </tbody>
                        </table>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="col-12 col-md-6">
                <div class="card border-0 bg-body-tertiary h-100">
                  <div class="card-body dashboard-card-body">
                    <div>
                      <h6 class="mb-1">Top Offerings By Area</h6>
                      <p class="text-muted small mb-0">Compare the strongest product or service in each location side by side.</p>
                    </div>
                    <div class="dashboard-table-wrap">
                      <div class="table-responsive">
                        <table class="table align-middle mb-0">
                          <thead>
                            <tr>
                              <th>Area</th>
                              <th>Offering</th>
                              <th>Category</th>
                              <th>Score</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach ($topProductByArea as $row): ?>
                              <?php $score = admin_score_value($row); ?>
                              <tr>
                                <td><?= htmlspecialchars($row['area_name']) ?></td>
                                <td><?= htmlspecialchars($row['product_name']) ?></td>
                                <td><?= htmlspecialchars($row['category']) ?></td>
                                <td><span class="badge <?= admin_score_badge_class($score) ?>"><?= $score ?></span></td>
                              </tr>
                            <?php endforeach; ?>
                            <?php if (!$topProductByArea): ?>
                              <tr><td colspan="4" class="text-muted">No area voting data yet.</td></tr>
                            <?php endif; ?>
                          </tbody>
                        </table>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="col-12 col-md-6">
                <div class="card border-0 bg-body-tertiary h-100">
                  <div class="card-body dashboard-card-body">
                    <div>
                      <h6 class="mb-1">Area-Specific Interests</h6>
                      <p class="text-muted small mb-0">Use resident interests to support funding and programming discussions.</p>
                    </div>
                    <div class="dashboard-table-wrap">
                      <div class="table-responsive">
                        <table class="table align-middle mb-0">
                          <thead>
                            <tr>
                              <th>Area</th>
                              <th>Interest</th>
                              <th>Residents</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach ($interestsByArea as $row): ?>
                              <tr>
                                <td><?= htmlspecialchars($row['area_name']) ?></td>
                                <td><?= htmlspecialchars($row['interest_name']) ?></td>
                                <td><span class="badge badge-brand-soft"><?= (int)$row['c'] ?></span></td>
                              </tr>
                            <?php endforeach; ?>
                            <?php if (!$interestsByArea): ?>
                              <tr><td colspan="3" class="text-muted">No resident interest data yet.</td></tr>
                            <?php endif; ?>
                          </tbody>
                        </table>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="col-12 col-md-6">
                <div class="card border-0 bg-body-tertiary h-100">
                  <div class="card-body dashboard-card-body">
                    <div>
                      <h6 class="mb-1">Detailed Category Demand</h6>
                      <p class="text-muted small mb-0">Review positive and negative demand without leaving the analytics view.</p>
                    </div>
                    <div class="dashboard-table-wrap">
                      <div class="table-responsive">
                        <table class="table align-middle mb-0">
                          <thead>
                            <tr>
                              <th>Area</th>
                              <th>Category</th>
                              <th>Yes</th>
                              <th>No</th>
                              <th>Score</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach ($categoryDemand as $row): ?>
                              <?php $score = admin_score_value($row); ?>
                              <tr>
                                <td><?= htmlspecialchars($row['area_name']) ?></td>
                                <td><?= htmlspecialchars($row['category']) ?></td>
                                <td><span class="badge <?= admin_vote_badge_class((int)$row['yes_votes'], 'yes') ?>"><?= (int)$row['yes_votes'] ?></span></td>
                                <td><span class="badge <?= admin_vote_badge_class((int)$row['no_votes'], 'no') ?>"><?= (int)$row['no_votes'] ?></span></td>
                                <td><span class="badge <?= admin_score_badge_class($score) ?>"><?= $score ?></span></td>
                              </tr>
                            <?php endforeach; ?>
                            <?php if (!$categoryDemand): ?>
                              <tr><td colspan="5" class="text-muted">No category demand data yet.</td></tr>
                            <?php endif; ?>
                          </tbody>
                        </table>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-12" id="people-management">
    <div class="row g-4">
      <div class="col-12 col-xl-6" id="residents-management">
        <div class="card shadow-sm h-100">
          <div class="card-header bg-white border-0 pb-0">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
              <div>
                <h5 class="mb-1">Manage Residents</h5>
                <p class="text-muted small mb-0">Monitor community registration and creative interest metrics to guide local resource allocation.</p>
              </div>
              <span class="badge badge-brand-muted align-self-start align-self-md-center"><?= (int)$totalResidentMatches ?> match<?= $totalResidentMatches === 1 ? '' : 'es' ?></span>
            </div>
          </div>
          <div class="card-body dashboard-card-body">
            <form method="get" class="row g-2 align-items-end js-filter-form">
              <?= admin_hidden_query_inputs(['residents_query', 'residents_page']) ?>
              <input type="hidden" name="residents_page" value="1">
              <div class="col-12 col-md-8">
                <label class="form-label" for="residents_query">Search by name or email</label>
                <input
                  id="residents_query"
                  name="residents_query"
                  type="search"
                  value="<?= htmlspecialchars($residentsSearch) ?>"
                  class="form-control js-live-filter"
                  data-target-table="residents-table"
                  data-empty-target="residents-page-empty"
                  placeholder="Type a resident name or email"
                  autocomplete="off"
                >
              </div>
              <div class="col-6 col-md-2">
                <button class="btn btn-culture w-100">Search</button>
              </div>
              <div class="col-6 col-md-2">
                <a class="btn btn-outline-secondary w-100" href="<?= htmlspecialchars(admin_page_url(['residents_query' => null, 'residents_page' => 1], 'residents-management')) ?>">Clear</a>
              </div>
              <div class="col-12">
                <small class="dashboard-search-hint">Maintain administrative oversight of resident demographics across all managed council areas.</small>
              </div>
            </form>

            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
              <p class="text-muted small mb-0" role="status">
                Showing <?= $residentStart ?>-<?= $residentEnd ?> of <?= (int)$totalResidentMatches ?> residents.
              </p>
              <?php if ($totalResidentMatches > 0): ?>
                <nav aria-label="Residents pagination">
                  <ul class="pagination pagination-sm mb-0 flex-wrap">
                    <li class="page-item <?= $residentsPage <= 1 ? 'disabled' : '' ?>">
                      <a class="page-link" href="<?= htmlspecialchars(admin_page_url(['residents_page' => $residentsPage - 1], 'residents-management')) ?>">Previous</a>
                    </li>
                    <?php foreach (admin_pagination_window($residentsPage, $residentTotalPages) as $pageNumber): ?>
                      <li class="page-item <?= $pageNumber === $residentsPage ? 'active' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars(admin_page_url(['residents_page' => $pageNumber], 'residents-management')) ?>"><?= $pageNumber ?></a>
                      </li>
                    <?php endforeach; ?>
                    <li class="page-item <?= $residentsPage >= $residentTotalPages ? 'disabled' : '' ?>">
                      <a class="page-link" href="<?= htmlspecialchars(admin_page_url(['residents_page' => $residentsPage + 1], 'residents-management')) ?>">Next</a>
                    </li>
                  </ul>
                </nav>
              <?php endif; ?>
            </div>

            <div class="dashboard-table-wrap dashboard-people-wrap">
              <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 dashboard-compact-table" id="residents-table">
                  <thead>
                    <tr>
                      <th>Resident Name</th>
                      <th>Account Email</th>
                      <th>Area</th>
                      <th>Interest Count</th>
                      <th class="text-end">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($residents as $resident): ?>
                      <tr data-filter-row data-filter-text="<?= htmlspecialchars(strtolower($resident['full_name'] . ' ' . $resident['email'] . ' ' . $resident['area_name'])) ?>">
                        <td>
                          <div class="fw-semibold"><?= htmlspecialchars($resident['full_name']) ?></div>
                          <div class="small text-muted"><?= htmlspecialchars($resident['title_name']) ?></div>
                        </td>
                        <td><?= htmlspecialchars($resident['email']) ?></td>
                        <td><?= htmlspecialchars($resident['area_name']) ?></td>
                        <td><span class="badge badge-brand-soft"><?= (int)$resident['interest_count'] ?></span></td>
                        <td class="text-end">
                          <div class="d-inline-flex gap-2 flex-wrap justify-content-end">
                            <button class="btn btn-outline-culture btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#residentModal<?= $resident['resident_id'] ?>">
                              Quick Edit
                            </button>
                            <form method="post" onsubmit="return confirm('Delete this resident and all linked votes?');">
                              <input type="hidden" name="action" value="delete_resident">
                              <input type="hidden" name="user_id" value="<?= $resident['user_id'] ?>">
                              <button class="btn btn-outline-danger btn-sm">Delete</button>
                            </form>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if (!$residents): ?>
                      <tr><td colspan="5" class="text-muted"><?= $residentsSearch !== '' ? 'No residents matched your search.' : 'No residents found.' ?></td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
            <div id="residents-page-empty" class="small text-muted d-none">No matching residents on this page.</div>
          </div>
        </div>
      </div>

      <div class="col-12 col-xl-6" id="smes-management">
        <div class="card shadow-sm h-100">
          <div class="card-header bg-white border-0 pb-0">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
              <div>
                <h5 class="mb-1">Manage SMEs</h5>
                <p class="text-muted small mb-0">Oversee the directory of local creative businesses and their professional service portfolios.</p>
              </div>
              <span class="badge badge-brand-muted align-self-start align-self-md-center"><?= (int)$totalSmeMatches ?> match<?= $totalSmeMatches === 1 ? '' : 'es' ?></span>
            </div>
          </div>
          <div class="card-body dashboard-card-body">
            <form method="get" class="row g-2 align-items-end js-filter-form">
              <?= admin_hidden_query_inputs(['smes_query', 'smes_page']) ?>
              <input type="hidden" name="smes_page" value="1">
              <div class="col-12 col-md-8">
                <label class="form-label" for="smes_query">Search by name or email</label>
                <input
                  id="smes_query"
                  name="smes_query"
                  type="search"
                  value="<?= htmlspecialchars($smesSearch) ?>"
                  class="form-control js-live-filter"
                  data-target-table="smes-table"
                  data-empty-target="smes-page-empty"
                  placeholder="Type a company, contact, or email"
                  autocomplete="off"
                >
              </div>
              <div class="col-6 col-md-2">
                <button class="btn btn-culture w-100">Search</button>
              </div>
              <div class="col-6 col-md-2">
                <a class="btn btn-outline-secondary w-100" href="<?= htmlspecialchars(admin_page_url(['smes_query' => null, 'smes_page' => 1], 'smes-management')) ?>">Clear</a>
              </div>
              <div class="col-12">
                <small class="dashboard-search-hint">Analyze demand signals to identify growth and marketing opportunities for the creative sector.</small>
              </div>
            </form>

            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
              <p class="text-muted small mb-0" role="status">
                Showing <?= $smeStart ?>-<?= $smeEnd ?> of <?= (int)$totalSmeMatches ?> SMEs.
              </p>
              <?php if ($totalSmeMatches > 0): ?>
                <nav aria-label="SME pagination">
                  <ul class="pagination pagination-sm mb-0 flex-wrap">
                    <li class="page-item <?= $smesPage <= 1 ? 'disabled' : '' ?>">
                      <a class="page-link" href="<?= htmlspecialchars(admin_page_url(['smes_page' => $smesPage - 1], 'smes-management')) ?>">Previous</a>
                    </li>
                    <?php foreach (admin_pagination_window($smesPage, $smeTotalPages) as $pageNumber): ?>
                      <li class="page-item <?= $pageNumber === $smesPage ? 'active' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars(admin_page_url(['smes_page' => $pageNumber], 'smes-management')) ?>"><?= $pageNumber ?></a>
                      </li>
                    <?php endforeach; ?>
                    <li class="page-item <?= $smesPage >= $smeTotalPages ? 'disabled' : '' ?>">
                      <a class="page-link" href="<?= htmlspecialchars(admin_page_url(['smes_page' => $smesPage + 1], 'smes-management')) ?>">Next</a>
                    </li>
                  </ul>
                </nav>
              <?php endif; ?>
            </div>

            <div class="dashboard-table-wrap dashboard-people-wrap">
              <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 dashboard-compact-table" id="smes-table">
                  <thead>
                    <tr>
                      <th>Company Name</th>
                      <th>Account Email</th>
                      <th>Contact Name</th>
                      <th>Listing Count</th>
                      <th class="text-end">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($smes as $sme): ?>
                      <tr data-filter-row data-filter-text="<?= htmlspecialchars(strtolower($sme['company_name'] . ' ' . $sme['contact_name'] . ' ' . $sme['email'])) ?>">
                        <td>
                          <div class="fw-semibold"><?= htmlspecialchars($sme['company_name']) ?></div>
                        </td>
                        <td><?= htmlspecialchars($sme['email']) ?></td>
                        <td><?= htmlspecialchars($sme['contact_name'] ?: 'No contact set') ?></td>
                        <td><span class="badge badge-brand-soft"><?= (int)$sme['product_count'] ?></span></td>
                        <td class="text-end">
                          <div class="d-inline-flex gap-2 flex-wrap justify-content-end">
                            <button class="btn btn-outline-culture btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#smeModal<?= $sme['sme_id'] ?>">
                              Quick Edit
                            </button>
                            <form method="post" onsubmit="return confirm('Delete this SME and all linked products?');">
                              <input type="hidden" name="action" value="delete_sme">
                              <input type="hidden" name="user_id" value="<?= $sme['user_id'] ?>">
                              <button class="btn btn-outline-danger btn-sm">Delete</button>
                            </form>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if (!$smes): ?>
                      <tr><td colspan="5" class="text-muted"><?= $smesSearch !== '' ? 'No SMEs matched your search.' : 'No SMEs found.' ?></td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
            <div id="smes-page-empty" class="small text-muted d-none">No matching SMEs on this page.</div>
          </div>
        </div>
      </div>
    </div>

    <?php foreach ($residents as $resident): ?>
      <?php $selectedResidentInterests = $residentInterestMap[(int)$resident['resident_id']] ?? []; ?>
      <div class="modal fade" id="residentModal<?= $resident['resident_id'] ?>" tabindex="-1" aria-labelledby="residentModalLabel<?= $resident['resident_id'] ?>" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
          <div class="modal-content">
            <div class="modal-header">
              <div>
                <h5 class="modal-title" id="residentModalLabel<?= $resident['resident_id'] ?>">Edit Resident</h5>
                <p class="small text-muted mb-0"><?= htmlspecialchars($resident['full_name']) ?> · <?= htmlspecialchars($resident['email']) ?></p>
              </div>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <form method="post" class="row g-3 dashboard-modal-form" id="resident-update-<?= $resident['resident_id'] ?>">
                <input type="hidden" name="action" value="update_resident">
                <input type="hidden" name="resident_id" value="<?= $resident['resident_id'] ?>">
                <input type="hidden" name="user_id" value="<?= $resident['user_id'] ?>">
                <div class="col-md-6">
                  <label class="form-label">Email</label>
                  <input name="email" value="<?= htmlspecialchars($resident['email']) ?>" type="email" class="form-control" required>
                </div>
                <div class="col-md-3">
                  <label class="form-label">Title</label>
                  <select name="title_id" class="form-select" required>
                    <?php foreach ($titles as $title): ?>
                      <option value="<?= $title['title_id'] ?>" <?= (int)$title['title_id'] === (int)$resident['title_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($title['title_name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-3">
                  <label class="form-label">Area</label>
                  <select name="area_id" class="form-select" required>
                    <?php foreach ($allAreasForSelect as $areaOption): ?>
                      <option value="<?= $areaOption['area_id'] ?>" <?= (int)$areaOption['area_id'] === (int)$resident['area_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($areaOption['area_name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-12">
                  <label class="form-label">Full Name</label>
                  <input name="full_name" value="<?= htmlspecialchars($resident['full_name']) ?>" class="form-control" required>
                </div>
                <div class="col-12">
                  <label class="form-label">Location</label>
                  <input name="location" value="<?= htmlspecialchars($resident['location']) ?>" class="form-control">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Age Group</label>
                  <select name="age_group" class="form-select" required>
                    <?php foreach ($ageOptions as $ageOption): ?>
                      <option value="<?= htmlspecialchars($ageOption) ?>" <?= $ageOption === $resident['age_group'] ? 'selected' : '' ?>><?= htmlspecialchars($ageOption) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Gender</label>
                  <select name="gender" class="form-select" required>
                    <?php foreach ($genderOptions as $genderOption): ?>
                      <option value="<?= htmlspecialchars($genderOption) ?>" <?= $genderOption === $resident['gender'] ? 'selected' : '' ?>><?= htmlspecialchars($genderOption) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-12">
                  <label class="form-label">Interests</label>
                  <div class="dashboard-inline-checks">
                    <?php foreach ($interests as $interest): ?>
                      <div class="form-check form-check-inline">
                        <input
                          type="checkbox"
                          class="form-check-input"
                          name="interests[]"
                          value="<?= $interest['interest_id'] ?>"
                          id="admin-resident-interest-<?= $resident['resident_id'] ?>-<?= $interest['interest_id'] ?>"
                          <?= in_array((int)$interest['interest_id'], $selectedResidentInterests, true) ? 'checked' : '' ?>
                        >
                        <label class="form-check-label" for="admin-resident-interest-<?= $resident['resident_id'] ?>-<?= $interest['interest_id'] ?>">
                          <?= htmlspecialchars($interest['interest_name']) ?>
                        </label>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </form>
            </div>
            <div class="modal-footer justify-content-between">
              <form method="post" onsubmit="return confirm('Delete this resident and all linked votes?');">
                <input type="hidden" name="action" value="delete_resident">
                <input type="hidden" name="user_id" value="<?= $resident['user_id'] ?>">
                <button class="btn btn-outline-danger">Delete Resident</button>
              </form>
              <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-culture" form="resident-update-<?= $resident['resident_id'] ?>">Save Changes</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>

    <?php foreach ($smes as $sme): ?>
      <div class="modal fade" id="smeModal<?= $sme['sme_id'] ?>" tabindex="-1" aria-labelledby="smeModalLabel<?= $sme['sme_id'] ?>" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
          <div class="modal-content">
            <div class="modal-header">
              <div>
                <h5 class="modal-title" id="smeModalLabel<?= $sme['sme_id'] ?>">Edit SME</h5>
                <p class="small text-muted mb-0"><?= htmlspecialchars($sme['company_name']) ?> · <?= htmlspecialchars($sme['email']) ?></p>
              </div>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <form method="post" class="row g-3 dashboard-modal-form" id="sme-update-<?= $sme['sme_id'] ?>">
                <input type="hidden" name="action" value="update_sme">
                <input type="hidden" name="sme_id" value="<?= $sme['sme_id'] ?>">
                <input type="hidden" name="user_id" value="<?= $sme['user_id'] ?>">
                <div class="col-md-6">
                  <label class="form-label">Email</label>
                  <input name="email" value="<?= htmlspecialchars($sme['email']) ?>" type="email" class="form-control" required>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Company / Artist Name</label>
                  <input name="company_name" value="<?= htmlspecialchars($sme['company_name']) ?>" class="form-control" required>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Contact Name</label>
                  <input name="contact_name" value="<?= htmlspecialchars($sme['contact_name']) ?>" class="form-control">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Phone</label>
                  <input name="contact_phone" value="<?= htmlspecialchars($sme['contact_phone']) ?>" class="form-control">
                </div>
                <div class="col-md-8">
                  <label class="form-label">Portfolio URL</label>
                  <input name="portfolio_url" value="<?= htmlspecialchars($sme['portfolio_url']) ?>" type="url" class="form-control" placeholder="https://...">
                </div>
                <div class="col-md-4">
                  <label class="form-label">Listing Count</label>
                  <input value="<?= (int)$sme['product_count'] ?>" class="form-control" disabled>
                </div>
              </form>
            </div>
            <div class="modal-footer justify-content-between">
              <form method="post" onsubmit="return confirm('Delete this SME and all linked products?');">
                <input type="hidden" name="action" value="delete_sme">
                <input type="hidden" name="user_id" value="<?= $sme['user_id'] ?>">
                <button class="btn btn-outline-danger">Delete SME</button>
              </form>
              <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-culture" form="sme-update-<?= $sme['sme_id'] ?>">Save Changes</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="modal fade" id="deleteAreaModal" tabindex="-1" aria-labelledby="deleteAreaModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header">
          <h5 class="modal-title" id="deleteAreaModalLabel">Delete Area</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="action" value="delete_area">
          <input type="hidden" name="area_id" id="delete-area-id">
          <p class="mb-2">You are about to permanently delete <strong id="delete-area-name">this area</strong>.</p>
          <p class="text-muted mb-0">Only delete areas that no longer have residents assigned.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger">Delete Area</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var deleteAreaModal = document.getElementById('deleteAreaModal');
  if (deleteAreaModal) {
    deleteAreaModal.addEventListener('show.bs.modal', function (event) {
      var trigger = event.relatedTarget;
      if (!trigger) {
        return;
      }
      var areaId = trigger.getAttribute('data-area-id') || '';
      var areaName = trigger.getAttribute('data-area-name') || 'this area';
      var areaIdInput = deleteAreaModal.querySelector('#delete-area-id');
      var areaNameLabel = deleteAreaModal.querySelector('#delete-area-name');
      if (areaIdInput) {
        areaIdInput.value = areaId;
      }
      if (areaNameLabel) {
        areaNameLabel.textContent = areaName;
      }
    });
  }

  document.querySelectorAll('.js-filter-form').forEach(function (form) {
    var input = form.querySelector('.js-live-filter');
    if (!input) {
      return;
    }

    var targetTable = document.getElementById(input.getAttribute('data-target-table'));
    var emptyTarget = document.getElementById(input.getAttribute('data-empty-target'));
    var debounceId = null;

    var filterVisibleRows = function () {
      if (!targetTable) {
        return;
      }
      var query = input.value.trim().toLowerCase();
      var rows = targetTable.querySelectorAll('tbody tr[data-filter-row]');
      var visibleRows = 0;

      rows.forEach(function (row) {
        var haystack = (row.getAttribute('data-filter-text') || row.textContent || '').toLowerCase();
        var matches = query === '' || haystack.indexOf(query) !== -1;
        row.classList.toggle('d-none', !matches);
        if (matches) {
          visibleRows += 1;
        }
      });

      if (emptyTarget) {
        var showClientEmpty = query !== '' && rows.length > 0 && visibleRows === 0;
        emptyTarget.classList.toggle('d-none', !showClientEmpty);
      }
    };

    input.addEventListener('input', function () {
      filterVisibleRows();
      window.clearTimeout(debounceId);
      debounceId = window.setTimeout(function () {
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          form.submit();
        }
      }, 350);
    });

    filterVisibleRows();
  });

  var tabs = document.querySelectorAll('#reportTabs button[data-bs-toggle="tab"]');
  tabs.forEach(function (tabButton) {
    tabButton.addEventListener('shown.bs.tab', function (event) {
      var target = event.target.getAttribute('data-bs-target');
      if (target) {
        history.replaceState(null, '', target);
      }
    });
  });

  if (window.location.hash) {
    var activeTabButton = document.querySelector('#reportTabs button[data-bs-target="' + window.location.hash + '"]');
    if (activeTabButton && window.bootstrap && window.bootstrap.Tab) {
      window.bootstrap.Tab.getOrCreateInstance(activeTabButton).show();
    }
  }
});
</script>
<?php require 'includes/footer.php'; ?>
