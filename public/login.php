<?php
session_start();
require_once '../private/auth.php';

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$signup_username = $signup_username ?? '';
$signup_email = $signup_email ?? '';
$login_username = $login_username ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cookventory Login</title>
    <link rel="stylesheet" href="assets/CSS/style.css?v=20260311b">
</head>
<body>
<main class="cv-page cv-page--auth">
  <section class="login-page">
    <article class="cv-card login-form-card">
      <h2>Login</h2>

      <?php if (!empty($errors)): ?>
        <div class="cv-alert cv-alert--error cv-stack-sm">
          <?php foreach ($errors as $error): ?>
            <p><?php echo htmlspecialchars($error); ?></p>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="" novalidate class="cv-form-grid">
        <div class="cv-field">
          <label for="login_username">Username</label>
          <input id="login_username" type="text" name="login_username" value="<?php echo htmlspecialchars($login_username); ?>" required minlength="3">
        </div>

        <div class="cv-field">
          <label for="login_password">Password</label>
          <input id="login_password" type="password" name="login_password" required minlength="6">
        </div>

        <button type="submit" name="login">Login</button>
      </form>
    </article>

    <article class="cv-card login-form-card">
      <h2>Sign Up</h2>
      <p class="cv-page-subtitle">Create an account to save your pantry and personalize recipe recommendations.</p>
      <hr class="login-divider">

      <form method="POST" action="" novalidate class="cv-form-grid login-signup-form">
        <div class="cv-field">
          <label for="signup_username">Username</label>
          <input id="signup_username" type="text" name="signup_username" value="<?php echo htmlspecialchars($signup_username); ?>" required minlength="3" maxlength="25">
        </div>

        <div class="cv-field">
          <label for="signup_email">Email</label>
          <input id="signup_email" type="email" name="signup_email" value="<?php echo htmlspecialchars($signup_email); ?>" required maxlength="255">
        </div>

        <div class="cv-field">
          <label for="signup_password">Password</label>
          <input id="signup_password" type="password" name="signup_password" required minlength="6">
        </div>

        <div class="cv-field">
          <label for="confirm_signup_password">Confirm Password</label>
          <input id="confirm_signup_password" type="password" name="confirm_signup_password" required minlength="6">
        </div>

        <button type="submit" name="signup">Sign Up</button>
      </form>
    </article>
  </section>
</main>
<script src="assets/JS/script.js?v=20260311b"></script>
</body>
</html>

