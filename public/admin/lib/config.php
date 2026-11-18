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

// Emails (admin invites, password resets, borrower invites) come from this
// address. It must be on markbitton.com or Bluehost may refuse to send it.
const MAIL_FROM_ADDRESS = 'loans@markbitton.com';
const MAIL_FROM = 'Mark Bitton <' . MAIL_FROM_ADDRESS . '>';

date_default_timezone_set('America/Denver');

// Local testing only: write emails to a file instead of sending them.
if (getenv('LC_MAIL_LOG') && PHP_SAPI === 'cli-server') define('LC_MAIL_LOG', getenv('LC_MAIL_LOG'));
