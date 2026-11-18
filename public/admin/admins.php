<?php
require __DIR__ . '/lib/bootstrap.php';
require_owner();
$me = current_user();
$err = '';
$newLink = null;

function invite_email($u, $link, $from) {
    return lc_mail($u['email'], 'You\'re invited to the markbitton.com Loan Center',
        "Hi {$u['name']},\n\n{$from} added you as an admin of the markbitton.com Loan Center. Open this link within 7 days to choose your password:\n\n"
        . $link . "\n\nAfter that, sign in any time at " . site_url('/admin/') . " with this email address.\n", $GLOBALS['me']['email'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = post('action');
    $id = (int)post('user_id');
    $st = db()->prepare('SELECT * FROM users WHERE id = ?'); $st->execute([$id]);
    $target = $st->fetch();

    if ($a === 'invite') {
        $email = post('email'); $name = post('name');
        if ($name === '') $err = 'Enter their name.';
        elseif (!lc_valid_email($email)) $err = 'Enter a valid email address.';
        else {
            $st = db()->prepare('SELECT * FROM users WHERE lower(email) = lower(?)'); $st->execute([$email]);
            if ($ex = $st->fetch()) {
                if ($ex['active']) $err = $email . ' is already an admin.';
                else {
                    db()->prepare('UPDATE users SET active = 1, name = ?, hash = NULL, pw_ver = pw_ver + 1 WHERE id = ?')->execute([$name, $ex['id']]);
                    $target = array_merge($ex, ['name' => $name]);
                }
            } else {
                db()->prepare('INSERT INTO users (email, name, hash, is_owner, created_at) VALUES (?, ?, NULL, 0, ?)')->execute([$email, $name, now_stamp()]);
                $target = ['id' => db()->lastInsertId(), 'email' => $email, 'name' => $name];
            }
            if (!$err) {
                $link = site_url('/admin/invite.php?t=' . lc_make_token($target['id'], 'invite', 24 * 7));
                $ok = invite_email($target, $link, $me['name'] ?: 'Mark');
                flash($ok ? "Invite emailed to $email. You can also copy the link below and send it yourself." : "Couldn't send the email. Copy the link below and send it to $email yourself.", $ok ? 'ok' : 'warn');
                $_SESSION['last_link'] = [$email, $link];
                redirect('admins.php');
            }
        }
    } elseif ($target && !$target['is_owner']) {
        if ($a === 'resend') {
            $kind = $target['hash'] ? 'reset' : 'invite';
            $link = site_url('/admin/invite.php?t=' . lc_make_token($target['id'], $kind, $kind === 'invite' ? 24 * 7 : 24));
            $ok = $kind === 'invite' ? invite_email($target, $link, $me['name'] ?: 'Mark')
                : lc_mail($target['email'], 'Reset your Loan Center password', "Hi {$target['name']},\n\nUse this link within 24 hours to choose a new password for the markbitton.com Loan Center:\n\n$link\n");
            flash(($ok ? 'Sent to ' . $target['email'] . '.' : "Couldn't send the email.") . ' The link is below if you want to send it yourself.', $ok ? 'ok' : 'warn');
            $_SESSION['last_link'] = [$target['email'], $link];
            redirect('admins.php');
        } elseif ($a === 'remove') {
            db()->prepare('UPDATE users SET active = 0, pw_ver = pw_ver + 1 WHERE id = ?')->execute([$target['id']]);
            db()->prepare('DELETE FROM tokens WHERE user_id = ?')->execute([$target['id']]);
            flash($target['name'] . ' no longer has access. They were signed out everywhere.');
            redirect('admins.php');
        }
    }
}
if (!empty($_SESSION['last_link'])) { $newLink = $_SESSION['last_link']; unset($_SESSION['last_link']); }
$users = db()->query('SELECT * FROM users WHERE active = 1 ORDER BY is_owner DESC, name')->fetchAll();

page_head('Admins · Loan Center', true);
?>
<main class="wrap narrow narrow--top">
  <?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
  <?php if ($newLink): ?>
    <div class="card share">
      <p class="small muted">Link for <?= h($newLink[0]) ?>:</p>
      <div class="sharerow"><input readonly value="<?= h($newLink[1]) ?>" data-copy><button type="button" class="btn btn--sm" data-copy-btn>Copy link</button></div>
    </div>
  <?php endif; ?>
  <div class="card">
    <h2>Admins</h2>
    <p class="muted small">Admins can see and change every loan. Only you (the owner) can add or remove admins.</p>
    <table class="admins">
      <?php foreach ($users as $u): ?>
      <tr>
        <td><strong><?= h($u['name'] ?: '—') ?></strong><?= $u['is_owner'] ? ' <span class="tag tag--share">Owner</span>' : '' ?><br>
          <small class="muted"><?= h($u['email'] ?: 'no email yet') ?><?= !$u['hash'] ? ' · invite not accepted yet' : ($u['last_login'] ? ' · last sign-in ' . h(nice_date(substr($u['last_login'], 0, 10))) : '') ?></small></td>
        <td class="r">
          <?php if (!$u['is_owner']): ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="action" value="resend">
            <button class="link"><?= $u['hash'] ? 'Send reset link' : 'Resend invite' ?></button></form>
          &nbsp;
          <form method="post" class="inline" data-confirm="Remove <?= h($u['name']) ?>? They'll be signed out right away."><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="action" value="remove">
            <button class="link danger">Remove</button></form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <form method="post" class="card stack">
    <h3>Invite an admin</h3>
    <?= csrf_field() ?><input type="hidden" name="action" value="invite">
    <div class="grid g2">
      <label>Name<input name="name" required value="<?= h(post('name')) ?>"></label>
      <label>Email<input type="email" name="email" required value="<?= h(post('email')) ?>"></label>
    </div>
    <div><button class="btn">Send invite</button></div>
    <p class="muted small">They get an email with a link to set their password. The link works once and expires in 7 days.</p>
  </form>
</main>
<?php page_foot();
