<?php
// Accept an admin invite, or use a password-reset link.
require __DIR__ . '/lib/bootstrap.php';
$t = $_GET['t'] ?? '';
if ($wait = lc_throttle_check('throttle-reset.json')) lc_fail("Too many tries. Wait $wait minutes.");
$tok = lc_find_token($t);
if (!$tok) {
    if (is_string($t) && $t !== '') lc_throttle_fail('throttle-reset.json', 10, 60);
    lc_fail('This link has expired or was already used. Ask the account owner to send a new one, or use "Forgot your password?" on the sign-in page.');
}
$isInvite = $tok['kind'] === 'invite';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($isInvite && post('name') === '') $err = 'Enter your name.';
    elseif (strlen(post('password')) < 10) $err = 'Use at least 10 characters.';
    elseif (post('password') !== post('confirm')) $err = 'The two passwords do not match.';
    else {
        $db = db();
        $db->beginTransaction();
        $db->prepare('UPDATE users SET hash = ?, pw_ver = pw_ver + 1, must_change = 0' . ($isInvite ? ', name = ?' : '') . ' WHERE id = ?')
           ->execute($isInvite ? [password_hash(post('password'), PASSWORD_DEFAULT), post('name'), $tok['user_id']]
                               : [password_hash(post('password'), PASSWORD_DEFAULT), $tok['user_id']]);
        $db->prepare('UPDATE tokens SET used_at = ? WHERE hash = ?')->execute([date('c'), $tok['hash']]);
        $db->commit();
        $st = $db->prepare('SELECT * FROM users WHERE id = ?'); $st->execute([$tok['user_id']]);
        lc_sign_in($st->fetch());
        flash($isInvite ? 'Welcome to the Loan Center.' : 'Password changed. You are signed in.');
        redirect('index.php');
    }
}
page_head(($isInvite ? 'Join' : 'New password') . ' · Loan Center');
?>
<main class="wrap narrow">
  <form method="post" class="card stack">
    <h1><span class="brand__mark">MB</span> <?= $isInvite ? 'Join the Loan Center' : 'Choose a new password' ?></h1>
    <p class="muted"><?= $isInvite ? 'You were invited as an admin. You\'ll sign in with ' : 'For ' ?><strong><?= h($tok['email']) ?></strong>.</p>
    <?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
    <?= csrf_field() ?>
    <?php if ($isInvite): ?><label>Your name<input name="name" required value="<?= h(post('name') ?: $tok['name']) ?>"></label><?php endif; ?>
    <label>Password (10+ characters)<input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
    <label>Repeat password<input type="password" name="confirm" required minlength="10" autocomplete="new-password"></label>
    <button class="btn"><?= $isInvite ? 'Create my account' : 'Save password' ?></button>
  </form>
</main>
<?php page_foot();
