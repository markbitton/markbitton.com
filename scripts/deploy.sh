#!/usr/bin/env bash
# Copies public/ to markbitton.com through the cPanel API (no SSH or FTP).
# Only files in public/ are uploaded or replaced; nothing on the server is
# deleted, and the Loan Center's data folder is never touched.
#
# Needs: CPANEL_HOST, CPANEL_TOKEN, CPANEL_USER, SITE_DOMAIN.
set -euo pipefail

: "${CPANEL_HOST:?}" "${CPANEL_TOKEN:?}" "${CPANEL_USER:?}" "${SITE_DOMAIN:?}"

# Secrets pasted into GitHub often pick up a trailing newline or spaces.
trim() { printf '%s' "$1" | tr -d '[:space:]'; }
CPANEL_HOST=$(trim "$CPANEL_HOST"); CPANEL_TOKEN=$(trim "$CPANEL_TOKEN"); CPANEL_USER=$(trim "$CPANEL_USER")
[ -n "$CPANEL_USER" ] || CPANEL_USER=axiompdc

host="${CPANEL_HOST#*://}"; host="${host%%/*}"
[[ "$host" == *:* ]] || host="$host:2083"
BASE="https://$host"
AUTH="Authorization: cpanel $CPANEL_USER:$CPANEL_TOKEN"

say() { echo "==> $*"; }
fail() { echo "::error::$*" >&2; exit 1; }

uapi() {
  local m=$1 f=$2; shift 2
  local out
  out=$(curl -sS --max-time 300 -H "$AUTH" "$BASE/execute/$m/$f" "$@") || fail "Could not reach cPanel at $host ($m::$f)."
  if [ "${SOFT:-0}" != 1 ] && [ "$(jq -r '.status // 0' <<<"$out" 2>/dev/null)" != 1 ]; then
    fail "cPanel $m::$f failed: $(jq -c '.errors // .' <<<"$out" 2>/dev/null || head -c 300 <<<"$out")"
  fi
  echo "$out"
}
api2() {
  local m=$1 f=$2; shift 2
  local out
  out=$(curl -sS --max-time 300 -H "$AUTH" "$BASE/json-api/cpanel" \
    --data-urlencode "cpanel_jsonapi_user=$CPANEL_USER" -d cpanel_jsonapi_apiversion=2 \
    -d "cpanel_jsonapi_module=$m" -d "cpanel_jsonapi_func=$f" "$@") || fail "Could not reach cPanel ($m::$f)."
  if [ "${SOFT:-0}" != 1 ] && [ "$(jq -r '.cpanelresult.event.result // 0' <<<"$out" 2>/dev/null)" != 1 ]; then
    fail "cPanel $m::$f failed: $(jq -c '.cpanelresult.error // .' <<<"$out" 2>/dev/null || head -c 300 <<<"$out")"
  fi
  echo "$out"
}

say "Checking the cPanel login"
HOMEDIR=$(SOFT=1 uapi Variables get_user_information | jq -r '.data.home // empty')
HOMEDIR="${HOMEDIR:-/home/$CPANEL_USER}"

say "Finding the folder for $SITE_DOMAIN"
data=$(uapi DomainInfo domains_data)
DOCROOT=$(jq -r --arg d "$SITE_DOMAIN" '
  [.data.main_domain] + (.data.addon_domains // []) + (.data.sub_domains // []) + (.data.parked_domains // [])
  | map(select(type == "object" and (.domain == $d or .domain == ("www." + $d)))) | .[0].documentroot // empty' <<<"$data")
if [ -z "$DOCROOT" ]; then
  # A parked domain shares the main domain's folder.
  if jq -e --arg d "$SITE_DOMAIN" '(.data.parked_domains // []) | map(if type == "object" then .domain else . end) | index($d)' <<<"$data" >/dev/null; then
    DOCROOT=$(jq -r '.data.main_domain.documentroot' <<<"$data")
  fi
fi
[ -n "$DOCROOT" ] || fail "$SITE_DOMAIN is not on the cPanel account '$CPANEL_USER'. Set the CPANEL_USER secret to the account that hosts it."
say "Site folder: $DOCROOT"

say "Making sure the private data folder exists (outside the website)"
if SOFT=1 uapi Fileman list_files -G --data-urlencode "dir=$HOMEDIR" | jq -e '.data[]? | select(.file == "loan-center-data")' >/dev/null; then
  say "loan-center-data already exists"
else
  api2 Fileman mkdir --data-urlencode "path=$HOMEDIR" -d name=loan-center-data -d permissions=0700 >/dev/null
fi

# First password for the Loan Center. Only a bcrypt hash of a long random
# password is stored here; it is written once, only if no password exists yet,
# and is changed from the Loan Center's Password page after first sign-in.
INITIAL_HASH='$2y$12$ZBGNatLwv4/rkHQAfVGCCOhui4TjEt4BqWflQBKi71RsNY3QiL8N2'
if SOFT=1 uapi Fileman list_files -G --data-urlencode "dir=$HOMEDIR/loan-center-data" | jq -e '.data[]? | select(.file == "auth.json")' >/dev/null; then
  say "Loan Center password already set; leaving it alone"
else
  say "Setting the Loan Center's first password"
  uapi Fileman save_file_content --data-urlencode "dir=$HOMEDIR/loan-center-data" --data-urlencode "file=auth.json" \
    --data-urlencode "content={\"hash\":\"$INITIAL_HASH\",\"ver\":1,\"initial\":true}" >/dev/null
fi

say "Packing public/"
ZIP="$(mktemp -d)/release.zip"
(cd public && zip -qr9 "$ZIP" . -x '*.sqlite' 'admin/data/*.json')
ls -lh "$ZIP"

say "Uploading and unpacking"
uapi Fileman upload_files -F "dir=$DOCROOT" -F overwrite=1 -F "file-1=@$ZIP;filename=release-${GITHUB_RUN_ID:-manual}.zip" >/dev/null
api2 Fileman fileop -d op=extract --data-urlencode "sourcefiles=$DOCROOT/release-${GITHUB_RUN_ID:-manual}.zip" --data-urlencode "destfiles=$DOCROOT" >/dev/null
api2 Fileman fileop -d op=unlink --data-urlencode "sourcefiles=$DOCROOT/release-${GITHUB_RUN_ID:-manual}.zip" >/dev/null

say "Turning on HTTPS (if it isn't already)"
SOFT=1 uapi SSL start_autossl_check >/dev/null || true

say "Checking the live site"
code() { curl -s -o /dev/null -w '%{http_code}' --max-time 30 "$1" || echo 000; }
c=$(code "https://$SITE_DOMAIN/admin/login.php")
echo "admin/login.php -> $c"
case "$c" in 200|302|303) ;; *) fail "The Loan Center didn't answer as expected (HTTP $c)." ;; esac
for p in admin/lib/config.php admin/data/loans.sqlite admin/data/auth.json; do
  c=$(code "https://$SITE_DOMAIN/$p")
  echo "$p -> $c"
  case "$c" in 403|404) ;; 200)
    body=$(curl -s --max-time 30 "https://$SITE_DOMAIN/$p" | head -c 200)
    [ -z "$body" ] || fail "https://$SITE_DOMAIN/$p is readable from the web. Check that .htaccess files are allowed." ;;
  *) ;; esac
done
say "Done: https://$SITE_DOMAIN/admin/"
