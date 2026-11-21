#!/bin/sh
# Copy tools/edgelog-ship to /home/forge on every host of
# ~/.ssh/config.d/charly.conf (but charly-mail-encre.me), then check that it
# is executable there. Nothing else.

set -u

CONFIG="$HOME/.ssh/config.d/charly.conf"
SKIP="charly-mail-encre.me"
SHIPPER="$(dirname "$0")/edgelog-ship"
TARGET=/home/forge/edgelog-ship

hosts=$(awk 'tolower($1) == "host" { for (i = 2; i <= NF; i++) if ($i !~ /[*?!]/) print $i }' "$CONFIG" | grep -vxF "$SKIP")
failed=0

for host in $hosts; do
    if ! scp -p -o ConnectTimeout=10 "$SHIPPER" "$host:$TARGET"; then
        echo "FAIL  $host: scp"
        failed=1
    elif ssh -o ConnectTimeout=10 "$host" "test -x $TARGET"; then
        echo "OK    $host"
    else
        echo "FAIL  $host: $TARGET is not executable"
        failed=1
    fi
done

exit "$failed"
