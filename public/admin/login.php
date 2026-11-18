<?php
require __DIR__ . '/lib/bootstrap.php';
if (!lc_password_set()) redirect('setup.php');
if (lc_logged_in()) redirect('index.php');
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($wait = lc_throttle_check()) {
        $err = "Too many failed tries. Wait $wait minute" . ($wait === 1 ? '' : 's') . ' and try again.';
    } else {
        $a = lc_json_read('auth.json');
        if ($a && password_verify(post('password'), $a['hash'])) {
            lc_throttle_clear();
            if (password_needs_rehash($a['hash'], PASSWORD_DEFAULT)) {
                $a['hash'] = password_hash(post('password'), PASSWORD_DEFAULT);
                lc_json_write('auth.json', $a);
            }
            lc_sign_in();
            redirect('index.php');
        }
        lc_throttle_fail();
        usleep(400000);
        $err = 'Wrong password.';
    }
}
page_head('Sign in · Loan Center');
?>
<main class="wrap narrow">
  <form method="post" class="card stack">
    <h1><span class="brand__mark">MB</span> Loan Center</h1>
    <?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
    <?= csrf_field() ?>
    <input type="text" name="username" value="admin" autocomplete="username" hidden>
    <label>Password<input type="password" name="password" required autofocus autocomplete="current-password"></label>
    <button class="btn">Sign in</button>
  </form>
</main>
<?php page_foot();
