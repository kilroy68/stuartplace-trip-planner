<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require_once __DIR__ . '/auth/auth.php';
auth_require_login();
$currentUser = auth_current_user();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<title>David and Angela's Site</title>
<style>
:root{
  --ink:#102024;
  --muted:#5d6d72;
  --cream:#fff8ec;
  --sand:#f1dfc2;
  --ocean:#0f6c81;
  --orange:#d46b38;
  --sage:#7ea17b;
  --sky:#b9dbe2;
}
*{box-sizing:border-box}
html,body{height:100%;margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Inter,Arial,sans-serif;color:var(--ink);background:#f8efe0;overflow:hidden}
body:before{content:"";position:fixed;inset:-20%;background:
  radial-gradient(circle at 16% 18%, rgba(212,107,56,.22), transparent 28%),
  radial-gradient(circle at 82% 12%, rgba(15,108,129,.22), transparent 26%),
  radial-gradient(circle at 72% 78%, rgba(126,161,123,.28), transparent 30%),
  linear-gradient(135deg,#fff8ec 0%,#f1dfc2 48%,#cfe6e9 100%);z-index:-3}
body:after{content:"";position:fixed;inset:0;background-image:linear-gradient(rgba(16,32,36,.045) 1px,transparent 1px),linear-gradient(90deg,rgba(16,32,36,.045) 1px,transparent 1px);background-size:42px 42px;mask-image:linear-gradient(to bottom,rgba(0,0,0,.8),transparent 80%);z-index:-2}
.shell{min-height:100%;display:grid;place-items:center;padding:28px;position:relative}
.card{width:min(1040px,100%);min-height:min(680px,calc(100vh - 56px));display:grid;grid-template-columns:1.05fr .95fr;gap:22px;align-items:center;background:rgba(255,248,236,.76);border:1px solid rgba(255,255,255,.68);box-shadow:0 30px 90px rgba(45,54,48,.24);border-radius:36px;padding:clamp(28px,5vw,64px);backdrop-filter:blur(18px);position:relative;overflow:hidden}
.card:before{content:"";position:absolute;inset:18px;border:1px solid rgba(16,32,36,.08);border-radius:26px;pointer-events:none}
.copy{position:relative;z-index:2}.eyebrow{font-size:13px;font-weight:900;letter-spacing:.16em;text-transform:uppercase;color:var(--ocean);margin-bottom:18px}
h1{font-size:clamp(46px,8vw,92px);line-height:.93;letter-spacing:-.075em;margin:0 0 20px;text-wrap:balance}
p{font-size:clamp(17px,2.2vw,22px);line-height:1.5;color:#425155;max-width:580px;margin:0 0 28px}
.actions{display:flex;gap:12px;flex-wrap:wrap}.button{display:inline-flex;align-items:center;gap:10px;min-height:48px;border-radius:999px;padding:0 18px;text-decoration:none;font-weight:850;border:1px solid rgba(16,32,36,.15);color:var(--ink);background:#fff;box-shadow:0 8px 24px rgba(16,32,36,.08);transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease}.button:hover{transform:translateY(-2px);box-shadow:0 13px 30px rgba(16,32,36,.15);border-color:rgba(15,108,129,.45)}.button.primary{background:var(--ocean);color:white;border-color:var(--ocean)}
.art{position:relative;min-height:440px;z-index:1}.sun{position:absolute;right:15%;top:2%;width:132px;height:132px;border-radius:50%;background:radial-gradient(circle,#ffd78a 0%,#f9ae54 55%,#d46b38 100%);box-shadow:0 0 70px rgba(212,107,56,.36)}
.blob{position:absolute;border-radius:999px;filter:drop-shadow(0 24px 35px rgba(16,32,36,.16))}.blob.ocean{left:4%;right:4%;bottom:7%;height:132px;background:linear-gradient(135deg,#0f6c81,#6cb0b8);transform:rotate(-7deg)}.blob.sage{left:20%;right:18%;bottom:28%;height:92px;background:linear-gradient(135deg,#7ea17b,#cad8a5);transform:rotate(10deg)}.blob.sand{left:14%;right:8%;bottom:45%;height:118px;background:linear-gradient(135deg,#f5ddae,#d46b38);transform:rotate(-4deg)}
.road{position:absolute;left:30%;right:18%;bottom:9%;height:315px;border-left:14px solid rgba(255,255,255,.72);border-radius:70% 0 0 0;transform:rotate(20deg);opacity:.95}.road:after{content:"";position:absolute;left:-8px;top:20px;bottom:20px;border-left:3px dashed rgba(16,32,36,.28)}
.star{position:absolute;width:9px;height:9px;border-radius:50%;background:var(--ocean);box-shadow:0 0 0 8px rgba(15,108,129,.08)}.star.a{left:10%;top:17%}.star.b{right:9%;top:45%;background:var(--orange);box-shadow:0 0 0 8px rgba(212,107,56,.1)}.star.c{left:24%;bottom:12%;background:var(--sage);box-shadow:0 0 0 8px rgba(126,161,123,.12)}
.auth-bar{position:fixed;top:12px;right:14px;z-index:10;display:flex;gap:9px;align-items:center;background:rgba(255,255,255,.82);border:1px solid rgba(16,32,36,.1);box-shadow:0 10px 28px rgba(16,32,36,.12);border-radius:999px;padding:8px 11px;font-size:12px;color:#516064;backdrop-filter:blur(12px)}.auth-bar a{color:var(--ocean);font-weight:900;text-decoration:none}.auth-bar a:hover{text-decoration:underline}@media(max-width:820px){.auth-bar{position:static;margin:12px auto 0;justify-content:center;flex-wrap:wrap;border-radius:16px}}
.footer{position:absolute;left:clamp(24px,4vw,42px);right:clamp(24px,4vw,42px);bottom:18px;display:flex;justify-content:space-between;gap:16px;color:rgba(16,32,36,.55);font-size:13px;font-weight:700;z-index:2}.footer a{color:inherit;text-decoration:none}.footer a:hover{color:var(--ocean)}
@media(max-width:820px){html,body{overflow:auto}.card{grid-template-columns:1fr;min-height:auto;border-radius:26px}.art{min-height:300px;order:-1}.sun{width:96px;height:96px}.footer{position:static;margin:18px 4px 0;flex-direction:column}.shell{display:block}.card:before{display:none}}
@media(prefers-reduced-motion:no-preference){.sun{animation:float 7s ease-in-out infinite}.blob.sage{animation:float 8s ease-in-out infinite reverse}.blob.sand{animation:float 9s ease-in-out infinite}@keyframes float{0%,100%{translate:0 0}50%{translate:0 -12px}}}
</style>
</head>
<body>

<div class="auth-bar">
  <span>Signed in as <?= auth_h($currentUser['email'] ?? '') ?></span>
  <?php if (($currentUser['role'] ?? '') === 'admin'): ?><a href="<?= auth_h(auth_url('/auth/users.php')) ?>">Manage users</a><?php endif; ?>
  <a href="<?= auth_h(auth_url('/auth/logout.php')) ?>">Sign out</a>
</div>
<main class="shell">
  <section class="card" aria-label="Welcome">
    <div class="copy">
      <div class="eyebrow">Stuart Place</div>
      <h1>Welcome to David and Angela's site.</h1>
      <p>A simple home base for family projects, travel plans, shared notes, and whatever we decide to build next.</p>
      <div class="actions">
        <a class="button primary" href="/california-trip/">Open California Trip Planner →</a>
        <a class="button" href="mailto:david@stuartplace.net">Contact David</a>
      </div>
    </div>
    <div class="art" aria-hidden="true">
      <div class="sun"></div>
      <div class="blob sand"></div>
      <div class="blob sage"></div>
      <div class="blob ocean"></div>
      <div class="road"></div>
      <div class="star a"></div>
      <div class="star b"></div>
      <div class="star c"></div>
    </div>
    <div class="footer"><span>Made for David and Angela</span><a href="/california-trip/">California Coast + Yosemite</a></div>
  </section>
</main>
</body>
</html>
