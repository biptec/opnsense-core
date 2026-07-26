#!/bin/sh
set -eu

: "${OPNSENSE_API_KEY:?Set OPNSENSE_API_KEY}"
: "${OPNSENSE_API_SECRET:?Set OPNSENSE_API_SECRET}"
: "${OPNSENSE_VXLAN_TEST_UUID:?Set OPNSENSE_VXLAN_TEST_UUID}"

BASE_URL=${OPNSENSE_URL:-http://127.0.0.1}
TMPDIR=$(mktemp -d /tmp/vxlan-guardrails.XXXXXX)
ORIGINAL="$TMPDIR/original.json"
PAYLOAD="$TMPDIR/payload.json"
DUPLICATE_UUID=
ORIGINAL_DELETED=0

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

api_status() {
    method=$1
    path=$2
    output=$3
    curl -sS -u "$OPNSENSE_API_KEY:$OPNSENSE_API_SECRET" \
        -H 'Content-Type: application/json' -X "$method" -d '{}' \
        -o "$output" -w '%{http_code}' "$BASE_URL$path"
}

cleanup() {
    status=$?
    trap - EXIT HUP INT TERM
    set +e
    if [ -n "$DUPLICATE_UUID" ]; then
        api_request POST "/api/interfaces/vxlan_settings/del_item/$DUPLICATE_UUID" '{}' >/dev/null
    fi
    if [ "$ORIGINAL_DELETED" -eq 1 ]; then
        api_request POST '/api/interfaces/vxlan_settings/add_item' "$(cat "$PAYLOAD")" >/dev/null
    fi
    rm -rf "$TMPDIR"
    exit "$status"
}
trap cleanup EXIT HUP INT TERM

api_request GET "/api/interfaces/vxlan_settings/get_item/$OPNSENSE_VXLAN_TEST_UUID" > "$ORIGINAL"
jq -e '.vxlan.deviceId and .vxlan.vxlanid and .vxlan.vxlanlocal' "$ORIGINAL" >/dev/null

DEVICE="vxlan$(jq -r '.vxlan.deviceId' "$ORIGINAL")"
api_request GET '/api/interfaces/assignment/search_item' > "$TMPDIR/assignments.json"
jq -e --arg device "$DEVICE" '.rows | any(.if == $device)' \
    "$TMPDIR/assignments.json" >/dev/null || {
        echo "$DEVICE is not assigned; refusing destructive guard test" >&2
        exit 1
    }

jq -c '{vxlan: {
    vxlanid: .vxlan.vxlanid,
    vxlanlocal: .vxlan.vxlanlocal,
    vxlanlocalport: .vxlan.vxlanlocalport,
    vxlanremote: .vxlan.vxlanremote,
    vxlanremoteport: .vxlan.vxlanremoteport,
    vxlangroup: .vxlan.vxlangroup,
    vxlandev: (.vxlan.vxlandev | to_entries[]? | select(.value.selected == 1) | .key) // ""
}}' "$ORIGINAL" > "$PAYLOAD"

api_request POST '/api/interfaces/vxlan_settings/add_item' "$(cat "$PAYLOAD")" \
    > "$TMPDIR/duplicate.json"
if ! jq -e '.result == "failed" and (.validations | length > 0)' \
    "$TMPDIR/duplicate.json" >/dev/null; then
    DUPLICATE_UUID=$(jq -r '.uuid // empty' "$TMPDIR/duplicate.json")
    echo "Exact duplicate VXLAN was accepted" >&2
    cat "$TMPDIR/duplicate.json" >&2
    exit 1
fi
echo "Duplicate configuration validation: PASS"

HTTP_STATUS=$(api_status POST \
    "/api/interfaces/vxlan_settings/del_item/$OPNSENSE_VXLAN_TEST_UUID" \
    "$TMPDIR/delete.json")
case "$HTTP_STATUS" in
    2??)
        ORIGINAL_DELETED=1
        echo "Assigned VXLAN was deleted" >&2
        cat "$TMPDIR/delete.json" >&2
        exit 1
        ;;
esac

api_request GET "/api/interfaces/vxlan_settings/get_item/$OPNSENSE_VXLAN_TEST_UUID" \
    > "$TMPDIR/read-back.json"
jq -e --arg device_id "$(jq -r '.vxlan.deviceId' "$ORIGINAL")" \
    '.vxlan.deviceId == $device_id' "$TMPDIR/read-back.json" >/dev/null
echo "Assigned VXLAN deletion guard: PASS"
echo "All VXLAN guardrail API tests passed"
