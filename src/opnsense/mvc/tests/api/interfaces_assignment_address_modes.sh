#!/bin/sh
set -eu

: "${OPNSENSE_API_KEY:?Set OPNSENSE_API_KEY}"
: "${OPNSENSE_API_SECRET:?Set OPNSENSE_API_SECRET}"

BASE_URL=${OPNSENSE_URL:-http://127.0.0.1}
IFACE=${OPNSENSE_TEST_INTERFACE:-lan}
TMPDIR=$(mktemp -d /tmp/interfaces-mode-api-test.XXXXXX)
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
TYPE=$(jq -r '.interface.type | to_entries[] | select(.value.selected == 1) | .key' "$ORIGINAL")
TYPE6=$(jq -r '.interface.type6 | to_entries[] | select(.value.selected == 1) | .key' "$ORIGINAL")
ORIGINAL_IPV4=$(jq -r '.interface.ipaddr' "$ORIGINAL")
ORIGINAL_PREFIX4=$(jq -r '.interface.subnet' "$ORIGINAL")
[ -n "$DEVICE" ] || { echo "Assigned device not found" >&2; exit 1; }

RESTORE_PAYLOAD=$(jq -c --arg type "$TYPE" --arg type6 "$TYPE6" '{
    interface: {
        type: $type,
        type6: $type6,
        ipaddr: .interface.ipaddr,
        subnet: .interface.subnet,
        gateway: .interface.gateway,
        ipaddrv6: .interface.ipaddrv6,
        subnetv6: .interface.subnetv6,
        gatewayv6: .interface.gatewayv6,
        dhcphostname: .interface.dhcphostname,
        "alias-address": .interface["alias-address"],
        "alias-subnet": .interface["alias-subnet"],
        dhcprejectfrom: .interface.dhcprejectfrom,
        "dhcp6-ia-pd-len": .interface["dhcp6-ia-pd-len"],
        "dhcp6-ia-pd-send-hint": .interface["dhcp6-ia-pd-send-hint"],
        dhcp6prefixonly: .interface.dhcp6prefixonly,
        dhcp6usev4iface: .interface.dhcp6usev4iface,
        dhcp6vlanprio: .interface.dhcp6vlanprio,
        "track6-interface": .interface["track6-interface"],
        "track6-prefix-id": .interface["track6-prefix-id"],
        track6_assoc_pd: .interface.track6_assoc_pd
    }
}' "$ORIGINAL")

cleanup() {
    status=$?
    trap - EXIT HUP INT TERM
    set +e
    api_request POST "/api/interfaces/assignment/set_item/$IFACE" \
        "$RESTORE_PAYLOAD" > "$TMPDIR/restore-set.json"
    api_request POST '/api/interfaces/assignment/reconfigure' '{}' \
        > "$TMPDIR/restore-apply.json"
    rm -rf "$TMPDIR"
    exit "$status"
}
trap cleanup EXIT HUP INT TERM

UNSUPPORTED=$(jq -nc '{interface:{type:"pppoe"}}')
api_request POST "/api/interfaces/assignment/set_item/$IFACE" "$UNSUPPORTED" \
    > "$TMPDIR/unsupported.json"
assert_json "$TMPDIR/unsupported.json" \
    '.result == "failed" and (.validations | length > 0)'
echo "Unsupported mode validation: PASS"

INVALID_HOST=$(jq -nc '{interface:{type:"dhcp",dhcphostname:"bad host"}}')
api_request POST "/api/interfaces/assignment/set_item/$IFACE" "$INVALID_HOST" \
    > "$TMPDIR/invalid-host.json"
assert_json "$TMPDIR/invalid-host.json" \
    '.result == "failed" and (.validations | length > 0)'
echo "DHCP hostname validation: PASS"
DHCP_PAYLOAD=$(jq -nc '{interface:{type:"dhcp",dhcphostname:"biptec-api-test"}}')
api_request POST "/api/interfaces/assignment/set_item/$IFACE" "$DHCP_PAYLOAD" \
    > "$TMPDIR/dhcp-set.json"
assert_json "$TMPDIR/dhcp-set.json" '.result == "saved"'
jq -e --arg iface "$IFACE" \
    '.[$iface].pending_action == "reconfigure" and (.[$iface].pending_families | index(4) != null)' \
    /tmp/.interfaces.todo >/dev/null

echo "DHCP mode scheduling: PASS"
api_request POST '/api/interfaces/assignment/reconfigure' '{}' \
    > "$TMPDIR/dhcp-apply.json"
assert_json "$TMPDIR/dhcp-apply.json" '.status == "ok"'
sleep 2
CONFIG_MODE=$(php -r '$x=simplexml_load_file("/conf/config.xml"); echo (string)$x->interfaces->{$argv[1]}->ipaddr;' "$IFACE")
[ "$CONFIG_MODE" = dhcp ]
! ifconfig "$DEVICE" | grep -F "inet $ORIGINAL_IPV4 " >/dev/null
echo "Static to DHCP runtime transition: PASS"

api_request GET "/api/interfaces/assignment/get_item/$IFACE" \
    > "$TMPDIR/dhcp-get.json"
assert_json "$TMPDIR/dhcp-get.json" \
    '.interface.type | to_entries | any(.key == "dhcp" and .value.selected == 1)'
assert_json "$TMPDIR/dhcp-get.json" '.interface.dhcphostname == "biptec-api-test"'
echo "DHCP API read-back: PASS"

api_request POST "/api/interfaces/assignment/set_item/$IFACE" "$RESTORE_PAYLOAD" \
    > "$TMPDIR/static-set.json"
assert_json "$TMPDIR/static-set.json" '.result == "saved"'
api_request POST '/api/interfaces/assignment/reconfigure' '{}' \
    > "$TMPDIR/static-apply.json"
assert_json "$TMPDIR/static-apply.json" '.status == "ok"'
sleep 2
if [ "$TYPE" = static ]; then
    ifconfig "$DEVICE" | grep -F "inet $ORIGINAL_IPV4 " >/dev/null
    RESTORED_PREFIX=$(php -r '$x=simplexml_load_file("/conf/config.xml"); echo (string)$x->interfaces->{$argv[1]}->subnet;' "$IFACE")
    [ "$RESTORED_PREFIX" = "$ORIGINAL_PREFIX4" ]
fi
echo "Address mode rollback: PASS"
echo "All interface address-mode API tests passed"
