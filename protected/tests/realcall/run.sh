#!/bin/bash
# Trunk caps real-call tests (spec Phase 5). DEV VM ONLY, run as root:
#
#   php protected/tests/realcall/setup.php     # once, creates the tcrc-* records
#   bash protected/tests/realcall/run.sh [T1 T2 ...]   # all tests by default
#
# Real calls go: SIPp caller -> Asterisk/MagnusBilling (AGI, trunk caps)
# -> SIPp fake providers on 127.0.0.2:5091 (tcrc-a) and 127.0.0.3:5092 (tcrc-b).
# Every check reads the database (CDRs, cap counters, reservations, events).
# Only tcrc-* records are touched. Logs: /root/tcrc-results/<timestamp>/.

set -u
HERE=$(cd "$(dirname "$0")" && pwd)
MB=$(cd "$HERE/../../.." && pwd)
SCEN=$HERE/scenarios
OUT=/root/tcrc-results/$(date -u +%Y%m%d-%H%M%S)
mkdir -p "$OUT"
# shellcheck disable=SC1091
. /root/tcrc.env
PASSES=0; FAILS=0; PROVIDER_PIDS=()

sql() { mysql -N -B mbilling -e "$1"; }
say() { echo "[$(date -u +%H:%M:%S)] $*" | tee -a "$OUT/run.log"; }
check() { # check "description" actual expected
    if [ "$2" = "$3" ]; then say "  PASS  $1 ($2)"; PASSES=$((PASSES + 1));
    else say "  FAIL  $1: got '$2', expected '$3'"; FAILS=$((FAILS + 1)); fi
}
check_range() { # check_range "description" actual min max
    if [ "$2" -ge "$3" ] && [ "$2" -le "$4" ]; then say "  PASS  $1 ($2, allowed $3..$4)"; PASSES=$((PASSES + 1));
    else say "  FAIL  $1: got $2, allowed $3..$4"; FAILS=$((FAILS + 1)); fi
}

start_providers() {
    for spec in "a 127.0.0.2 5091" "b 127.0.0.3 5092"; do
        set -- $spec
        sipp -sf "$SCEN/uas_provider.xml" -i "$2" -p "$3" -aa -bg -nostdin -trace_logs -log_file "$OUT/provider-$1.log" \
            -trace_err -error_file "$OUT/provider-$1-err.log" >"$OUT/provider-$1.out" 2>&1
        PROVIDER_PIDS+=("$(grep -o 'PID=\[[0-9]*\]' "$OUT/provider-$1.out" | tr -dc 0-9)")
    done
    # Asterisk qualifies the trunks (OPTIONS, answered by -aa); wait until both are reachable.
    asterisk -rx "pjsip qualify tcrc-a" >/dev/null; asterisk -rx "pjsip qualify tcrc-b" >/dev/null
    for _ in $(seq 1 30); do
        [ "$(asterisk -rx 'pjsip show contacts' | grep -cE 'tcrc-[ab]/.*Avail')" -eq 2 ] && break; sleep 1
    done
    say "trunk contacts: $(asterisk -rx 'pjsip show contacts' | grep -E 'tcrc-[ab]/' | awk '{print $2, $4}' | tr '\n' ' ')"
}
stop_providers() { for p in "${PROVIDER_PIDS[@]}"; do kill "$p" 2>/dev/null; done; }
trap stop_providers EXIT

CALLER_PORT=5070
# calls NAME COUNT RATE_PER_S HOLD_MS [SCENARIO] [MAX_CONCURRENT] - runs and waits
calls() {
    local name=$1 count=$2 rate=$3 hold=$4 scen=${5:-uac_auth.xml} limit=${6:-$2}
    CALLER_PORT=$((CALLER_PORT + 1))
    sipp 127.0.0.1:5060 -sf "$SCEN/$scen" -s "${TCRC_PREFIX}$(printf '%07d' $RANDOM)" \
        -set user "$TCRC_SIP_USER" -au "$TCRC_SIP_USER" -ap "$TCRC_SIP_PASS" -i 127.0.0.1 -p "$CALLER_PORT" \
        -m "$count" -r "$rate" -l "$limit" -d "$hold" -timeout 1200 -bg -nostdin \
        -trace_logs -log_file "$OUT/$name.log" -trace_err -error_file "$OUT/$name-err.log" >"$OUT/$name.out" 2>&1
    local pid; pid=$(grep -o 'PID=\[[0-9]*\]' "$OUT/$name.out" | tr -dc 0-9)
    while kill -0 "$pid" 2>/dev/null; do sleep 1; done
    sleep 3 # let the AGI finish writing CDRs and commits
}
count_lines() { awk -v p="$1" 'index($0, p) == 1 { n++ } END { print n + 0 }' "$2" 2>/dev/null || echo 0; }
answered() { count_lines ANSWERED "$OUT/$1.log"; }

reset_caps() { # remove test policies (cascades usage/minute rows), reservations, events and old test CDRs
    sql "DELETE FROM pkg_trunk_cap_policy WHERE id_trunk IN ($TCRC_TRUNK_A, $TCRC_TRUNK_B);
         DELETE FROM pkg_trunk_cap_reservation WHERE id_trunk IN ($TCRC_TRUNK_A, $TCRC_TRUNK_B);
         DELETE FROM pkg_trunk_cap_event WHERE id_trunk IN ($TCRC_TRUNK_A, $TCRC_TRUNK_B);
         DELETE FROM pkg_trunk_cap_statement WHERE id_trunk IN ($TCRC_TRUNK_A, $TCRC_TRUNK_B);"
}
group() { # group A | AB
    sql "DELETE FROM pkg_trunk_group_trunk WHERE id_trunk_group = $TCRC_GROUP;
         INSERT INTO pkg_trunk_group_trunk (id_trunk_group, id_trunk) VALUES ($TCRC_GROUP, $TCRC_TRUNK_A);"
    [ "$1" = "AB" ] && sql "INSERT INTO pkg_trunk_group_trunk (id_trunk_group, id_trunk) VALUES ($TCRC_GROUP, $TCRC_TRUNK_B);"
}
policy() { # policy TRUNK_ID "col=val, col=val"
    sql "INSERT INTO pkg_trunk_cap_policy SET id_trunk = $1, name = 'tcrc', timezone = 'UTC', warn_pct = '', $2;"
}
since() { date -u -d "-$1 seconds" '+%Y-%m-%d %H:%M:%S'; }
cdr_count() { sql "SELECT COUNT(*) FROM pkg_cdr WHERE id_trunk = $1 AND starttime >= '$2'"; }
open_reservations() { sql "SELECT COUNT(*) FROM pkg_trunk_cap_reservation WHERE id_trunk IN ($TCRC_TRUNK_A, $TCRC_TRUNK_B) AND released_at IS NULL"; }

# CDR starttime is written in the PHP timezone; read "now" the same way.
php_now() { php -r 'echo date("Y-m-d H:i:s");'; }

T0() {
    say "T0 smoke: one 3 s call on tcrc-a, counted"
    reset_caps; group A; policy "$TCRC_TRUNK_A" "max_calls = 100, period_type = 'day'"
    local t; t=$(php_now)
    calls t0 1 1 3000
    check "answered" "$(answered t0)" 1
    check "CDR on tcrc-a" "$(cdr_count "$TCRC_TRUNK_A" "$t")" 1
    check "counter calls" "$(sql "SELECT calls FROM pkg_trunk_cap_usage u JOIN pkg_trunk_cap_policy p ON p.id = u.id_policy WHERE p.id_trunk = $TCRC_TRUNK_A")" 1
    check "no open reservations" "$(open_reservations)" 0
}

T1() {
    say "T1 concurrency: 100 simultaneous calls, tcrc-a limited to 10 concurrent, no failover"
    reset_caps; group A; policy "$TCRC_TRUNK_A" "max_concurrent = 10"
    local t; t=$(php_now)
    calls t1 100 100 20000
    check "answered" "$(answered t1)" 10
    check "CDRs on tcrc-a" "$(cdr_count "$TCRC_TRUNK_A" "$t")" 10
    # peak overlap of answered calls, from the CDRs themselves
    check_range "peak simultaneous calls on tcrc-a (CDR overlap)" "$(sql "SELECT COALESCE(MAX(n), 0) FROM (SELECT (SELECT COUNT(*) FROM pkg_cdr d
        WHERE d.id_trunk = c.id_trunk AND d.starttime >= '$t' AND d.starttime <= c.starttime
          AND d.starttime + INTERVAL d.sessiontime SECOND > c.starttime) n
        FROM pkg_cdr c WHERE c.id_trunk = $TCRC_TRUNK_A AND c.starttime >= '$t') x")" 1 10
    check "blocked events" "$(sql "SELECT COUNT(*) FROM pkg_trunk_cap_event WHERE id_trunk = $TCRC_TRUNK_A AND event = 'blocked'")" 90
    check "no open reservations" "$(open_reservations)" 0
}

T2() {
    say "T2 failover: tcrc-a capped at 5 calls/day, 8 calls -> 5 on tcrc-a, 3 on tcrc-b"
    reset_caps; group AB; policy "$TCRC_TRUNK_A" "max_calls = 5, period_type = 'day'"
    local t; t=$(php_now)
    calls t2 8 1 2000 uac_auth.xml 1
    check "answered" "$(answered t2)" 8
    check "CDRs on tcrc-a" "$(cdr_count "$TCRC_TRUNK_A" "$t")" 5
    check "CDRs on tcrc-b" "$(cdr_count "$TCRC_TRUNK_B" "$t")" 3
}

T3() {
    say "T3 all capped: both trunks at their cap -> call rejected, cause visible"
    # continues from T2's counters: tcrc-a has 5/5; cap tcrc-b at 3 (it has 3)
    policy "$TCRC_TRUNK_B" "max_calls = 3, period_type = 'day', on_limit = 'skip_trunk'"
    local t; t=$(php_now)
    calls t3 1 1 2000
    check "answered" "$(answered t3)" 0
    check "rejected (SIP final error)" "$(count_lines REJECTED "$OUT/t3.log")" 1
    check "no new CDR on either trunk" "$(( $(cdr_count "$TCRC_TRUNK_A" "$t") + $(cdr_count "$TCRC_TRUNK_B" "$t") ))" 0
    check "blocked events for that call (one per trunk)" "$(sql "SELECT COUNT(DISTINCT id_trunk) FROM pkg_trunk_cap_event WHERE event = 'blocked'
        AND uniqueid = (SELECT uniqueid FROM pkg_trunk_cap_event WHERE event = 'blocked' ORDER BY id DESC LIMIT 1)")" 2
    say "  info  caller saw: $(grep '^REJECTED' "$OUT/t3.log" | head -1 | cut -c1-120)"
}

T4() {
    say "T4 cut-off: tcrc-a has 2 minutes left, caller never hangs up -> network ends the call at ~120 s"
    reset_caps; group A; policy "$TCRC_TRUNK_A" "max_minutes = 2, period_type = 'month', reserve_mode = 'full'"
    local t; t=$(php_now)
    calls t4 1 1 0 uac_wait_bye.xml
    check "hung up by the network" "$(count_lines HUNG_UP_BY_NETWORK "$OUT/t4.log")" 1
    check_range "CDR real duration (s)" "$(sql "SELECT COALESCE(MAX(real_sessiontime), 0) FROM pkg_cdr WHERE id_trunk = $TCRC_TRUNK_A AND starttime >= '$t'")" 115 121
    check "counter billed seconds (60/60 rounding)" "$(sql "SELECT billed_seconds FROM pkg_trunk_cap_usage u JOIN pkg_trunk_cap_policy p ON p.id = u.id_policy WHERE p.id_trunk = $TCRC_TRUNK_A")" 120
    calls t4b 1 1 2000
    check "next call blocked (cap used up)" "$(answered t4b)" 0
}

T5() {
    say "T5 crash: 3 calls up, Asterisk killed -> reservations left open, sweeper releases them"
    reset_caps; group A; policy "$TCRC_TRUNK_A" "max_concurrent = 5"
    CALLER_PORT=$((CALLER_PORT + 1))
    sipp 127.0.0.1:5060 -sf "$SCEN/uac_auth.xml" -s "${TCRC_PREFIX}5550005" -set user "$TCRC_SIP_USER" -au "$TCRC_SIP_USER" -ap "$TCRC_SIP_PASS" \
        -i 127.0.0.1 -p "$CALLER_PORT" -m 3 -r 3 -l 3 -d 300000 -bg -nostdin -trace_logs -log_file "$OUT/t5.log" >"$OUT/t5.out" 2>&1
    local pid; pid=$(grep -o 'PID=\[[0-9]*\]' "$OUT/t5.out" | tr -dc 0-9)
    sleep 15
    check "open reservations while calls are up" "$(open_reservations)" 3
    systemctl kill --signal=KILL asterisk; sleep 3; kill "$pid" 2>/dev/null
    systemctl start asterisk; sleep 8
    check "reservations still open after the crash" "$(open_reservations)" 3
    # Fast-forward: real expiry is max duration + 300 s. Only the clock is faked; state and sweeper are real.
    sql "UPDATE pkg_trunk_cap_reservation SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id_trunk = $TCRC_TRUNK_A AND released_at IS NULL"
    php "$MB/cron.php" TrunkCapSweep >>"$OUT/t5-sweep.log" 2>&1
    check "open reservations after sweep" "$(open_reservations)" 0
    check "swept events" "$(sql "SELECT COUNT(*) FROM pkg_trunk_cap_event WHERE id_trunk = $TCRC_TRUNK_A AND event = 'swept'")" 3
    check "concurrency slot usable again" "$(calls t5b 1 1 2000; answered t5b)" 1
}

T6() {
    say "T6 accuracy: 40 calls of mixed length vs the fake provider's own records (60/60 billing)"
    reset_caps; group A; policy "$TCRC_TRUNK_A" "max_minutes = 100000, period_type = 'month'"
    : >"$OUT/provider-a.log.t6mark"; local before; before=$(wc -l <"$OUT/provider-a.log")
    local t; t=$(php_now)
    for hold in 7000 23000 41000 59000 61000 77000 89000 119000; do
        calls "t6-$hold" 5 5 "$hold"
    done
    # provider truth: ANSWER/END pairs logged by the fake provider during T6
    local prov; prov=$(tail -n +"$((before + 1))" "$OUT/provider-a.log" | awk '
        $1 == "ANSWER" { a[$2] = $3 } $1 == "END" && ($2 in a) { d = int($3 - a[$2] + 0.5); n++;
          b = (d < 60 ? 60 : d); if (b % 60) b += 60 - b % 60; s += b } END { printf "%d %d", n, s }')
    local pcalls=${prov% *} pbilled=${prov#* }
    local counter; counter=$(sql "SELECT billed_seconds FROM pkg_trunk_cap_usage u JOIN pkg_trunk_cap_policy p ON p.id = u.id_policy WHERE p.id_trunk = $TCRC_TRUNK_A")
    local ccalls; ccalls=$(cdr_count "$TCRC_TRUNK_A" "$t")
    check "answered calls (ours)" "$ccalls" 40
    check "calls on the provider side" "$pcalls" 40
    say "  info  billed seconds: cap counter $counter, provider $pbilled"
    check_range "cap counter vs provider, difference in billed seconds" "$(( counter > pbilled ? counter - pbilled : pbilled - counter ))" 0 $(( pbilled / 50 ))
    # the monthly reconciliation report, with the provider figure entered as a statement
    local day; day=$(date -u +%Y-%m-%d)
    sql "INSERT INTO pkg_trunk_cap_statement (id_trunk, period_start, period_end, timezone, provider_minutes, provider_calls, note)
         VALUES ($TCRC_TRUNK_A, '$day', '$day', 'UTC', $(awk "BEGIN { printf \"%.2f\", $pbilled / 60 }"), $pcalls, 'tcrc T6 fake provider')"
    php "$MB/cron.php" TrunkCapReconcile "$(date -u +%Y-%m)" >"$OUT/t6-reconcile.txt" 2>&1
    cat "$OUT/t6-reconcile.txt" | tee -a "$OUT/run.log" >/dev/null
    check "reconciliation status for tcrc-a" "$(awk '$1 == "tcrc-a" && $2 ~ /'"$day"'/ { print $NF }' "$OUT/t6-reconcile.txt" | head -1)" ok
}

command -v sipp >/dev/null || { echo "sipp is not installed (apt install sip-tester)"; exit 2; }
say "Results in $OUT"
start_providers
for t in ${@:-T0 T1 T2 T3 T4 T5 T6}; do "$t"; done
reset_caps; group AB
say "DONE: $PASSES passed, $FAILS failed"
[ "$FAILS" -eq 0 ]
