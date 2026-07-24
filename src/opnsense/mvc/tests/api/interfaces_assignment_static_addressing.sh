#!/bin/sh
set -eu

: "${OPNSENSE_API_KEY:?Set OPNSENSE_API_KEY}"
: "${OPNSENSE_API_SECRET:?Set OPNSENSE_API_SECRET}"

BASE_URL=${OPNSENSE_URL:-http://127.0.0.1}
IFACE=${OPNSENSE_TEST_INTERFACE:-lan}
TEST_IPV4=${OPNSENSE_TEST_IPV4:-192.0.2.1}
TEST_PREFIX4=${OPNSENSE_TEST_PREFIX4:-24}
TEST_IPV6=${OPNSENSE_TEST_IPV6:-2001:db8:55::1}
TEST_PREFIX6=${OPNSENSE_TEST_PREFIX6:-64}
TMPDIR=$(mktemp -d /tmp/interfaces-api-test.XXXXXX)
ORIGINAL="$TMPDIR/original.json"

api_request() {
    method=$1
    path=$2
    data=${3-}
    if [ "$method" = GET ]; then
        curl -fsS -u "$OPNSENSE_API_KEY:$OPNSENSE_API_SECRET" \
            "$BASE_URL$path"
    else
        curl -fsS -u "$OPNSENSE_API_KEY:$OPNSENSE_API_SECRET" \
            -H 'Content-Type: application/json' -X "$method" \
            -d "$data" "$BASE_URL$path"
    fi
}
assert_json() {
    file=$1
    expression=$2
    if ! jq -e "$expression" "$file" >/dev/null; then
        echo "Assertion failed: $expression" >&2
        cat "$file" >&2
        exit 1
    fi
}

api_request GET "/api/interfaces/assignment/get_item/$IFACE" > "$ORIGINAL"
DEVICE=$(jq -r '.interface.if | to_entries[] | select(.value.selected == 1) | .key' "$ORIGINAL")
[ -n "$DEVICE" ] || { echo "Assigned device not found" >&2; exit 1; }

RESTORE_PAYLOAD=$(jq -c '{interface: {
    ipaddr: .interface.ipaddr,
    subnet: .interface.subnet,
    gateway: .interface.gateway,
    ipaddrv6: .interface.ipaddrv6,
    subnetv6: .interface.subnetv6,
    gatewayv6: .interface.gatewayv6
}}' "$ORIGINAL")

cleanup() {
    status=$?
    trap - EXIT HUP INT TERM
    set +e
    restore_failed=0
    if ! api_request POST "/api/interfaces/assignment/set_item/$IFACE" \
        "$RESTORE_PAYLOAD" > "$TMPDIR/restore-set.json"; then
        restore_failed=1
    fi
    if ! api_request POST '/api/interfaces/assignment/reconfigure' \
        "$(jq -nc '{}')" > "$TMPDIR/restore-apply.json"; then
        restore_failed=1
    fi
    if [ "$restore_failed" -ne 0 ]; then
        echo "Rollback failed" >&2
        status=1
    else
        echo "Rollback completed"
    fi
    rm -rf "$TMPDIR"
    exit "$status"
}
trap cleanup EXIT HUP INT TERM

INVALID_PAYLOAD=$(jq -nc \
    --arg ipaddr '999.1.1.1' \
    --arg subnet '24' \
    '{interface:{ipaddr:$ipaddr,subnet:$subnet}}')
api_request POST "/api/interfaces/assignment/set_item/$IFACE" \
    "$INVALID_PAYLOAD" > "$TMPDIR/invalid.json"
assert_json "$TMPDIR/invalid.json" \
    '.result == "failed" and (.validations | length > 0)'
echo "Invalid IPv4 validation: PASS"

VALID_IPV6_PAYLOAD=$(jq -nc \
    --arg ip6 "$TEST_IPV6" --arg prefix6 "$TEST_PREFIX6" \
    '{interface:{ipaddrv6:$ip6,subnetv6:$prefix6,gatewayv6:""}}')
api_request POST "/api/interfaces/assignment/set_item/$IFACE" \
    "$VALID_IPV6_PAYLOAD" > "$TMPDIR/valid-set-v6.json"
assert_json "$TMPDIR/valid-set-v6.json" '.result == "saved"'

VALID_IPV4_PAYLOAD=$(jq -nc \
    --arg ip4 "$TEST_IPV4" --arg prefix4 "$TEST_PREFIX4" \
    '{interface:{ipaddr:$ip4,subnet:$prefix4,gateway:""}}')
api_request POST "/api/interfaces/assignment/set_item/$IFACE" \
    "$VALID_IPV4_PAYLOAD" > "$TMPDIR/valid-set-v4.json"
assert_json "$TMPDIR/valid-set-v4.json" '.result == "saved"'
echo "Sequential static address saves: PASS"

api_request GET "/api/interfaces/assignment/get_item/$IFACE" \
    > "$TMPDIR/valid-get.json"
assert_json "$TMPDIR/valid-get.json" \
    ".interface.ipaddr == \"$TEST_IPV4\" and .interface.subnet == \"$TEST_PREFIX4\""
assert_json "$TMPDIR/valid-get.json" \
    ".interface.ipaddrv6 == \"$TEST_IPV6\" and .interface.subnetv6 == \"$TEST_PREFIX6\""
echo "API read-back: PASS"

api_request POST '/api/interfaces/assignment/reconfigure' \
    "$(jq -nc '{}')" > "$TMPDIR/apply.json"
assert_json "$TMPDIR/apply.json" '.status == "ok"'
sleep 2
echo "Interface reconfigure: PASS"

config_value() {
    php -r '$xml=simplexml_load_file("/conf/config.xml"); echo (string)$xml->interfaces->{$argv[1]}->{$argv[2]};' \
        "$IFACE" "$1"
}

[ "$(config_value ipaddr)" = "$TEST_IPV4" ]
[ "$(config_value subnet)" = "$TEST_PREFIX4" ]
[ "$(config_value ipaddrv6)" = "$TEST_IPV6" ]
[ "$(config_value subnetv6)" = "$TEST_PREFIX6" ]
echo "config.xml persistence: PASS"

ifconfig "$DEVICE" | grep -F "inet $TEST_IPV4 " >/dev/null
ifconfig "$DEVICE" | grep -F "inet6 $TEST_IPV6 " >/dev/null
echo "Runtime interface state: PASS"
echo "All interface static-addressing API tests passed"
