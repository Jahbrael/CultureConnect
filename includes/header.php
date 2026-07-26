<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$role = $_SESSION['role'] ?? null;
$email = $_SESSION['email'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CultureConnect</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="assets/css/styles.css" rel="stylesheet">
</head>
<body class="<?= basename($_SERVER['PHP_SELF'] ?? '') === 'index.php' ? 'page-home' : '' ?>">
<nav class="navbar navbar-expand-lg navbar-dark site-nav">
  <div class="container">
    <a class="navbar-brand fw-bold" href="index.php">🎭 CultureConnect</a>
    <button class="navbar-toggler" data-bs-toggle="collapse" data-bs-target="#nav"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse d-lg-flex align-items-lg-center" id="nav">
      <ul class="navbar-nav me-auto align-items-lg-center site-nav-links">
        <li class="nav-item"><a class="nav-link" href="index.php">Home</a></li>
        <li class="nav-item"><a class="nav-link" href="browse.php">Browse</a></li>
        <?php if ($role === 'admin'): ?>
          <li class="nav-item"><a class="nav-link" href="dashboard_admin.php">Admin Dashboard</a></li>
        <?php elseif ($role === 'sme'): ?>
          <li class="nav-item"><a class="nav-link" href="dashboard_sme.php">SME Dashboard</a></li>
        <?php elseif ($role === 'resident'): ?>
          <li class="nav-item"><a class="nav-link" href="dashboard_resident.php">My Dashboard</a></li>
        <?php endif; ?>
      </ul>
      <ul class="navbar-nav ms-lg-auto align-items-lg-center site-nav-user-nav d-lg-flex align-items-lg-center">
        <?php if ($email): ?>
          <li class="nav-item d-flex align-items-center"><span class="navbar-text text-light me-lg-3 mb-0">Hi, <?= htmlspecialchars($email) ?></span></li>
          <li class="nav-item d-flex align-items-center mt-2 mt-lg-0"><a class="btn btn-outline-light btn-sm align-self-center d-inline-flex align-items-center" href="logout.php">Logout</a></li>
        <?php else: ?>
          <li class="nav-item d-flex align-items-center"><a class="btn btn-outline-light btn-sm me-lg-2 align-self-center d-inline-flex align-items-center" href="login.php">Sign In</a></li>
          <li class="nav-item d-flex align-items-center mt-2 mt-lg-0"><a class="btn btn-light btn-sm align-self-center d-inline-flex align-items-center" href="register.php">Register</a></li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>
<main class="container py-4 site-main">
<?php
// Heuristic: show flash messages on all pages except the homepage.
if (!empty($_SESSION['flash'])) {
    foreach ($_SESSION['flash'] as $f) {
        echo '<div class="alert alert-'.htmlspecialchars($f['type']).' alert-dismissible fade show">'
            .htmlspecialchars($f['msg'])
            .'<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
    unset($_SESSION['flash']);
}
