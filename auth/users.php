<?php
require_once __DIR__ . '/auth.php';
auth_require_admin();
$pdo = auth_db();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    auth_verify_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add') {
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Enter a valid email address.');
            }
            $stmt = $pdo->prepare("INSERT INTO allowed_users (email, role, status, created_by) VALUES (?, ?, 'active', ?) ON DUPLICATE KEY UPDATE role = VALUES(role), status = 'active'");
            $stmt->execute([$email, $role, auth_current_user()['email']]);
            $message = 'Allowed ' . $email . '.';
        } elseif ($action === 'disable' || $action === 'enable' || $action === 'delete') {
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid email address.');
            }
            if ($email === strtolower(auth_current_user()['email']) && $action !== 'enable') {
                throw new RuntimeException('You cannot disable or delete your own admin account while logged in.');
            }
            if ($action === 'delete') {
                $stmt = $pdo->prepare('DELETE FROM allowed_users WHERE email = ?');
                $stmt->execute([$email]);
                $message = 'Deleted ' . $email . '.';
            } else {
                $status = $action === 'enable' ? 'active' : 'disabled';
                $stmt = $pdo->prepare('UPDATE allowed_users SET status = ? WHERE email = ?');
                $stmt->execute([$status, $email]);
                $message = ucfirst($status) . ' ' . $email . '.';
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$users = $pdo->query('SELECT email, role, status, name, last_login_at, created_at, created_by FROM allowed_users ORDER BY role = "admin" DESC, email ASC')->fetchAll();
$current = auth_current_user();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Allowed users - Stuart Place</title>
<style>
:root{--ink:#102024;--muted:#5d6d72;--cream:#fff8ec;--line:#eadfcd;--ocean:#0f6c81;--orange:#d46b38}*{box-sizing:border-box}body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;background:#fff8ec;color:var(--ink)}header{padding:24px clamp(18px,5vw,56px);display:flex;justify-content:space-between;gap:16px;align-items:center;background:white;border-bottom:1px solid var(--line)}main{padding:28px clamp(18px,5vw,56px);max-width:1120px;margin:auto}h1{margin:0;font-size:28px}.nav{display:flex;gap:12px;flex-wrap:wrap}.nav a,.button,button{border:1px solid rgba(16,32,36,.15);border-radius:999px;background:white;color:var(--ink);font-weight:800;text-decoration:none;padding:10px 14px;cursor:pointer}.primary{background:var(--ocean);color:white;border-color:var(--ocean)}.danger{color:#9e2f20}.card{background:white;border:1px solid var(--line);border-radius:24px;padding:22px;margin-bottom:22px;box-shadow:0 12px 35px rgba(16,32,36,.08)}label{display:block;font-weight:800;margin-bottom:6px}input,select{width:100%;border:1px solid #d8cab9;border-radius:14px;padding:12px;font-size:16px}.grid{display:grid;grid-template-columns:1fr 150px auto;gap:12px;align-items:end}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:12px;border-bottom:1px solid var(--line);font-size:14px}th{color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.08em}.pill{display:inline-block;border-radius:999px;padding:4px 9px;font-size:12px;font-weight:850;background:#eef6f7;color:var(--ocean)}.disabled{background:#f7e7e1;color:#9e2f20}.msg{padding:12px 14px;border-radius:14px;margin-bottom:16px}.ok{background:#eaf6ed;color:#27633a}.err{background:#fdebe7;color:#9e2f20}@media(max-width:760px){.grid{grid-template-columns:1fr}table{display:block;overflow-x:auto}}</style>
</head>
<body>
<header>
  <div><h1>Allowed users</h1><div>Signed in as <?= auth_h($current['email']) ?></div></div>
  <nav class="nav"><a href="<?= auth_h(auth_url('/california-trip/')) ?>">Trip planner</a><a href="<?= auth_h(auth_url('/auth/logout.php')) ?>">Sign out</a></nav>
</header>
<main>
  <?php if ($message): ?><div class="msg ok"><?= auth_h($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="msg err"><?= auth_h($error) ?></div><?php endif; ?>
  <section class="card">
    <h2>Add a user</h2>
    <form method="post" class="grid">
      <input type="hidden" name="csrf" value="<?= auth_h(auth_csrf_token()) ?>">
      <input type="hidden" name="action" value="add">
      <div><label for="email">Google email address</label><input id="email" name="email" type="email" required placeholder="person@gmail.com"></div>
      <div><label for="role">Role</label><select id="role" name="role"><option value="user">User</option><option value="admin">Admin</option></select></div>
      <button class="primary" type="submit">Allow user</button>
    </form>
  </section>
  <section class="card">
    <h2>Current allow-list</h2>
    <table>
      <thead><tr><th>Email</th><th>Role</th><th>Status</th><th>Last login</th><th>Created</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?= auth_h($u['email']) ?><?= $u['name'] ? '<br><small>' . auth_h($u['name']) . '</small>' : '' ?></td>
          <td><span class="pill"><?= auth_h($u['role']) ?></span></td>
          <td><span class="pill <?= $u['status'] === 'disabled' ? 'disabled' : '' ?>"><?= auth_h($u['status']) ?></span></td>
          <td><?= auth_h($u['last_login_at'] ?: '—') ?></td>
          <td><?= auth_h($u['created_at']) ?></td>
          <td>
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf" value="<?= auth_h(auth_csrf_token()) ?>">
              <input type="hidden" name="email" value="<?= auth_h($u['email']) ?>">
              <input type="hidden" name="action" value="<?= $u['status'] === 'active' ? 'disable' : 'enable' ?>">
              <button type="submit"><?= $u['status'] === 'active' ? 'Disable' : 'Enable' ?></button>
            </form>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete <?= auth_h($u['email']) ?>?')">
              <input type="hidden" name="csrf" value="<?= auth_h(auth_csrf_token()) ?>">
              <input type="hidden" name="email" value="<?= auth_h($u['email']) ?>">
              <input type="hidden" name="action" value="delete">
              <button class="danger" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
</main>
</body>
</html>
