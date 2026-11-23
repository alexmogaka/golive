<?php
require_once __DIR__ . '/config/config.php';
if (current_user()) { header('Location: ' . url('index.php')); exit; }
$pageTitle = 'Create account';
$error = '';
$name = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim((string)($_POST['name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    if ($name === '' || mb_strlen($name) > 100) $error = 'Enter your name (up to 100 characters).';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Enter a valid email address.';
    elseif (strlen($password) < 10) $error = 'Use a password with at least 10 characters.';
    else {
        try {
            $stmt = db()->prepare('INSERT INTO users (name,email,password_hash) VALUES (?,?,?)');
            $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            flash('Account created. You can now sign in.');
            header('Location: ' . url('login.php')); exit;
        } catch (PDOException $e) {
            $error = $e->getCode() === '23000' ? 'That email address already has an account.' : 'Could not create your account. Check the database setup.';
        }
    }
}
require __DIR__ . '/includes/header.php';
?>
<section class="form-card"><h1>Create your GoalLive account</h1><p class="muted">Register to personalize your football experience.</p>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
<label for="name">Full name</label><input class="field" id="name" name="name" required maxlength="100" value="<?= e($name) ?>">
<label for="email">Email address</label><input class="field" id="email" type="email" name="email" required maxlength="190" value="<?= e($email) ?>">
<label for="password">Password (minimum 10 characters)</label><input class="field" id="password" type="password" name="password" required minlength="10" autocomplete="new-password">
<button class="btn" type="submit">Create account</button>
</form><p class="muted small">Already registered? <a href="<?= e(url('login.php')) ?>">Sign in</a></p></section>
<?php require __DIR__ . '/includes/footer.php'; ?>
