<?php
require __DIR__ . '/lib/bootstrap.php';
lc_session(); // before any output, so the form's token is saved
if (!lc_password_set()) redirect('setup.php');
$sent = false; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($wait = lc_throttle_check('throttle-reset.json')) {
        $err = "Too many requests. Wait $wait minutes.";
    } else {
        lc_throttle_fail('throttle-reset.json', 5, 30); // every request counts, found or not
        $st = db()->prepare('SELECT * FROM users WHERE lower(email) = lower(?) AND active = 1');
        $st->execute([post('email')]);
        if ($u = $st->fetch()) {
            $t = lc_make_token($u['id'], 'reset', 2);
            lc_mail($u['email'], 'Reset your Loan Center password',
                "Hi {$u['name']},\n\nSomeone asked to reset your markbitton.com Loan Center password. To choose a new one, open this link within 2 hours:\n\n"
                . site_url('/admin/invite.php?t=' . $t) . "\n\nIf this wasn't you, ignore this email. Your password stays the same.\n");
        }
        $sent = true;
    }
}
page_head('Reset password · Loan Center');
?>
<main class="wrap narrow">
  <form method="post" class="card stack">
    <h1>Reset password</h1>
    <?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
    <?php if ($sent): ?>
      <p class="flash flash--ok">If that email belongs to an admin, a reset link is on its way. Check spam if it doesn't arrive in a few minutes.</p>
    <?php else: ?>
      <p class="muted">Enter your email and we'll send you a link to choose a new password.</p>
      <?= csrf_field() ?>
      <label>Email<input type="email" name="email" required autofocus></label>
      <button class="btn">Send reset link</button>
    <?php endif; ?>
    <p class="small muted"><a href="login.php">Back to sign in</a></p>
  </form>
</main>
<?php page_foot();
