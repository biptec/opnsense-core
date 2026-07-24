#!/bin/sh
set -eu

: "${OPNSENSE_API_KEY:?Set OPNSENSE_API_KEY}"
: "${OPNSENSE_API_SECRET:?Set OPNSENSE_API_SECRET}"

BASE_URL=${OPNSENSE_URL:-http://127.0.0.1}
IFACE=${OPNSENSE_TEST_INTERFACE:-lan}
TEST_MTU=${OPNSENSE_TEST_MTU:-1400}
TMPDIR=$(mktemp -d /tmp/interfaces-basic-api-test.XXXXXX)
ORIGINAL="$TMPDIR/original.json"

api_request() {
    method=$1
    path=$2
    data=${3-}
    if [ "$method" = GET ]; then
        curl -fsS -u "$OPNSENSE_API_KEY:$OPNSENSE_API_SECRET" "$BASE_URL$path"
    else
        curl -fsS -u "$OPNSENSE_API_KEY:$OPNSENSE_API_SECRET" \
            -H 'Content-Type: application/json' -X "$method" \
            -d "$data" "$BASE_URL$path"
    fi
}

assert_json() {
    file=$1
    expression=$2
    jq -e "$expression" "$file" >/dev/null || {
        echo "Assertion failed: $expression" >&2
        cat "$file" >&2
        exit 1
    }
}
api_request GET "/api/interfaces/assignment/get_item/$IFACE" > "$ORIGINAL"
DEVICE=$(jq -r '.interface.if | to_entries[] | select(.value.selected == 1) | .key' "$ORIGINAL")
[ -n "$DEVICE" ] || { echo "Assigned device not found" >&2; exit 1; }
ORIGINAL_RUNTIME_MTU=$(ifconfig "$DEVICE" | awk 'NR == 1 {for (i=1; i<=NF; i++) if ($i == "mtu") print $(i+1)}')

RESTORE_PAYLOAD=$(jq -c '{interface: {
    enable: .interface.enable,
    blockpriv: .interface.blockpriv,
    blockbogons: .interface.blockbogons,
    gateway_interface: .interface.gateway_interface,
    promisc: .interface.promisc,
    spoofmac: .interface.spoofmac,
    mtu: .interface.mtu,
    mss: .interface.mss
}}' "$ORIGINAL")

cleanup() {
    status=$?
    trap - EXIT HUP INT TERM
    set +e
    api_request POST "/api/interfaces/assignment/set_item/$IFACE" \
        "$RESTORE_PAYLOAD" > "$TMPDIR/restore-set.json"
    api_request POST '/api/interfaces/assignment/reconfigure' '{}' \
        > "$TMPDIR/restore-apply.json"
    if [ -n "$ORIGINAL_RUNTIME_MTU" ]; then
        ifconfig "$DEVICE" mtu "$ORIGINAL_RUNTIME_MTU" >/dev/null 2>&1
    fi
    rm -rf "$TMPDIR"
    exit "$status"
}
trap cleanup EXIT HUP INT TERM

INVALID_MAC=$(jq -nc '{interface:{spoofmac:"not-a-mac"}}')
api_request POST "/api/interfaces/assignment/set_item/$IFACE" "$INVALID_MAC" \
    > "$TMPDIR/invalid-mac.json"
assert_json "$TMPDIR/invalid-mac.json" \
    '.result == "failed" and (.validations | length > 0)'
echo "Invalid MAC validation: PASS"

INVALID_MTU=$(jq -nc '{interface:{mtu:"65536"}}')
api_request POST "/api/interfaces/assignment/set_item/$IFACE" "$INVALID_MTU" \
    > "$TMPDIR/invalid-mtu.json"
assert_json "$TMPDIR/invalid-mtu.json" \
    '.result == "failed" and (.validations | length > 0)'
echo "Invalid MTU validation: PASS"

ORIGINAL_BLOCK=$(jq -r '.interface.blockbogons' "$ORIGINAL")
[ "$ORIGINAL_BLOCK" = 1 ] && TEST_BLOCK=0 || TEST_BLOCK=1
ORIGINAL_MSS=$(jq -r '.interface.mss' "$ORIGINAL")
[ "$ORIGINAL_MSS" = 1300 ] && TEST_MSS=1400 || TEST_MSS=1300
FILTER_PAYLOAD=$(jq -nc --arg block "$TEST_BLOCK" --arg mss "$TEST_MSS" \
    '{interface:{blockbogons:$block,mss:$mss}}')
api_request POST "/api/interfaces/assignment/set_item/$IFACE" "$FILTER_PAYLOAD" \
    > "$TMPDIR/filter-set.json"
assert_json "$TMPDIR/filter-set.json" '.result == "saved"'
jq -e --arg iface "$IFACE" '.[$iface].pending_action == "filter"' \
    /tmp/.interfaces.todo >/dev/null
echo "Filter-only scheduling: PASS"

api_request POST '/api/interfaces/assignment/reconfigure' '{}' \
    > "$TMPDIR/filter-apply.json"
assert_json "$TMPDIR/filter-apply.json" '.status == "ok"'
[ ! -e /tmp/.interfaces.todo ]
echo "Filter-only apply: PASS"

MTU_PAYLOAD=$(jq -nc --arg mtu "$TEST_MTU" '{interface:{mtu:$mtu}}')
api_request POST "/api/interfaces/assignment/set_item/$IFACE" "$MTU_PAYLOAD" \
    > "$TMPDIR/mtu-set.json"
assert_json "$TMPDIR/mtu-set.json" '.result == "saved"'
jq -e --arg iface "$IFACE" '.[$iface].pending_action == "reconfigure"' \
    /tmp/.interfaces.todo >/dev/null

api_request POST '/api/interfaces/assignment/reconfigure' '{}' \
    > "$TMPDIR/mtu-apply.json"
assert_json "$TMPDIR/mtu-apply.json" '.status == "ok"'
sleep 2
ifconfig "$DEVICE" | grep -E "(^|[[:space:]])mtu $TEST_MTU([[:space:]]|$)" >/dev/null
echo "Runtime MTU apply: PASS"
api_request GET "/api/interfaces/assignment/get_item/$IFACE" \
    > "$TMPDIR/read-back.json"
assert_json "$TMPDIR/read-back.json" \
    ".interface.mtu == \"$TEST_MTU\" and .interface.mss == \"$TEST_MSS\""

config_value() {
    php -r '$xml=simplexml_load_file("/conf/config.xml"); echo (string)$xml->interfaces->{$argv[1]}->{$argv[2]};' \
        "$IFACE" "$1"
}
[ "$(config_value mtu)" = "$TEST_MTU" ]
[ "$(config_value mss)" = "$TEST_MSS" ]
[ "$(config_value blockbogons)" = "$TEST_BLOCK" ]
echo "API and config.xml read-back: PASS"
echo "All interface basic-settings API tests passed"
