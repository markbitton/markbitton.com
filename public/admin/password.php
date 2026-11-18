<?php
require __DIR__ . '/lib/bootstrap.php';
require_login();
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = lc_json_read('auth.json');
    if (!password_verify(post('current'), $a['hash'])) {
        $err = 'Your current password is not right.';
    } elseif (strlen(post('password')) < 10) {
        $err = 'Use at least 10 characters.';
    } elseif (post('password') !== post('confirm')) {
        $err = 'The two new passwords do not match.';
    } else {
        lc_json_write('auth.json', ['hash' => password_hash(post('password'), PASSWORD_DEFAULT), 'ver' => ($a['ver'] ?? 1) + 1]);
        lc_sign_in();
        flash('Password changed. Any other signed-in devices were signed out.');
        redirect('index.php');
    }
}
page_head('Change password · Loan Center', true);
?>
<main class="wrap narrow">
  <form method="post" class="card stack">
    <h2>Change password</h2>
    <?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
    <?= csrf_field() ?>
    <label>Current password<input type="password" name="current" required autocomplete="current-password"></label>
    <label>New password (10+ characters)<input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
    <label>Repeat new password<input type="password" name="confirm" required minlength="10" autocomplete="new-password"></label>
    <button class="btn">Change password</button>
  </form>
</main>
<?php page_foot();
