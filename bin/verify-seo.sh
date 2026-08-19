#!/usr/bin/env bash
#
# SEO/AEO verification matrix for a wp-headless site.
#
# Usage: verify-seo.sh [base_url] [post_path]
#   base_url   defaults to http://localhost:11001
#   post_path  a published post's pretty-permalink path (default /hello-qa-post/)
#
# Asserts the served head (meta + schema @graph), llms.txt, robots.txt,
# favicon exemption, and head cleanup. Exits non-zero on any failure.

set -u

BASE="${1:-http://localhost:11001}"
POST_PATH="${2:-/hello-qa-post/}"

PASS=0
FAIL=0

ok()   { PASS=$((PASS + 1)); printf 'ok   %s\n' "$1"; }
fail() { FAIL=$((FAIL + 1)); printf 'FAIL %s\n' "$1"; }

assert_contains() { # label haystack needle
	case "$2" in
		*"$3"*) ok "$1" ;;
		*) fail "$1 (missing: $3)" ;;
	esac
}

assert_not_contains() { # label haystack needle
	case "$2" in
		*"$3"*) fail "$1 (unexpected: $3)" ;;
		*) ok "$1" ;;
	esac
}

FRONT="$(curl -sL "$BASE/")"
POST="$(curl -sL "$BASE$POST_PATH")"

# ── Front page: canonical + og identity (the branch-order regression) ──
assert_contains     "front og:url is home"            "$FRONT" "property=\"og:url\" content=\"$BASE/\""
assert_not_contains "front canonical is not permalink" "$FRONT" "rel=\"canonical\" href=\"$BASE/front"
assert_contains     "front og:image present"           "$FRONT" "property=\"og:image\""

# ── Post: schema @graph with connected anchors ──
# The JSON contains no raw '<' (SeoHead escapes '</'), so stop at the first.
LD="$(printf '%s' "$POST" | tr '\n' ' ' | grep -o '<script type="application/ld+json">[^<]*' | head -1)"
assert_contains "post emits ld+json"        "$LD" '@graph'
assert_contains "post graph #organization"  "$LD" '#organization'
assert_contains "post graph #website"       "$LD" '#website'
assert_contains "post graph #webpage"       "$LD" '#webpage'
assert_contains "post graph #breadcrumb"    "$LD" '#breadcrumb'
assert_contains "post graph Article node"   "$LD" '"Article"'
assert_contains "post robots meta captured" "$POST" "name='robots'"

if command -v python3 >/dev/null 2>&1; then
	JSON="${LD#<script type=\"application/ld+json\">}"
	if printf '%s' "$JSON" | python3 -m json.tool >/dev/null 2>&1; then
		ok "post ld+json parses as JSON"
	else
		fail "post ld+json parses as JSON"
	fi
fi

# ── llms.txt ──
LLMS_HEAD="$(curl -sI "$BASE/llms.txt")"
LLMS_BODY="$(curl -s "$BASE/llms.txt")"
assert_contains "llms.txt is 200"        "$LLMS_HEAD" " 200"
assert_contains "llms.txt is text/plain" "$LLMS_HEAD" "text/plain"
case "$LLMS_BODY" in
	"# "*) ok "llms.txt starts with H1" ;;
	*) fail "llms.txt starts with H1" ;;
esac
assert_contains "llms.txt lists the post" "$LLMS_BODY" "$POST_PATH"

# ── favicon: core behavior (icon or redirect to it), not the SPA shell ──
# Requires flushed rewrites: core's favicon\.ico$ rule ships with them.
FAVICON_CT="$(curl -sL -o /dev/null -w '%{content_type}' "$BASE/favicon.ico")"
case "$FAVICON_CT" in
	*text/html*) fail "favicon.ico is not the SPA shell (got $FAVICON_CT)" ;;
	*) ok "favicon.ico is not the SPA shell ($FAVICON_CT)" ;;
esac

# ── robots.txt ──
ROBOTS="$(curl -s "$BASE/robots.txt")"
assert_contains "robots.txt has llms pointer" "$ROBOTS" "llms.txt"
assert_contains "robots.txt keeps Sitemap"    "$ROBOTS" "Sitemap:"

# ── head cleanup ──
assert_not_contains "no EditURI/RSD"    "$FRONT" "EditURI"
assert_not_contains "no wlwmanifest"    "$FRONT" "wlwmanifest"
assert_not_contains "no shortlink"      "$FRONT" "rel='shortlink'"
assert_not_contains "no generator meta" "$FRONT" "name=\"generator\""
assert_contains     "RSS feed stays"    "$FRONT" "application/rss+xml"

# ── sitemap still served by core ──
SITEMAP_CT="$(curl -s -o /dev/null -w '%{content_type}' "$BASE/wp-sitemap.xml")"
case "$SITEMAP_CT" in
	*xml*) ok "wp-sitemap.xml serves XML" ;;
	*) fail "wp-sitemap.xml serves XML (got $SITEMAP_CT)" ;;
esac

# ── HTTP cache policy + conditional requests (0.3.0) ─────────────────────────

FRONT_H="$(curl -sI "$BASE/")"
assert_contains "anon front is public"        "$FRONT_H" "public"
assert_contains "anon front has s-maxage"     "$FRONT_H" "s-maxage="
assert_contains "anon front varies on Cookie" "$FRONT_H" "Vary: Cookie"
assert_contains "anon front has ETag"         "$FRONT_H" "ETag:"

ETAG="$(printf '%s' "$FRONT_H" | grep -i '^etag:' | awk '{print $2}' | tr -d '\r')"
if [ -n "$ETAG" ]; then
	INM="$(curl -s -o /dev/null -w '%{http_code}' -H "If-None-Match: $ETAG" "$BASE/")"
	if [ "$INM" = "304" ]; then ok "If-None-Match revalidates to 304"; else fail "If-None-Match revalidates to 304 (got $INM)"; fi
fi

# Cookie NAME presence alone must force private — no real session needed.
AUTH_H="$(curl -sI -H "Cookie: wordpress_logged_in_x=1" "$BASE/")"
assert_contains     "cookie-carrying request is private" "$AUTH_H" "private"
assert_not_contains "cookie-carrying has no s-maxage"    "$AUTH_H" "s-maxage"

NF_H="$(curl -sI "$BASE/definitely-not-a-page-$(date +%s)/")"
assert_contains "404 has max-age=0" "$NF_H" "max-age=0"

# Asset conditional: pull a hashed asset URL out of the served shell.
ASSET="$(curl -s "$BASE/" | grep -oE '(/_wp-headless/assets/|/dist/assets/)[^"]*\.js' | head -1)"
if [ -n "$ASSET" ]; then
	A_H="$(curl -sI "$BASE$ASSET")"
	assert_contains "asset has ETag"          "$A_H" "ETag:"
	assert_contains "asset has Last-Modified" "$A_H" "Last-Modified:"
	A_ETAG="$(printf '%s' "$A_H" | grep -i '^etag:' | awk '{print $2}' | tr -d '\r')"
	if [ -n "$A_ETAG" ]; then
		A_INM="$(curl -s -o /dev/null -w '%{http_code}' -H "If-None-Match: $A_ETAG" "$BASE$ASSET")"
		if [ "$A_INM" = "304" ]; then ok "asset INM revalidates to 304"; else fail "asset INM revalidates to 304 (got $A_INM)"; fi
	fi
fi

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
