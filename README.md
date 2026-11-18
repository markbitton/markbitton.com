# markbitton.com

Code for markbitton.com that is deployed from GitHub. Every push to `main` is tested and copied to the site through the cPanel API by the **deploy** GitHub Action (no SSH, FTP or local computer needed).

Today this repo holds:

- `public/admin/`: the **Loan Center**, a private, password-protected loan tracker at https://markbitton.com/admin/
- `public/loan/`: the read-only **borrower view**, opened with a private link per loan

The rest of the site (index.html, css, js, contact.php) is still managed by hand on the server. The deploy only adds or replaces the files in `public/`; it never deletes anything on the server.

## Secrets (Settings → Secrets and variables → Actions)

| Secret | Value |
| --- | --- |
| `CPANEL_HOST` | cPanel address, e.g. `box1234.bluehost.com:2083` |
| `CPANEL_TOKEN` | cPanel → Security → Manage API Tokens |
| `CPANEL_USER` | cPanel username (defaults to `axiompdc` if not set) |

Until `CPANEL_HOST` and `CPANEL_TOKEN` are set, the action only runs the tests.

## First sign-in

The first deploy writes a starting password (only its bcrypt hash is in this repo; the password itself was given to Larry privately). Sign in at https://markbitton.com/admin/ and change it on the **Password** page; the Loan Center reminds you until you do. Deploys never overwrite an existing password.

Forgot the password? Delete `auth.json` from `~/loan-center-data` in cPanel File Manager and re-run the deploy action; the starting password comes back.

## Where the data lives

`~/loan-center-data/loans.sqlite` in the cPanel home folder, outside every website (the deploy creates the folder). Deploys never touch it. Back it up by downloading that file, or use **Export CSV** in the Loan Center.

## Interest

Default is **simple interest, Actual/365**. Each loan can use Actual/360, 30/360 or compound daily, and an optional higher default rate after maturity. Payments go to fees, then accrued interest, then principal.

## Borrower view

On a loan's page, **Create borrower link** makes a private, unguessable link (`/loan/?k=…`) showing that loan's balance, payoff (today or a future date) and payment history. It never shows your notes or contact info, and can't change anything. **Turn off link** or **Make a new link** stops the old link working immediately.

## Security

- Server-side login, bcrypt-hashed password, HTTP-only/Secure/SameSite=Strict session cookie scoped to `/admin/`, 4-hour idle sign-out.
- 5 wrong passwords lock that address out for 15 minutes; 25 in an hour lock all logins for 15 minutes. Bad borrower links are rate-limited too.
- CSRF tokens on every form, parameterized SQL, noindex and no-cache headers, `.htaccess` denies on `lib/` and `data/`.
- After each deploy the action checks that the Loan Center answers and that its private files are not readable from the web.

## Tests

`php tests/interest_test.php` checks the interest math (day counts, compounding, default rate, payment allocation, payoff).
