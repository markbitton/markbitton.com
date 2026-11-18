<?php
require __DIR__ . '/lib/bootstrap.php';
if (lc_password_set()) redirect('login.php');
page_head('Loan Center');
?>
<main class="wrap narrow">
  <div class="card stack">
    <h1>Loan Center</h1>
    <p class="muted">Not set up yet. The first password is created by the next deploy from GitHub.</p>
  </div>
</main>
<?php page_foot();
