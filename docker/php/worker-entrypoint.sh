#!/bin/sh

set -u

child_pid=
shutdown_requested=0

forward_shutdown() {
    shutdown_requested=1

    if [ -n "$child_pid" ]; then
        kill -TERM "$child_pid" 2>/dev/null || true
        wait "$child_pid" 2>/dev/null || true
        child_pid=
    fi
}

run_child() {
    "$@" &
    child_pid=$!
    wait "$child_pid"
    status=$?
    child_pid=

    return "$status"
}

trap forward_shutdown TERM INT

while [ "$shutdown_requested" -eq 0 ]; do
    run_child php bin/console app:email-outbox:relay --no-interaction || true
    [ "$shutdown_requested" -eq 0 ] || break

    run_child php bin/console messenger:consume async --time-limit=5 --memory-limit=256M -vv || true
done

exit 0
