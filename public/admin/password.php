<?php
require __DIR__ . '/lib/bootstrap.php';
require_login();
$me = current_user();
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (post('action') === 'profile') {
        $email = post('email');
        if (post('name') === '') $err = 'Enter your name.';
        elseif (!lc_valid_email($email)) $err = 'Enter a valid email address.';
        else {
            $st = db()->prepare('SELECT id FROM users WHERE lower(email) = lower(?) AND id <> ?');
            $st->execute([$email, $me['id']]);
            if ($st->fetch()) $err = 'Another admin already uses that email.';
            else {
                db()->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?')->execute([post('name'), $email, $me['id']]);
                flash('Saved. Sign in with ' . $email . ' from now on.');
                redirect('password.php');
            }
        }
    } else {
        if (!password_verify(post('current'), $me['hash'])) {
            $err = 'Your current password is not right.';
        } elseif (strlen(post('password')) < 10) {
            $err = 'Use at least 10 characters.';
        } elseif (post('password') !== post('confirm')) {
            $err = 'The two new passwords do not match.';
        } else {
            db()->prepare('UPDATE users SET hash = ?, pw_ver = pw_ver + 1, must_change = 0 WHERE id = ?')
                ->execute([password_hash(post('password'), PASSWORD_DEFAULT), $me['id']]);
            $st = db()->prepare('SELECT * FROM users WHERE id = ?'); $st->execute([$me['id']]);
            lc_sign_in($st->fetch());
            flash('Password changed. Any other signed-in devices were signed out.');
            redirect('index.php');
        }
    }
}
page_head('Account · Loan Center', true);
?>
<main class="wrap narrow narrow--top">
  <?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
  <form method="post" class="card stack">
    <h2>Your account</h2>
    <?php if (empty($me['email'])): ?><p class="flash flash--warn">Add your email. You'll sign in with it, and it's needed to reset a forgotten password.</p><?php endif; ?>
    <?= csrf_field() ?><input type="hidden" name="action" value="profile">
    <label>Name<input name="name" required value="<?= h(post('name') ?: $me['name']) ?>"></label>
    <label>Email<input type="email" name="email" required value="<?= h(post('email') ?: (string)$me['email']) ?>" autocomplete="email"></label>
    <button class="btn">Save</button>
  </form>
  <form method="post" class="card stack">
    <h2>Change password</h2>
    <?= csrf_field() ?><input type="hidden" name="action" value="password">
    <label>Current password<input type="password" name="current" required autocomplete="current-password"></label>
    <label>New password (10+ characters)<input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
    <label>Repeat new password<input type="password" name="confirm" required minlength="10" autocomplete="new-password"></label>
    <button class="btn">Change password</button>
  </form>
</main>
<?php page_foot();
