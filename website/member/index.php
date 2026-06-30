<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/community_lib.php';

ryerson_community_start_member_session();

$member = [];
try {
	load_env_file();
	$mysqli = create_database_connection();
	$member = ryerson_community_current_member($mysqli);
	$mysqli->close();
} catch (RuntimeException $exception) {
	error_log('Ryerson member home error: ' . $exception->getMessage());
	ryerson_community_exit_with_message(500, 'Member Home Error', 'The member home page could not load.');
}

$isLoggedIn = count($member) > 0;
$displayName = $isLoggedIn ? (string) $member['display_name'] : '';
$nedbucksBalance = $isLoggedIn ? (int) $member['nedbucks_balance'] : 0;
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ryerson Member Home</title>
    <link rel="icon" type="image/png" href="../images/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-LN+7fdVzj6u52u30Kp6M/trliBMCMKTyK833zpbD+pXdCLuTusPj697FH4R/5mcr" crossorigin="anonymous">
  </head>
  <body>
    <main class="container py-4">
      <nav class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4" aria-label="Member navigation">
        <a class="btn btn-sm btn-outline-secondary" href="../index.html">Ryerson Home</a>
        <?php if ($isLoggedIn): ?>
        <a class="btn btn-sm btn-outline-secondary" href="logout.php">Log Out</a>
        <?php endif; ?>
      </nav>

      <?php if (!$isLoggedIn): ?>
      <section class="py-4">
        <h1 class="mb-3">Ryerson Member Home</h1>
        <a class="btn btn-primary" href="login.php">Log in with ORCID</a>
      </section>
      <?php else: ?>
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
          <h1 class="mb-1">Welcome, <?php echo ryerson_community_html($displayName); ?></h1>
          <p class="text-muted mb-0">Ryerson community member</p>
        </div>
        <div class="text-end">
          <div class="text-muted small">NEDbucks</div>
          <div class="display-6"><?php echo ryerson_community_html((string) $nedbucksBalance); ?></div>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-md-6 col-lg-4">
          <section class="border rounded p-3 h-100">
            <h2 class="h5">Suggest Item</h2>
            <a class="btn btn-primary" href="suggest-item.php">Suggest Item</a>
          </section>
        </div>
        <div class="col-md-6 col-lg-4">
          <section class="border rounded p-3 h-100">
            <h2 class="h5">Current Items</h2>
            <a class="btn btn-primary" href="current-items.php">View Items</a>
          </section>
        </div>
        <div class="col-md-6 col-lg-4">
          <section class="border rounded p-3 h-100">
            <h2 class="h5">Item Bakeoff</h2>
            <a class="btn btn-primary" href="item-bakeoff.php">Start Bakeoff</a>
          </section>
        </div>
        <div class="col-md-6 col-lg-4">
          <section class="border rounded p-3 h-100">
            <h2 class="h5">Member Stats</h2>
            <button class="btn btn-outline-secondary" type="button" disabled>Coming Soon</button>
          </section>
        </div>
        <div class="col-md-6 col-lg-4">
          <section class="border rounded p-3 h-100">
            <h2 class="h5">Purchase NEDbucks</h2>
            <button class="btn btn-outline-secondary" type="button" disabled>Coming Soon</button>
          </section>
        </div>
      </div>
      <?php endif; ?>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js" integrity="sha384-ndDqU0Gzau9qJ1lfW4pNLlhNTkCfHzAVBReH9diLvGRem5+R9g2FzA8ZGN954O5Q" crossorigin="anonymous"></script>
  </body>
</html>
