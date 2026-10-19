<?php
header('Cache-Control: no-store');
require_once __DIR__.'/../auth/auth.php';
auth_require_login();
$currentUser=auth_current_user();
$canEdit=auth_is_admin();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Future Trips • Stuart Place</title>
  <link rel="icon" href="/favicon.svg">
  <link rel="stylesheet" href="/assets/travel.css?v=future-trips-1">
  <link rel="stylesheet" href="/assets/future-trips.css?v=1">
</head>
<body>
<header class="topbar">
  <a class="brand" href="/">← STUART PLACE</a>
  <nav class="account" aria-label="Account">
    <a href="/california-trip/">California</a>
    <a href="/new-england-trip/">New England</a>
    <span><?=auth_h($currentUser['email'] ?? '')?></span>
    <a href="/auth/logout.php">Sign out</a>
  </nav>
</header>
<main class="wrap future-shell">
  <div class="eyebrow">David & Angela · Dream list</div>
  <div class="future-heading">
    <div><h1>Future Trips</h1><p class="muted">Keep the possibilities in one place. Move favorites toward the top as plans take shape.</p></div>
    <div class="idea-mark" aria-hidden="true">✦</div>
  </div>
  <?php if ($canEdit): ?>
  <section id="editor" class="panel future-editor" aria-labelledby="add-title">
    <h2 id="add-title">Add a possibility</h2>
    <form id="trip-form">
      <label>Trip title<input id="trip-title" name="title" maxlength="200" required placeholder="e.g. Canadian Rockies by rail"></label>
      <label>Description<textarea id="trip-description" name="description" maxlength="5000" required placeholder="What would make this trip special?"></textarea></label>
      <button class="primary" type="submit">Add future trip</button>
    </form>
  </section>
  <?php endif; ?>
  <section class="future-list-section" aria-labelledby="ideas-title">
    <div class="future-list-heading"><div><div class="eyebrow">Priority order</div><h2 id="ideas-title">Possibilities</h2></div><span id="trip-count" class="muted"></span></div>
    <p id="empty" class="panel empty" hidden>No future trips yet. Add the first possibility above.</p>
    <ol id="trip-list" class="future-list"></ol>
    <p id="status" role="status" aria-live="polite"></p>
  </section>
</main>
<script src="/assets/future-trips.js?v=2" defer></script>
</body>
</html>
