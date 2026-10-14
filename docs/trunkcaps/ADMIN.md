# Trunk Caps: administrator guide

Trunk caps stop MagnusBilling from sending more traffic to a trunk than its upstream provider allows. Each trunk can have one or more **cap policies**. Before every call attempt on a trunk, all of its active policies are checked. If one is at its limit, the trunk is skipped and the call fails over to the next trunk in the Trunk Group, or it is rejected.

## Panel screens

Under **Routes**, for the administrator group (log in again after upgrading to see them):

- **Trunk Caps:** create and edit policies. The **Summary** box at the top of the form describes the policy in plain language as you type. Toolbar actions on the selected policy:
  - **Reset counters** zeroes the current period's calls and minutes. Calls in progress keep their reservation.
  - **Pause** (1 h / 4 h / 24 h / End pause) stops enforcing the policy for a while. Usage is still counted during a pause, so the counters keep matching the provider.
  
  Both actions are recorded in the Trunk Cap Log with the user who made them.
- **Trunk Cap Usage:** live view of every active policy's current period: status (OK, Warning, At limit, Paused, Outside window), usage bar, minutes (billed plus reserved for calls in progress), calls, and calls in progress. It refreshes every 30 seconds.
- **Trunk Cap Log:** read-only audit log of blocked calls, warnings, resets, pauses and swept reservations. Select a row to see the full snapshot.
- **Trunk Cap Reconciliation:** provider statements and how they compare with our minutes (see below).

Other groups get no access unless you grant it under Settings → Group users. The usage and log screens are read-only for everyone.

### How the screens are loaded (for maintainers)

MagnusBilling ships a precompiled Ext JS bundle per theme and a build-generated `index.html`. The Trunk Caps screens are therefore **not** compiled into that bundle. They live in `resources/trunkcaps/trunkcaps.js`, which `index.php` (`SiteController::actionIndex` → `TrunkCapPanel::loaderScript()`) adds to the page. They work with every theme and need no Sencha Cmd build. Deploying is just copying the files. Running upstream's `protected/commands/update.sh` overwrites the fork, so deploy from the fork instead (see the spec, Phase 6).

## What is enforced

Caps apply to calls routed through a **Trunk Group**. That covers normal outbound calls, and DID calls forwarded to an external number.

**Not cap-enforced in this version:** Voice Broadcast campaigns, callback / 0800 callback, and DID destinations that dial a trunk directly with a raw dial string. Traffic on those paths is still counted by the provider, so leave extra safety margin on trunks they use.

## Policy fields

| Field | Meaning |
|---|---|
| `period_type` | `hour`, `day`, `week`, `month`, or `custom` (`period_length_sec`, at least 60 s) |
| `anchor` | `calendar`: periods start at the top of the hour, midnight, Monday or the 1st, in `timezone`. `billing_day`: weekly or monthly periods start on `reset_day` (ISO weekday 1–7, or day of month 1–31; days past the end of a short month move to its last day). `rolling`: the last hour, day, week, 30 days or custom length, up to now. |
| `timezone` | Used for all period and window boundaries. Default `Africa/Lagos`. |
| `window_start`, `window_end`, `weekdays` | Optional peak/off-peak window. The policy only applies, and only counts, calls that start inside the window. An overnight window such as 22:00–06:00 belongs to the weekday it opens on. |
| `max_calls` | Calls per period. With `count_failed_attempts = 1`, every attempt counts, answered or not. |
| `max_calls_per_hour` | Calls in any rolling 60 minutes, on top of the period limit |
| `max_minutes` | Billed minutes per period, using the provider's rounding |
| `max_concurrent` | Simultaneous calls on the trunk |
| `max_cps` | New calls per second on the trunk |
| `safety_margin_pct` | Enforce at this percent of the period limits (calls, calls per hour, minutes), e.g. 95, to absorb differences between our counters and the provider's records. Not applied to concurrent calls or calls per second, which are measured exactly. Count limits never drop below 1. |
| `warn_pct` | Warning thresholds as a percent of the provider cap, e.g. `80,90` |
| `on_limit` | `skip_trunk` fails over to the next trunk. `reject` fails the call without trying other trunks. |
| `reserve_mode` | `adaptive` (default) or `full`. See below. |
| `rounding_init_sec`, `rounding_inc_sec` | Override the provider billing increment. Empty = use the provider buy rate for the destination. |
| `strict` | If the cap check itself fails (e.g. database error), block the trunk instead of allowing the call |

Empty limits mean unlimited. A policy needs at least one limit.

## How minutes caps avoid overshooting

Each call gets a maximum duration (Asterisk's Dial time limit). That duration is never longer than the minutes still available under the tightest minutes cap. A call that is still running when the cap is reached is cut off.

While a call is in progress, part of the remaining minutes is reserved for it:

- **adaptive:** while plenty of minutes are left, a call reserves only its expected length (the average call in this period, initially 3 minutes). Once less than 2× a call's maximum duration remains, each new call reserves its full maximum duration. This rarely blocks a trunk early. Very many long calls running at the same time just before the switch-over can still go slightly over the cap, so keep a safety margin.
- **full:** every call always reserves its full maximum duration. The cap can never be exceeded. However, with many simultaneous calls and long maximum durations, the trunk stops taking new calls before the minutes are actually used.

A call is counted entirely in the period it **started** in.

**Known limitation: direct media.** When both the SIP account and the trunk have `directmedia = yes`, MagnusBilling removes the Dial time limit (`L()`), so the call can't be cut off when the minutes run out (`Magnus::run_dial`). Calls are still counted correctly and new calls are still blocked, but a call already in progress can run past the cap. Avoid direct media on capped trunks, or keep a larger safety margin.

## If something goes wrong

- If the cap check fails (database unreachable, unexpected error), it is logged in the Asterisk console and syslog as `[TrunkCaps] …`, and the call goes ahead as if caps were not installed. Exception: policies marked `strict` block the trunk.
- If an AGI process dies mid-call, its reservation is released by the per-minute sweeper (`php cron.php TrunkCapSweep`, installed as `/etc/cron.d/magnusbilling-trunkcaps`) once the call's maximum duration plus 5 minutes has passed. The real duration is taken from the CDR when one exists.

## When every trunk is capped

The caller gets a **503 Service Unavailable** within seconds (hangup cause 34). Upstream carriers and phones treat it as "try elsewhere or later". The AGI log shows `TRUNK_CAP_ALL_BLOCKED`, and every skipped trunk gets a `blocked` row in the Trunk Cap Log.

## A new policy starts with this period's CDR traffic

When you create a policy, its counters are seeded from the CDRs of its trunk, from the start of the current period up to the moment the policy was saved. Calls after that are counted live, and calls that already hold a reservation are skipped, so no call is counted twice. Answered calls add a call and their provider-rounded seconds, using the policy's rounding or else the provider buy rate for the CDR's prefix. Failed calls (`pkg_cdr_failed`) add an attempt. Time-of-day/weekday windows are honoured, and rolling policies fill their minute buckets.

Seeding runs once per policy. The Trunk Cap Log shows it as a `seeded` event with the range and totals. Policies created by SQL, or whose seeding failed in the panel, are seeded by the per-minute sweep.

Limits of seeding:
- Calls still in progress when the policy is saved have no CDR yet. They are not counted.
- Editing a policy later (for example from daily to monthly) doesn't re-seed it. Create a new policy instead.
- A policy that was inactive and is reactivated counts live only from reactivation.
- Rounding uses the CDR's matched customer prefix to find the provider rate, which can differ from the live lookup on the dialled number when the provider has longer prefixes than the customer rate. Set the policy's own rounding when that matters.

## Warnings: e-mail and Telegram

When a fixed-period policy crosses a `warn_pct` threshold, a `warned` event is written to `pkg_trunk_cap_event`. Within a minute, the sweeper sends a digest on every configured channel. Each channel keeps its own delivery marker, so a failure on one channel never re-sends on the other. Warnings that can't be sent within 24 hours are dropped from the queue. Rolling-window policies don't send warnings.

- **E-mail:** set **Trunk caps alert e-mail** (`trunkcaps_alert_email`, comma-separated). Mail goes through the admin SMTP account (Settings → SMTP).
- **Telegram:**
  1. Create a bot with @BotFather and copy its token.
  2. Add the bot to the group or channel that should receive alerts (as an admin for channels).
  3. Find the chat ID. Send a message in the chat, then open `https://api.telegram.org/bot<TOKEN>/getUpdates`. Group IDs start with `-100`.
  4. Under Settings → Configuration, set **Trunk caps Telegram bot token** and **Trunk caps Telegram chat ID**.

  Leave the token empty to turn Telegram off. Errors are logged without the token.

A warning threshold of 100 never fires on a policy with a safety margin below 100, because the policy blocks first. Use, for example, `80,90,95`.

## Monthly reconciliation against provider statements

Under **Routes → Trunk Cap Reconciliation**, enter each provider statement: trunk, period (inclusive dates), the statement's timezone, minutes, and optionally calls. Each row shows:

- **Our minutes:** answered CDR time on that trunk in the period, rounded per call with the provider's billing increments (buy-rate initial block and increment for the call's prefix, or the policy's rounding override). Hover over the value for the unrounded figure.
- **Cap counter min:** the trunk's calendar-month minutes counter, shown only when the statement covers exactly that month.
- **Difference** and **Status:** `OK` or `Mismatch` against **Trunk caps reconciliation tolerance %** (`trunkcaps_reconcile_tolerance_pct`, default 2).

On the 2nd of every month at 07:00 (server time), `php cron.php TrunkCapReconcile send` sends the previous month's report by e-mail and Telegram. Trunks with a minutes policy but no statement are listed as "No statement", so a missing statement is visible too. Run `php cron.php TrunkCapReconcile 2026-09` to print any month without sending.

CDR times are in the server's PHP timezone (set by the installer from the system timezone). Statement periods are converted from the statement's timezone, so a Lagos-time statement on a UTC server lines up correctly.

## Audit log

`pkg_trunk_cap_event` records `blocked` (with the limit and usage at that moment), `warned`, `swept`, `reset` and `override`. Resets and pauses also store the panel user's ID. View it under Routes → Trunk Cap Log.

## Call diagnostics and logs

AGI log events: `TRUNK_CAP_BLOCKED` (trunk skipped), `TRUNK_CAP_LIMITED` (call duration shortened), and `TRUNK_CAP_ALL_BLOCKED` (no trunk dialed because of caps). In the last case the channel variable `TRUNKCAP_RESULT` is set to `BLOCKED`. Call Diagnostics runs the check without reserving anything, and shows these events with the trunk, the limit reached, the policy ID and the action.

## Site-specific hooks

`resources/asterisk/BeforesendCalltoTrunk.php` and `AftersendCalltoTrunk.php` belong to trunk caps. If you had your own `BeforesendCalltoTrunk.php`, move it to `BeforesendCalltoTrunk.local.php`. It is still called for every trunk attempt that caps allow, and returning `false` still skips the trunk. Post-dial logic goes in `AftersendCalltoTrunk.local.php`.

## Maintenance commands

```
php cron.php TrunkCapMigrate                 # schema version
php cron.php TrunkCapMigrate up              # also run automatically by UpdateMysql
php cron.php TrunkCapMigrate down confirm    # remove trunk caps tables, data and cron file
php cron.php TrunkCapSweep                   # what the cron runs every minute
php cron.php TrunkCapReconcile [send] [YYYY-MM]   # monthly report (cron: 2nd of the month, send)
```
