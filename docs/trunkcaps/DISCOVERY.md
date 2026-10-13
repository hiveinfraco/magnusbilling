# Trunk Caps — Phase 1 Discovery Note

Upstream base: `magnussolution/magnusbilling@source` `eae92c56` (2026-09-23).
Everything below was verified in the code unless marked **ASSUMPTION**.

## 1. Where a trunk is chosen and dialed

```
mbilling.php → StandardCallAgi::processCall → Magnus::checkNumber (rate/plan lookup)
            → CalcAgi::sendCall()                resources/asterisk/CalcAgi.php:572
                 builds trunk list from pkg_trunk_group_trunk (type 1 order / 2 random / 3 LCR)
                 foreach trunk:                   :628
                    skip if provider credit exhausted / trunk status = 0
                    include BeforesendCalltoTrunk.php  :710  ← return false = skip trunk
                    sendCalltoTrunk() → Magnus::run_dial()  (Dial with L(%timeout%…))
                    BUSY/NOANSWER/CANCEL/answered → break; CHANUNAVAIL/CONGESTION → next trunk
            → CalcAgi::updateSystem() → saveCDR()  :300 / :870  (pkg_cdr or pkg_cdr_failed, with id_trunk)
```

`DidAgi.php:743` (DID forwarded to PSTN) also goes through `sendCall`, so it is covered too.

**Hooks (only small core changes):**

| Stage | Hook | Core diff |
|---|---|---|
| Check + reserve | **Existing** optional include `resources/asterisk/BeforesendCalltoTrunk.php`. Runs inside `sendCall` with `$modelTrunk`, `$MAGNUS`, `$agi`, `$this` (CalcAgi) and `$timeout` in scope. | **none** (new file) |
| Max-duration cap | Lower `$timeout` in that same include. It becomes `L(%timeout%…)` in the default `dialcommand_param` (`,60,L(%timeout%:61000:30000)`). | none |
| Commit / release | No hook exists after `sendCalltoTrunk()` returns. Add `AftersendCalltoTrunk.php` include, same pattern as the existing one (~5 lines, `CalcAgi.php:730`). At that point `dialstatus` and `real_answeredtime` are known for **each attempt**, failover attempts included. | ~5 lines |
| Dry run | In debug mode, `run_dial` stops (`exit`) before dialing (`Magnus.php:651`). The before-hook must **not reserve** when `$agi->debugMode`, and should only emit events. | — |
| Call Diagnostics | Emit `$agi->verboseEvent('Trunk', 'TRUNK_CAP_BLOCKED', …)`, the same mechanism as `PROVIDER_NO_CREDIT`. `CallDiagnosticService` already collects these events. Add a display mapping in `eventDisplayDetails()`. | ~10 lines |

The AGI process ignores SIGHUP (`mbilling.php:22`), so code after `Dial` still runs when the caller hangs up. Only a crash or kill leaves a reservation open, and the cron sweeper cleans those up.

## 2. Paths that dial trunks *without* `sendCall` (not covered by the hook)

| Path | File | Notes |
|---|---|---|
| Campaign origination | `protected/commands/MassiveCallCommand.php:271-330` | Picks the first active trunk itself, then writes a call file. It has a `MassiveCallBeforeDial.php` include, but that include can't skip a trunk. |
| Campaign press-to-transfer | `resources/asterisk/MassiveCall.php:349-400` | Has its own trunk-group loop. |
| 0800 / callback | `Start0800CallBackCommand.php:110-175`, `CallbackAgi.php:73`, `Call0800WebController`, `SmsCallbackController` | Originates directly on the first trunk of the group. |
| DID destination that is a raw dial string | `DidAgi.php:631-660` | Trunk named inside the destination string. |

**Proposal:** milestone 1 enforces caps on `sendCall` only. The paths above get a "not cap-enforced" warning in the docs, and the ones you actually use become a later milestone (see Q1).

## 3. Existing limit-like fields (don't duplicate)

- `pkg_trunk.maxuse` / `inuse` / `if_max_use` exist and `maxuse` is editable, but nothing enforces it in the AGI. `AsteriskAccess::groupTrunk()` has **no callers**. Our `max_concurrent` replaces it in practice. We leave those columns alone.
- `pkg_provider.credit` + `credit_control` already skip a trunk when provider credit runs out. Our caps follow the same "skip and fail over" pattern.
- **Provider rounding already exists:** `pkg_rate_provider.buyrateinitblock` / `buyrateincrement` (used for `buycost`, `CalcAgi.php:430-460`). So `rounding_mode` on a policy becomes an *optional override*, and the default is the provider buy-rate blocks for the matched prefix.

## 4. Conventions found

- **DB access in AGI:** `class AGI extends PDO`, raw SQL. `TrunkCapService` will take a `PDO`. On the Yii side that is `Yii::app()->db->pdoInstance`, so the same class works in both places. Tables use the `pkg_` prefix, InnoDB, utf8mb3.
- **Migrations:** `UpdateMysqlCommand` is a *single linear chain* on `pkg_configuration.version` (`8.0.0.0 → … → 8.0.0.8`, and upstream adds steps monthly). If we add our own `8.0.0.x` step, it will collide with upstream's next one. **Proposal:** a separate track `TrunkCapMigrateCommand` with its own key `trunkcaps_schema_version`. It gets called by one line at the end of `UpdateMysqlCommand::run()`. Fresh installs pick it up too, because `install.sh:950` runs `updatemysql`. With this, `script/database.sql` needs no edit (it's mostly one huge INSERT line and conflicts easily). This deviates from spec rule 3; see Q2.
- **Cron:** schedules are hard-coded in `install.sh:675-696` (written to crontab). Our migration will write `/etc/cron.d/magnusbilling-trunkcaps` instead. Upstream already writes system files from migrations (8.0.0.7 edits `modules.conf`).
- **Menu/permissions:** `pkg_module` (text, module, icon_cls, id_module parent, priority) + `pkg_group_module` (id_group, id_module, action, show_menu…). We seed both in our migration, admin group only.
- **Ext JS module pattern:** `classic/src/view/<module>/{Module,Controller,List,Form,Combo,Lookup}.js` + `app/model/<X>.js` + `app/store/<X>.js` (copy `trunk/`). The built bundles are checked in, so this needs Sencha Cmd 6.2 to rebuild.
- **Notifications:** `AlarmCommand` already sends SMTP mail using the `smtp_*` config through JPhpMailer. We reuse that for email. Telegram is new.
- **Tests:** there is **no PHPUnit suite in the repo** (`protected/tests/` doesn't exist, even though CONTRIBUTING.md mentions it). We'll create `protected/tests/unit/` and keep window math in a pure class with no DB, so tests run without a server.

## 5. Final schema

```
pkg_trunk_cap_policy   id, id_trunk FK→pkg_trunk ON DELETE CASCADE, name, active,
                       period_type ENUM(hour,day,week,month,custom), period_length_sec (custom only),
                       anchor ENUM(calendar,billing_day,rolling), reset_day, timezone DEFAULT 'Africa/Lagos',
                       window_start TIME, window_end TIME, weekdays SET(mon..sun)       (all NULL = always)
                       max_calls, max_calls_per_hour, max_minutes, max_concurrent, max_cps   (NULL = unlimited)
                       count_failed_attempts, safety_margin_pct DEFAULT 100, warn_pct DEFAULT '80,90',
                       on_limit ENUM(skip_trunk,reject), rounding_init_sec, rounding_inc_sec (NULL = provider buy-rate),
                       strict, created_at, updated_at                                   KEY(id_trunk, active)
pkg_trunk_cap_usage    id_policy, window_start DATETIME(UTC), calls, attempts, billed_seconds,
                       reserved_seconds, warned_pct, updated_at                         PK(id_policy, window_start)
                       -- one row per fixed window (calendar / billing_day anchors)
pkg_trunk_cap_minute   id_policy, minute_start DATETIME(UTC), calls, attempts, billed_seconds,
                       reserved_seconds                                                 PK(id_policy, minute_start)
                       -- 1-minute buckets, written only for rolling policies and policies with
                       --  max_calls_per_hour; summed over the window. Old buckets are purged by the sweeper.
pkg_trunk_cap_lock     id_trunk PK                                                      -- per-trunk FOR UPDATE row
pkg_trunk_cap_reservation id, id_trunk, uniqueid, expected_seconds, allocations TEXT(JSON),
                       created_at DATETIME(3), expires_at, released_at NULL, dialstatus
                       KEY(id_trunk, released_at), KEY(id_trunk, created_at), KEY(expires_at)
pkg_trunk_cap_event    id, created_at, id_trunk, id_policy, event ENUM(warned,blocked,reset,override,swept),
                       snapshot TEXT(JSON), uniqueid, id_user                           KEY(id_trunk, created_at)
```

Changes from the first draft, and why:
- **No stored `concurrent` counter.** Concurrency = open reservations (`released_at IS NULL`) for the trunk, counted under the trunk lock. A counter can drift after a crash; a count cannot. The sweeper closes stale reservations. `pkg_trunk_cap_lock` is only a lock row.
- **Reservations are kept after release** (purged after a day). CPS = reservations created in the last second, including calls that already failed.
- **`allocations`** records which usage row and minute bucket each policy charged. On commit we decrement the same rows, even if the window rolled over during the call.
- **`pkg_trunk_cap_minute`** handles rolling windows and `max_calls_per_hour` without scanning CDRs.

**Atomic reserve:** one short transaction per trunk attempt. `SELECT … FROM pkg_trunk_cap_lock WHERE id_trunk=? FOR UPDATE` serializes cap checks for that trunk. Then read usage, check the limits, upsert the counters, insert the reservation, and commit. Any exception → log + fail open, unless the policy is `strict`.

**Reservation sizing:** resolved in Phase 3. See §9 and `ADMIN.md`.

## 6. Files to touch

**New:** `protected/components/TrunkCapService.php`, `TrunkCapWindow.php` (pure math), `resources/asterisk/BeforesendCalltoTrunk.php`, `AftersendCalltoTrunk.php`, `protected/commands/TrunkCapMigrateCommand.php`, `TrunkCapSweepCommand.php`, models `TrunkCapPolicy/Usage/Event.php`, `protected/controllers/TrunkCap{Policy,Usage,Event}Controller.php`, `classic/src/view/trunkCap*/…`, `app/model|store/TrunkCap*.js`, `protected/tests/unit/TrunkCapWindowTest.php`, `docs/trunkcaps/ADMIN.md`.
**Edited (small):** `CalcAgi.php` (+after-hook include), `UpdateMysqlCommand.php` (+1 call), `CallDiagnosticService.php` (+event labels), locale files (translations).

## 7. Decisions (confirmed 2026-10-13)

1. **Call paths:** milestone 1 enforces caps on `CalcAgi::sendCall` only (standard outbound + DID→PSTN). Campaigns, callbacks/0800 and raw-dial DID destinations are documented as *not cap-enforced*.
2. **Migrations:** separate track. `TrunkCapSchema` is versioned by `pkg_configuration.trunkcaps_schema_version` and called by one line in `UpdateMysqlCommand::run()`. It can also be run by hand: `php cron.php TrunkCapMigrate [status|up|down confirm]`.
3. **After-dial hook:** add the `AftersendCalltoTrunk.php` include (~5 lines) in `CalcAgi::sendCall`.
4. The spec's §10 open questions still stand. They only affect defaults.

## 8. Window semantics (implemented in `TrunkCapWindow`)

- Period boundaries use the policy timezone. Hourly periods use UTC-offset arithmetic, so DST and half-hour zones are handled. Daily, weekly and monthly periods start at local midnight. Weeks start on Monday.
- `billing_day` + month: if the reset day doesn't exist in a month, it moves to that month's last day (reset 31 → Feb 28/29, Apr 30).
- Calendar `custom` periods are aligned to the Unix epoch in UTC.
- Rolling periods sum the 1-minute buckets, including the current minute. **ASSUMPTION:** a rolling "month" is 30 days.
- Time-of-day windows include the start time and exclude the end time. An overnight window (e.g. 22:00–06:00) counts as the weekday it opens on.
- A call is counted in the period and window where it **starts**. A call that crosses a period boundary is charged entirely to the period it started in.

## 9. Enforcement (implemented in Phase 3)

Schema steps 2 and 3 add: `reserved_calls` on usage and minute rows, `reserve_mode` on policies, `max_seconds` on reservations, `notified_at` on events, the `trunkcaps_alert_email` setting, and `/etc/cron.d/magnusbilling-trunkcaps`.

- **Hook flow:** `BeforesendCalltoTrunk.php` calls `TrunkCapService::reserve()` and returns false to skip a trunk. It may lower `$timeout`, which becomes Dial's `L()` limit. `AftersendCalltoTrunk.php` calls `commit()` after each attempt, failed failover attempts included. Both chain to optional `*.local.php` files for site-specific logic.
- **Locking:** one transaction per trunk attempt, using READ COMMITTED isolation and `SELECT … FOR UPDATE` on `pkg_trunk_cap_lock`. The lock row is created **outside** the transaction. Creating it inside concurrent transactions deadlocked in testing, and fail-open then let calls through without a cap check. Deadlocks and lock timeouts are retried 3 times before failing open.
- **Calls limits:** `count_failed_attempts = 1` counts `attempts`, which are charged at reserve time. Otherwise the count is `calls + reserved_calls`, so in-flight calls count against the limit. Count limits after the safety margin are never below 1.
- **Minutes limits:**
  - available = margin-adjusted limit − billed − reserved seconds. Below 30 s the policy blocks (**ASSUMPTION**).
  - Dial limit = min(the call's normal timeout, available), taking the tightest policy.
  - `adaptive` (default): reserve the expected call length while available ≥ 2× the call's timeout, otherwise reserve the full dial limit. Expected length = the window's average answered call, rounded up to a whole minute; it defaults to 180 s (**ASSUMPTION**) until 10 calls are answered. **Residual risk:** many long calls admitted at the same time while above the threshold can overshoot. Keep a safety margin, or use `full`.
  - `full`: always reserve the full dial limit. It never overshoots, but near the cap the concurrent calls' maximum durations use up headroom quickly.
- **Concurrency / CPS:** per trunk. Concurrency = open reservations. CPS = reservations created in the last second, including ones already released.
- **Billed seconds:** a policy's rounding override takes priority. Otherwise the provider buy-rate init/increment for the matched prefix is used, with the same formula as CalcAgi's buycost.
- **Blocked calls:** `skip_trunk` fails over to the next trunk. `reject` skips all remaining trunks of this call. When no trunk is dialed because of caps, the channel variable `TRUNKCAP_RESULT=BLOCKED` is set and the AGI logs `TRUNK_CAP_ALL_BLOCKED`. The CDR is the normal failed CDR with an empty dialstatus. **To verify on the dev VM:** the SIP cause the caller receives in that case.
- **Warnings:** percent of the **provider** cap (not the margin-adjusted limit), billed usage only, fixed windows only. Each threshold fires once per window (`warned_pct`). The AGI only writes the event; `TrunkCapSweep` e-mails a digest. Telegram is not built yet (spec §10 Q6).
- **Sweeper:** reservations still open after max duration + 300 s are released, using `pkg_cdr.real_sessiontime` when the call was recorded, otherwise counted as an unanswered attempt. Released reservations are purged after 1 day, and minute buckets after 32 days.
