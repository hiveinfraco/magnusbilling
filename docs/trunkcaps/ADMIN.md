# Trunk Caps: administrator guide

Trunk caps stop MagnusBilling from sending more traffic to a trunk than its upstream provider allows. Each trunk can have one or more **cap policies**. Before every call attempt on a trunk, all of its active policies are checked. If one is at its limit, the trunk is skipped and the call fails over to the next trunk in the Trunk Group, or it is rejected.

> Status: the enforcement engine is in place. The panel screens (Trunk Caps module, live usage, overrides) come in the next milestone. Until then, manage policies directly in `pkg_trunk_cap_policy`.

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
| `safety_margin_pct` | Enforce at this percent of each limit (e.g. 95). Count limits never drop below 1. |
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

## If something goes wrong

- If the cap check fails (database unreachable, unexpected error), it is logged in the Asterisk console and syslog as `[TrunkCaps] …`, and the call goes ahead as if caps were not installed. Exception: policies marked `strict` block the trunk.
- If an AGI process dies mid-call, its reservation is released by the per-minute sweeper (`php cron.php TrunkCapSweep`, installed as `/etc/cron.d/magnusbilling-trunkcaps`) once the call's maximum duration plus 5 minutes has passed. The real duration is taken from the CDR when one exists.

## Warnings

When a fixed-period policy crosses a `warn_pct` threshold, a `warned` event is written to `pkg_trunk_cap_event`. Within a minute, the sweeper e-mails a digest to the addresses in the **Trunk caps alert e-mail** setting (`trunkcaps_alert_email`), using the admin SMTP account. Warnings that can't be sent within 24 hours are dropped from the queue. Rolling-window policies don't send warnings.

## Audit log

`pkg_trunk_cap_event` records `blocked` (with the limit and usage at that moment), `warned`, `swept` and, once the panel screens exist, `reset` and `override`.

## Call diagnostics and logs

AGI log events: `TRUNK_CAP_BLOCKED` (trunk skipped), `TRUNK_CAP_LIMITED` (call duration shortened), and `TRUNK_CAP_ALL_BLOCKED` (no trunk dialed because of caps). In the last case the channel variable `TRUNKCAP_RESULT` is set to `BLOCKED`. Call Diagnostics runs the check without reserving anything.

## Site-specific hooks

`resources/asterisk/BeforesendCalltoTrunk.php` and `AftersendCalltoTrunk.php` belong to trunk caps. If you had your own `BeforesendCalltoTrunk.php`, move it to `BeforesendCalltoTrunk.local.php`. It is still called for every trunk attempt that caps allow, and returning `false` still skips the trunk. Post-dial logic goes in `AftersendCalltoTrunk.local.php`.

## Maintenance commands

```
php cron.php TrunkCapMigrate                 # schema version
php cron.php TrunkCapMigrate up              # also run automatically by UpdateMysql
php cron.php TrunkCapMigrate down confirm    # remove trunk caps tables, data and cron file
php cron.php TrunkCapSweep                   # what the cron runs every minute
```
