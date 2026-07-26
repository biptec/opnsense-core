#!/bin/sh
set -eu

SCRIPT=/usr/local/opnsense/scripts/auth/add_user.php
USERNAME=${OPNSENSE_TEST_USERNAME:-addusertest$$}
ORIGIN=${OPNSENSE_TEST_ORIGIN:-regression-test}
LOG=$(find /var/log/configd -type f -name 'configd_*.log' | sort | tail -n 1)
START_LINE=$(wc -l < "$LOG" | tr -d ' ')

cleanup() {
    status=$?
    trap - EXIT HUP INT TERM
    php -r '
        require_once("legacy_bindings.inc");
        $username = $argv[1];
        $config = OPNsense\Core\Config::getInstance();
        $config->lock();
        $model = new OPNsense\Auth\User();
        $changed = false;
        foreach ($model->user->iterateItems() as $uuid => $user) {
            if ((string)$user->name === $username) {
                $changed = $model->user->del($uuid) || $changed;
            }
        }
        if ($changed) {
            $model->serializeToConfig(false, true);
            $config->save();
        } else {
            $config->unlock();
        }
        configdp_run("auth sync user", [$username]);
    ' "$USERNAME" >/dev/null
    exit "$status"
}
trap cleanup EXIT HUP INT TERM

OUTPUT=$($SCRIPT -u "$USERNAME" -o "$ORIGIN")
printf '%s' "$OUTPUT" | jq -e --arg name "$USERNAME" \
    '.status == "ok" and .name == $name' >/dev/null

echo "User creation: PASS"

SCOPE=$(php -r '
    $xml = simplexml_load_file("/conf/config.xml");
    foreach ($xml->system->user as $user) {
        if ((string)$user->name === $argv[1]) {
            echo (string)$user->scope;
        }
    }
' "$USERNAME")
[ "$SCOPE" = "$ORIGIN" ]
echo "Origin option: PASS"

found=0
for unused in 1 2 3 4 5; do
    if tail -n +$((START_LINE + 1)) "$LOG" | grep -F "user $USERNAME changed" >/dev/null; then
        found=1
        break
    fi
    sleep 1
done
[ "$found" -eq 1 ]
echo "Backend event username: PASS"

DUPLICATE=$($SCRIPT -u "$USERNAME" -o "$ORIGIN")
printf '%s' "$DUPLICATE" | jq -e '.status == "failed" and (.messages | length > 0)' >/dev/null
echo "Duplicate validation: PASS"

echo "All add-user tests passed"
