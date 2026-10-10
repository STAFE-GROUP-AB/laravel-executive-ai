#!/bin/zsh
# ADR-001 prototype: measure FrankenPHP cold start to first HTTP 200 on loopback.
#
# Usage:  ./measure-cold-start.sh [binary] [runs]
#   binary  defaults to ./bin/frankenphp-mac-arm64 (download it first, see README.md)
#   runs    number of cold starts to measure, default 5
#
# What it does, per run: records a wall-clock timestamp, spawns `frankenphp php-server`
# bound to 127.0.0.1 on a random high port serving ./www, polls GET / every 10 ms until
# it answers 200, records the delta, captures RSS of the server process, then SIGTERMs it
# and waits for the port to close. Output is a JSON array on stdout; progress on stderr.
#
# Nothing here is production code. It exists only so the numbers in
# docs/adrs/001-macos-runtime-packaging.md can be reproduced.

set -euo pipefail

HERE="${0:A:h}"
BIN="${1:-$HERE/bin/frankenphp-mac-arm64}"
RUNS="${2:-5}"
ROOT="$HERE/www"

if [[ ! -x "$BIN" ]]; then
    echo "binary not found or not executable: $BIN" >&2
    exit 1
fi

now_ms() { perl -MTime::HiRes=time -e 'printf("%.0f\n", time()*1000)'; }

echo "["
for i in $(seq 1 "$RUNS"); do
    PORT=$(( 18000 + RANDOM % 2000 ))
    URL="http://127.0.0.1:$PORT/"

    T0=$(now_ms)
    "$BIN" php-server --listen "127.0.0.1:$PORT" --root "$ROOT" >/dev/null 2>"$HERE/.last-server.log" &
    PID=$!

    CODE=000
    while [[ "$CODE" != "200" ]]; do
        CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 1 "$URL" || true)
        if (( $(now_ms) - T0 > 30000 )); then
            echo "timeout waiting for $URL" >&2
            kill "$PID" 2>/dev/null || true
            exit 2
        fi
        [[ "$CODE" == "200" ]] || sleep 0.01
    done
    T1=$(now_ms)

    BODY=$(curl -s "$URL")
    RSS_KB=$(ps -o rss= -p "$PID" | tr -d ' ')
    WARM_MS=$(curl -s -o /dev/null -w '%{time_total}' "$URL" | awk '{printf "%.1f", $1*1000}')

    kill -TERM "$PID"
    wait "$PID" 2>/dev/null || true
    T2=$(now_ms)

    SEP=","; (( i == RUNS )) && SEP=""
    printf '  {"run": %d, "port": %d, "cold_start_to_200_ms": %d, "shutdown_ms": %d, "rss_kb": %s, "warm_request_ms": %s, "body": %s}%s\n' \
        "$i" "$PORT" $(( T1 - T0 )) $(( T2 - T1 )) "${RSS_KB:-null}" "$WARM_MS" "$BODY" "$SEP"
    echo "run $i: cold start $(( T1 - T0 )) ms, rss ${RSS_KB} kB" >&2
done
echo "]"
