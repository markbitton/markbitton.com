<?php
require __DIR__ . '/lib/bootstrap.php';
lc_session();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $_SESSION = [];
    session_regenerate_id(true);
    session_destroy();
}
redirect('login.php');
