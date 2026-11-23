<?php
require_once __DIR__ . '/config/config.php';
if (current_user()) { header('Location: ' . url('index.php')); exit; }
$pageTitle = 'Login';
$error = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $stmt = db()->prepare('SELECT id,name,email,password_hash,role FROM users WHERE email=? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = ['id'=>(int)$user['id'], 'name'=>$user['name'], 'email'=>$user['email'], 'role'=>$user['role']];
        flash('Welcome back, ' . $user['name'] . '!');
        header('Location: ' . url('index.php')); exit;
    }
    $error = 'Invalid email or password.';
}
require __DIR__ . '/includes/header.php';
?>
<section class="form-card"><h1>Welcome back</h1><p class="muted">Sign in to your GoalLive account.</p>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
<label for="email">Email address</label><input class="field" id="email" type="email" name="email" required value="<?= e($email) ?>" autocomplete="username">
<label for="password">Password</label><input class="field" id="password" type="password" name="password" required autocomplete="current-password">
<button class="btn" type="submit">Sign in</button></form><p class="muted small">New here? <a href="<?= e(url('register.php')) ?>">Create an account</a></p></section>
<?php require __DIR__ . '/includes/footer.php'; ?>
