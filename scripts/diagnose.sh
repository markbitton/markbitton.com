#!/usr/bin/env bash
# Copies public/ to markbitton.com through the cPanel API (no SSH or FTP).
# Only files in public/ are uploaded or replaced; nothing on the server is
# deleted, and the Loan Center's data folder is never touched.
#
# Needs: CPANEL_HOST, CPANEL_TOKEN, CPANEL_USER, SITE_DOMAIN.
set -euo pipefail

say() { echo "==> $*"; }
fail() { echo "::error::$*" >&2; exit 1; }

: "${CPANEL_HOST:?}" "${CPANEL_TOKEN:?}" "${CPANEL_USER:?}" "${SITE_DOMAIN:?}"

# Secrets pasted into GitHub often pick up a trailing newline or spaces.
trim() { printf '%s' "$1" | tr -d '[:space:]'; }
CPANEL_HOST=$(trim "$CPANEL_HOST"); CPANEL_TOKEN=$(trim "$CPANEL_TOKEN"); CPANEL_USER=$(trim "$CPANEL_USER")
[ -n "$CPANEL_USER" ] || CPANEL_USER=axiompdc

host="${CPANEL_HOST#*://}"; host="${host%%/*}"
[[ "$host" == *:* ]] || host="$host:2083"

# If the CPANEL_HOST secret doesn't resolve, find the hosting server from the
# site's own DNS (Bluehost's reverse DNS names the server, e.g. box1234.bluehost.com).
if ! getent hosts "${host%%:*}" >/dev/null 2>&1; then
  h="${host%%:*}"
  echo "::warning::CPANEL_HOST doesn't resolve (${#h} characters, $( [[ "$h" == *.* ]] && echo 'has dots' || echo 'no dots')). Looking up the server from $SITE_DOMAIN instead."
  ip=$(getent ahostsv4 "$SITE_DOMAIN" | awk 'NR==1{print $1}')
  # Ask cPanel's own certificate on port 2083 which server name it answers to.
  rdns=""
  names=$(echo | timeout 15 openssl s_client -connect "$ip:2083" -servername "$SITE_DOMAIN" 2>/dev/null \
    | openssl x509 -noout -ext subjectAltName -subject 2>/dev/null | grep -oE '[A-Za-z0-9*.-]+\.[A-Za-z]{2,}' | grep -v '^\*' | sort -u || true)
  for n in $names; do
    if curl -s -o /dev/null --max-time 15 "https://$n:2083/"; then rdns=$n; break; fi
  done
  if [ -n "$rdns" ]; then
    host="$rdns:2083"
    echo "Using the server that hosts $SITE_DOMAIN: $rdns"
  else
    fail "CPANEL_HOST doesn't resolve and the server couldn't be found from $SITE_DOMAIN (IP ${ip:-none}). Set CPANEL_HOST to the cPanel address, e.g. box1234.bluehost.com."
  fi
fi
BASE="https://$host"
AUTH="Authorization: cpanel $CPANEL_USER:$CPANEL_TOKEN"

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


# ---- Read-only health check (run by the "diagnose" workflow). The repo is
# public, so its log is too: print only redacted, non-personal details.
redact() { sed -E 's/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+/[email]/g; s/\b[0-9]{1,3}(\.[0-9]{1,3}){3}\b/[ip]/g; s#/home[0-9]*/[^/ ]+#~#g; s/[a-f0-9]{32,}/[hex]/g'; }
say "Private data folder"
SOFT=1 uapi Fileman list_files -G --data-urlencode "dir=$HOMEDIR/loan-center-data" | jq -r '.data[]? | "  \(.file)  \(.type)  perms=\(.nicemode // .mode)"' | grep -v '^  sess_' || true
echo "  session files: $(SOFT=1 uapi Fileman list_files -G --data-urlencode "dir=$HOMEDIR/loan-center-data/sessions" | jq '[.data[]?] | length')"
for d in "$DOCROOT" "$DOCROOT/admin" "$DOCROOT/loan" "$HOMEDIR/loan-center-data"; do
  for f in error_log php_errorlog; do
    r=$(SOFT=1 uapi Fileman get_file_content -G --data-urlencode "dir=$d" --data-urlencode "file=$f")
    if [ "$(jq -r '.status' <<<"$r")" = 1 ]; then
      say "Last lines of ${d/#$HOMEDIR/~}/$f"
      jq -r '.data.content' <<<"$r" | tail -n 40 | redact
    fi
  done
done
say "Live probes"
probe() { curl -s -o /tmp/body -w '%{http_code} %{redirect_url}' --max-time 30 "$@"; echo; head -c 300 /tmp/body | tr '\n' ' ' | redact; echo; }
echo "-- GET admin/admins.php"; probe "https://$SITE_DOMAIN/admin/admins.php"
echo "-- POST admin/admins.php (invite form shape, not signed in)"; probe -X POST -H "Origin: https://$SITE_DOMAIN" -H "Referer: https://$SITE_DOMAIN/admin/admins.php" \
  --data-urlencode action=invite --data-urlencode name='Test Person' --data-urlencode email=test@example.com --data-urlencode csrf=abc "https://$SITE_DOMAIN/admin/admins.php"
echo "-- POST admin/login.php (wrong password)"; probe -X POST -H "Origin: https://$SITE_DOMAIN" --data-urlencode email=nobody@example.com --data-urlencode password=x "https://$SITE_DOMAIN/admin/login.php"
echo "-- headers"; curl -sI --max-time 30 "https://$SITE_DOMAIN/admin/login.php" | grep -iE '^(HTTP|server|set-cookie|x-|cache|via|cf-)' | sed -E 's/=[^;]+;/=[value];/'
exit 0
