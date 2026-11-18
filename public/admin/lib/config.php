<?php
// ===== Loan Center settings =====

// Where the database and login files live. The deploy creates a folder named
// "loan-center-data" in the cPanel home folder (next to public_html, outside
// every website); if it exists it is used. Otherwise admin/data is used,
// which is locked by .htaccess.
function lc_data_dir() {
    $here = realpath(__DIR__) ?: __DIR__;
    if (preg_match('#^(/home\d*/[^/]+)/#', $here, $m)) {
        $outside = $m[1] . '/loan-center-data';
        if (is_dir($outside) && is_writable($outside)) return $outside;
    }
    return dirname(__DIR__) . '/data';
}

// Redirect plain http:// to https://. Leave true once SSL is on.
const FORCE_HTTPS = true;

// Sign out after this many minutes without activity.
const IDLE_MINUTES = 240;

date_default_timezone_set('America/Denver');
