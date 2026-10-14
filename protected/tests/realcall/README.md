# Trunk caps: real-call tests (Phase 5)

End-to-end tests with real SIP calls through Asterisk and MagnusBilling. **Dev VM only.**

```
SIPp caller (tcrc-sip, digest auth)
   │  INVITE 9997xxxxxxx
   ▼
Asterisk 20 / PJSIP ──► AGI mbilling.php ──► CalcAgi::sendCall
   │                         └─ Before/AftersendCalltoTrunk.php (trunk caps)
   ├──► tcrc-a  127.0.0.2:5091  SIPp fake provider A
   └──► tcrc-b  127.0.0.3:5092  SIPp fake provider B
```

The fake providers listen on their own loopback addresses: if a trunk's host were 127.0.0.1, PJSIP would identify the caller as that trunk by IP and skip authentication. They answer every call and log each call's answer and hangup time. These logs are the "provider statement" for the accuracy test. Everything runs on the dev VM, so no second VM or public SIP traffic is needed.

## Steps

1. Install MagnusBilling 8 on the dev VM with the upstream `install.sh` (spec §5), then take a snapshot.
2. From your machine, deploy the fork: `protected/tests/realcall/deploy-dev.sh root@VM_IP`.
3. On the VM, as root:
   ```
   cd /var/www/html/mbilling
   php protected/tests/realcall/setup.php      # tcrc-* records; secrets in /root/tcrc.env
   bash protected/tests/realcall/run.sh         # all tests, ~16 min; or: run.sh T2 T3
   ```

## Tests

| | What | Pass criteria |
|---|---|---|
| T0 | One call | Answered, one CDR on tcrc-a, counted, no open reservation |
| T1 | 100 simultaneous calls; tcrc-a limited to 10 concurrent; no failover trunk | Exactly 10 answered and 10 CDRs; peak overlap in the CDRs ≤ 10; 90 `blocked` events |
| T2 | tcrc-a capped at 5 calls/day; group A → B; 8 calls | 5 CDRs on tcrc-a, 3 on tcrc-b |
| T3 | Both trunks at their cap | Caller gets a SIP error; no CDR; `blocked` events on both trunks for that call |
| T4 | 2 minutes left on tcrc-a; caller never hangs up | The network ends the call at about 120 s; counter = 120 s; the next call is blocked |
| T5 | 3 calls up, Asterisk killed with SIGKILL | Reservations stay open; after the sweep, 0 open and 3 `swept` events; a new call works |
| T6 | 40 calls from 7 s to 119 s, provider bills 60/60 | 40 calls on both sides; cap counter within 2% of the provider's billed seconds; reconciliation report status `ok` |
| T7 | Both trunks inactive (nothing dialed, no caps involved) | Caller gets a final 503, not silence |
| T8 | 3 calls with no policy, then a daily policy is created | After the sweep, the counter equals today's CDRs (calls, 60/60 seconds, attempts) and one `seeded` event exists; the next call is counted once |

T5 fast-forwards one thing: it sets the stale reservations' `expires_at` to now, instead of waiting for max duration + 5 minutes. The crash, the left-over state and the sweeper are all real.

The real accuracy check against a real carrier is the pilot in Phase 6: enter the carrier's monthly statement under Routes → Trunk Cap Reconciliation.
