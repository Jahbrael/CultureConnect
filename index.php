<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$sessionRole = $_SESSION['role'] ?? null;

require 'includes/db.php';

function cultureconnect_total(mysqli $conn, string $sql): int
{
    $result = $conn->query($sql);
    if (!$result) {
        return 0;
    }

    return (int)($result->fetch_assoc()['total'] ?? 0);
}

$dashboardLink = null;
if ($sessionRole === 'admin') {
    $dashboardLink = 'dashboard_admin.php';
} elseif ($sessionRole === 'sme') {
    $dashboardLink = 'dashboard_sme.php';
} elseif ($sessionRole === 'resident') {
    $dashboardLink = 'dashboard_resident.php';
}

$secondaryCtaHref = $dashboardLink ?: 'register.php';
$secondaryCtaLabel = $dashboardLink ? 'Go to Dashboard' : 'Get Started';
$councilCtaHref = $dashboardLink ?: 'register.php?role=sme';
$councilCtaLabel = $dashboardLink ? 'Open Council View' : 'Join as an SME or Resident';

$stats = [
    [
        'icon' => 'bi bi-geo-alt-fill',
        'value' => cultureconnect_total($conn, 'SELECT COUNT(*) AS total FROM areas'),
        'label' => 'Local Areas',
        'hint' => 'Council-managed regions',
    ],
    [
        'icon' => 'bi bi-shop-window',
        'value' => cultureconnect_total($conn, 'SELECT COUNT(*) AS total FROM smes'),
        'label' => 'Creative SMEs',
        'hint' => 'Business profiles listed',
    ],
    [
        'icon' => 'bi bi-grid-1x2-fill',
        'value' => cultureconnect_total($conn, 'SELECT COUNT(*) AS total FROM products'),
        'label' => 'Offerings',
        'hint' => 'Services and products ready to browse',
    ],
    [
        'icon' => 'bi bi-hand-thumbs-up-fill',
        'value' => cultureconnect_total($conn, 'SELECT COUNT(*) AS total FROM votes'),
        'label' => 'Community Votes',
        'hint' => 'Resident feedback already captured',
    ],
];

$categories = [
    [
        'type' => 'Service',
        'eyebrow' => 'Creative Workshops',
        'title' => 'Creative Workshops',
        'description' => 'Hands-on art, ceramics, music, and mixed-media sessions that invite every skill level in.',
        'icon' => '🎨',
        'href' => 'browse.php?category=Workshops',
        'media_style' => 'background: linear-gradient(135deg, #f6eaff 0%, #d8c1ff 100%);',
    ],
    [
        'type' => 'Service',
        'eyebrow' => 'Performing Arts',
        'title' => 'Performing Arts',
        'description' => 'Theatre, concerts, spoken word, and live cultural events that bring local audiences together.',
        'icon' => '🎭',
        'href' => 'browse.php?category=Performing+Arts',
        'media_style' => 'background: linear-gradient(135deg, #fbe7ff 0%, #cbb8ff 100%);',
    ],
    [
        'type' => 'Service',
        'eyebrow' => 'Cultural Experiences',
        'title' => 'Cultural Experiences',
        'description' => 'Discover heritage walks, gallery visits, artist talks, and place-based cultural stories.',
        'icon' => '🗺️',
        'href' => 'browse.php',
        'media_style' => 'background: linear-gradient(135deg, #eef5ff 0%, #d4d7ff 100%);',
    ],
    [
        'type' => 'Service',
        'eyebrow' => 'Creative Services',
        'title' => 'Creative Services',
        'description' => 'Browse photography, design, and commissioned creative support for events, campaigns, and projects.',
        'icon' => '📸',
        'href' => 'browse.php',
        'media_style' => 'background: linear-gradient(135deg, #ffeaf2 0%, #e0c1ff 100%);',
    ],
    [
        'type' => 'Product',
        'eyebrow' => 'Art & Handmade Goods',
        'title' => 'Art & Handmade Goods',
        'description' => 'Original artwork, ceramics, and crafted pieces made by local creative businesses.',
        'icon' => '🏺',
        'href' => 'browse.php?category=Handmade+Goods',
        'media_style' => 'background: linear-gradient(135deg, #fff0df 0%, #efd0ff 100%);',
    ],
    [
        'type' => 'Product',
        'eyebrow' => 'Literary & Media',
        'title' => 'Literary & Media',
        'description' => 'Independent books, screenings, documentaries, and digital storytelling rooted in the community.',
        'icon' => '🎬',
        'href' => 'browse.php?category=Literary+%26+Media',
        'media_style' => 'background: linear-gradient(135deg, #e7f0ff 0%, #d4c6ff 100%);',
    ],
    [
        'type' => 'Product',
        'eyebrow' => 'Cultural Merchandise',
        'title' => 'Cultural Merchandise',
        'description' => 'Explore posters, stationery, keepsakes, and branded cultural goods created for events and exhibitions.',
        'icon' => '🖼️',
        'href' => 'browse.php',
        'media_style' => 'background: linear-gradient(135deg, #fff5ea 0%, #e7d0ff 100%);',
    ],
];

$stakeholderCards = [
    [
        'eyebrow' => 'For Residents',
        'icon' => '👥',
        'title' => 'Discover cultural activity that feels relevant from day one',
        'description' => 'Browse workshops, performances, products, and creative services in familiar categories, then vote on what matters most locally.',
        'media_style' => 'background: linear-gradient(135deg, #efe6ff 0%, #d5c1ff 100%);',
    ],
    [
        'eyebrow' => 'For SMEs',
        'icon' => '🏪',
        'title' => 'Showcase creative work with clearer visibility',
        'description' => 'Keep listings current, present your products and services professionally, and reach residents and councils in one trusted space.',
        'media_style' => 'background: linear-gradient(135deg, #fff0e7 0%, #e6cbff 100%);',
    ],
    [
        'eyebrow' => 'For Councils',
        'icon' => '📊',
        'title' => 'Turn participation into sharper cultural planteam websitening',
        'description' => 'See where demand is building, identify promising SME partnerships, and ground support decisions in real community signals.',
        'media_style' => 'background: linear-gradient(135deg, #e7f1ff 0%, #cfc2ff 100%);',
    ],
];

$councilInsights = [
    [
        'icon' => '📍',
        'title' => 'Area-aware feedback',
        'description' => 'See which neighbourhoods respond to workshops, performances, and cultural products.',
    ],
    [
        'icon' => '🤝',
        'title' => 'SME collaboration',
        'description' => 'Spot the creative businesses and categories already building momentum with residents.',
    ],
    [
        'icon' => '💡',
        'title' => 'Better funding decisions',
        'description' => 'Back programmes with clearer evidence from real community participation and demand.',
    ],
];

require 'includes/header.php';
?>
<section class="home-hero home-bleed position-relative overflow-hidden text-white">
  <div class="container-xl px-4 px-lg-5 home-section-shell home-hero-inner">
    <div class="row align-items-stretch home-hero-main-row">
      <div class="col-lg-7 col-xl-6 d-flex align-items-center">
        <div class="home-hero-copy-column">
          <span class="home-kicker">Culture-led local growth</span>
          <h1 class="mt-3 mb-4">Where local creativity meets community decisions.</h1>
          <p class="lead home-hero-copy mb-4">CultureConnect helps residents discover art classes, workshops, performances, and local creative services they care about, gives cultural SMEs stronger visibility, and equips councils with the feedback they need to support what communities actually value.</p>
          <div class="d-flex flex-wrap gap-3 home-hero-actions d-lg-none">
            <a href="browse.php" class="btn btn-brand-primary btn-lg px-4">Browse Offerings</a>
            <a href="<?= htmlspecialchars($secondaryCtaHref) ?>" class="btn btn-brand-outline btn-lg px-4"><?= htmlspecialchars($secondaryCtaLabel) ?></a>
          </div>
        </div>
      </div>
      <div class="col-lg-5 col-xl-6 d-none d-lg-flex align-items-center justify-content-center justify-content-xl-end">
        <div class="home-hero-cta-column">
          <div class="d-flex flex-column gap-3 home-hero-actions home-hero-actions-desktop">
            <a href="browse.php" class="btn btn-brand-primary btn-lg px-4">Browse Offerings</a>
            <a href="<?= htmlspecialchars($secondaryCtaHref) ?>" class="btn btn-brand-outline btn-lg px-4"><?= htmlspecialchars($secondaryCtaLabel) ?></a>
          </div>
        </div>
      </div>
    </div>

    <div class="home-hero-stats-wrap">
      <div class="row row-cols-2 row-cols-xl-4 g-3 g-lg-4 home-hero-stats-row">
        <?php foreach ($stats as $stat): ?>
          <div class="col">
            <div class="home-stat-card h-100">
              <span class="home-stat-icon" aria-hidden="true"><i class="<?= htmlspecialchars($stat['icon']) ?>"></i></span>
              <div class="home-stat-value"><?= (int)$stat['value'] ?></div>
              <div class="home-stat-label"><?= htmlspecialchars($stat['label']) ?></div>
              <div class="home-stat-hint"><?= htmlspecialchars($stat['hint']) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<section class="home-section home-explore-section">
  <div class="container-xl px-4 px-lg-5 home-section-shell">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
      <div>
        <p class="section-eyebrow mb-2">Explore by category</p>
        <h2 class="section-heading mb-2">Services and products organised for easy discovery</h2>
        <p class="section-copy mb-0">CultureConnect groups cultural services and products into clear categories, making it easier for residents to discover, for SMEs to showcase, and for councils to review local demand.</p>
      </div>
      <a href="browse.php" class="btn btn-outline-primary home-section-action">View All Offerings</a>
    </div>

    <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-4">
      <?php foreach ($categories as $category): ?>
        <div class="col">
          <div class="card home-category-card border-0 shadow-sm h-100">
            <div class="home-category-media" style="<?= htmlspecialchars($category['media_style']) ?>">
              <span class="home-category-icon"><?= $category['icon'] ?></span>
              <span class="badge rounded-pill home-category-tag"><?= htmlspecialchars($category['type']) ?></span>
            </div>
            <div class="card-body p-4 d-flex flex-column">
              <p class="home-card-eyebrow mb-2"><?= htmlspecialchars($category['eyebrow']) ?></p>
              <h3 class="h5 mb-3"><?= htmlspecialchars($category['title']) ?></h3>
              <p class="section-copy mb-4"><?= htmlspecialchars($category['description']) ?></p>
              <a href="<?= htmlspecialchars($category['href']) ?>" class="home-card-link mt-auto">Explore <?= htmlspecialchars($category['title']) ?> <span aria-hidden="true">→</span></a>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="home-section home-stakeholder-section home-bleed">
  <div class="container-xl px-4 px-lg-5 home-section-shell">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4 home-stakeholder-intro">
      <div>
        <p class="section-eyebrow mb-2">Built For Every Stakeholder</p>
        <h2 class="section-heading mb-2">Clear discovery for residents, stronger visibility for SMEs, and better oversight for councils.</h2>
        <p class="section-copy mb-0">Residents, SMEs, and councils each have a focused experience, while the platform stays simple, calm, and easy to navigate.</p>
      </div>
    </div>

    <div class="row row-cols-1 row-cols-lg-3 home-card-grid home-stakeholder-row">
      <?php foreach ($stakeholderCards as $stakeholder): ?>
        <div class="col">
          <article class="card home-stakeholder-card border-0 shadow-sm h-100">
            <div class="home-stakeholder-media" style="<?= htmlspecialchars($stakeholder['media_style']) ?>">
              <span class="home-stakeholder-icon" aria-hidden="true"><?= htmlspecialchars($stakeholder['icon']) ?></span>
              <span class="badge rounded-pill home-stakeholder-chip"><?= htmlspecialchars($stakeholder['eyebrow']) ?></span>
            </div>
            <div class="card-body p-4 d-flex flex-column">
              <h3 class="h5 mb-3"><?= htmlspecialchars($stakeholder['title']) ?></h3>
              <p class="section-copy mb-0"><?= htmlspecialchars($stakeholder['description']) ?></p>
            </div>
          </article>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="home-section home-council-section home-bleed">
  <div class="container-xl px-4 px-lg-5 home-section-shell">
    <div class="home-council-shell">
      <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
        <div>
          <p class="section-eyebrow mb-2">Council Insights</p>
          <h2 class="section-heading home-council-heading mb-3">Empowering Councils with Community Insights.</h2>
          <p class="section-copy mb-0">Councils can add regions, understand what residents respond to, and make more confident decisions about which cultural services and SMEs deserve support next.</p>
        </div>
      </div>

      <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 home-card-grid">
        <?php foreach ($councilInsights as $insight): ?>
          <div class="col">
            <div class="home-council-insight-card h-100">
              <span class="home-council-insight-icon"><?= $insight['icon'] ?></span>
              <h3 class="h5 mb-2"><?= htmlspecialchars($insight['title']) ?></h3>
              <p class="section-copy mb-0"><?= htmlspecialchars($insight['description']) ?></p>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<?php require 'includes/footer.php'; ?>
