# Project: MagnusBilling Fork with Trunk Cap Engine

> Drop this file in the root of the forked repository (Claude Code reads `CLAUDE.md` automatically), or rename it and reference it explicitly.

## 1. Goal

Fork **MagnusBilling 8** (`github.com/magnussolution/magnusbilling`, branch `source`) and add a **trunk cap engine** so we never exceed the limits our upstream SIP providers impose on each trunk.

For every trunk we must be able to define one or more cap policies:

- **Periods:** hour, day, week, month, or custom; calendar-based, billing-cycle-day-based, or rolling.
- **Optional time-of-day / weekday windows** (e.g. peak vs off-peak), in a configurable timezone (default `Africa/Lagos`).
- **Limits per period:**
  - max calls
  - max calls per hour
  - max minutes (per day or per month)
  - max concurrent calls
  - optionally max calls per second
- **Safety margin** (e.g. enforce at 95% of the provider cap) and **warning thresholds** (e.g. 80%, 90%).
- **Action at limit:** skip the trunk and fail over to the next trunk in the Trunk Group, or reject.

Longer term, the fork may grow additional features (PBX features, reporting, etc.), but **trunk caps is milestone 1**.

## 2. Background and decisions already made

- We originally considered combining MagnusBilling and FreePBX. **Decision: Option A, fork MagnusBilling only** and add features on top. FreePBX and FreeSWITCH are out of scope for now.
- Reason: MagnusBilling and FreePBX both want to own Asterisk and its config, and cannot share one server. MagnusBilling already covers trunks, providers, billing, routing, IVR, queues, and DIDs.
- MagnusBilling is **LGPL-3.0**. Modifying and running it is permitted; if we ever distribute the modified version, changes to its files must remain LGPL and source must be available. Rebrand anything user-facing if we ship it as a product. (Not legal advice; confirm with a lawyer before commercializing.)
- Provider caps: confirm per provider whether caps are in minutes, call attempts, or both, and whether they are hard cutoffs or overage charges. This affects defaults (see Open Questions).

## 3. Architecture (as documented by the project)

```
Browser / API clients
        |
Apache + PHP
        |
Yii 1 web app (Ext JS 6 admin UI) ---- MariaDB
        |                                |
        +---------- AMI / AGI -----------+
                      |
              Asterisk 20 / PJSIP
                      |
            carriers and SIP devices
```

Call path: Asterisk dialplan invokes `resources/asterisk/mbilling.php`, which dispatches to `*Agi.php` classes that read the user / rate / trunk / CDR tables and decide trunk selection and dialing.

Key paths (from project docs; **verify in the code before relying on them**):

| Path | Purpose |
|---|---|
| `resources/asterisk/mbilling.php` | AGI entry point for calls |
| `*Agi.php` classes | Billing, trunk selection, dial decision (locate the exact class during discovery) |
| `protected/controllers/<Entity>Controller.php` | Panel CRUD per module (e.g. Trunk) |
| `protected/models/` | Yii models (Trunk, TrunkGroup, CDR, etc.) |
| `protected/components/` | Services and shared components (we add `TrunkCapService.php` here) |
| `protected/components/CallDiagnosticService.php`, `protected/controllers/CallDiagnosticController.php` | Call Diagnostics: runs the real AGI routing code in a dry-run context |
| `protected/commands/UpdateMysqlCommand.php` | DB migrations / structure updates (our schema changes go here) |
| `script/database.sql` | Base schema |
| `protected/config/main.php`, `protected/config/cron.php` | Web and console config; cron registration |
| `cron.php` | Console entry point |
| `app/`, `classic/src/view/` | Ext JS front end (new "Trunk Caps" module) |
| `protected/commands/update.sh` | Upstream update script (see deployment notes) |
| `/etc/asterisk/res_config_mysql.conf` | DB connection used by the installed system |
| `/etc/asterisk/pjsip_magnus.conf`, `pjsip_magnus_user.conf` | **Generated** PJSIP config. Do not hand-edit |
| `ARCHITECTURE.md`, `PROJECT.md`, `CONTRIBUTING.md`, `wiki/` | Read these first |

Installed location on a server: `/var/www/html/mbilling`.

## 4. Ground rules for Claude Code

1. **Never test telephony or installer changes on a production server.** Use the dev VM only.
2. **Keep the diff to core files tiny.** All new logic goes in new files (e.g. `TrunkCapService.php`, new models, a new controller, new UI module). Existing AGI/controller code only gets small hook calls. This keeps upstream merges easy.
3. **Schema changes go through `UpdateMysqlCommand.php`** migrations (and `script/database.sql` for fresh installs). Make them idempotent and reversible where practical.
4. **Do not hand-edit generated Asterisk files** (`pjsip_magnus*.conf`). Change trunk behavior through MagnusBilling records and code.
5. **Never put credentials in the repo**, docs, issues, or logs.
6. **Follow the repo's `CONTRIBUTING.md`** (focused changes, tests or a verification procedure, English docs for user-visible behavior).
7. **Fail safe in the AGI path:** if the cap service errors or the DB is unreachable, log loudly and fall back to the pre-existing behavior (do not drop all calls), unless a policy is marked "strict". Never let a cap-check bug take down call routing.
8. **Performance matters:** the cap check runs on every call setup. Keep it to a small number of indexed queries.
9. Before large changes, **write a short plan and confirm**. Prefer small, reviewable commits.
10. Anything not verifiable from the code or docs should be flagged as an assumption, not invented.

## 5. Development environment

- **Dev server:** a **VM** on our Proxmox (not an LXC; the installer configures Asterisk, firewall, Fail2ban, and may reboot).
  - Debian 12 (installer supports Debian 11/12/13 and Ubuntu LTS 22.04/24.04/26.04), 2 vCPU (`host`), 4 GB RAM, 40 GB disk, bridged network with static IP, QEMU guest agent on.
  - Take a Proxmox snapshot before and after install.
- Install MagnusBilling 8 on the clean VM:
  ```
  wget https://raw.githubusercontent.com/magnussolution/magnusbilling/source/script/install.sh
  bash install.sh
  ```
  Default panel login is `root` / `magnus`: **change immediately**.
- Keep the dev VM off the public internet (LAN or VPN only).
- Server clock in UTC; store the timezone per cap policy.
- Fake upstream for tests: a second small VM (or container) running plain Asterisk acting as a "provider" trunk, so we can generate calls without cost.
- Workflow: edit in the git repo, sync to `/var/www/html/mbilling` on the dev VM, run test calls. PHP tests use PHPUnit (see `protected/tests/`). Ext JS builds use Sencha Cmd 6.2 (`sencha app build development|production`).

## 6. Git and branching

- Fork into our own remote; keep an `upstream` remote pointing at `magnussolution/magnusbilling`.
- Branches: `main` (tracks upstream `source`), `trunkcaps` (feature work), release branch for deployments.
- Merge `upstream/source` into our branches regularly in small batches to avoid drift.

## 7. Design: trunk cap engine

### 7.1 Data model (sketch; refine after discovery)

**`trunk_cap_policy`**: the rules
- `id`, `trunk_id`, `name`, `active`
- `period_type`: `hour | day | week | month | custom`
- `anchor`: `calendar | billing_day | rolling`; `reset_day`; `timezone`
- optional `window_start`, `window_end`, `weekdays` (peak/off-peak)
- limits (NULL = unlimited): `max_calls`, `max_calls_per_hour`, `max_minutes`, `max_concurrent`, `max_cps`
- `safety_margin_pct`, `warn_pct` (multiple thresholds), `on_limit` (`skip_trunk | reject`)
- `rounding_mode`: provider billing increment (e.g. 1/1, 60/1, 60/60)
- `strict` flag (see ground rule 7)

**`trunk_cap_usage`**: counters
- `policy_id`, `window_start`, `calls`, `billed_seconds`, `reserved_seconds`, `updated_at`
- unique key `(policy_id, window_start)`
- for rolling hourly caps: per-minute buckets, sum the last 60 (cheaper and more accurate than scanning CDRs)

**`trunk_cap_event`**: audit log
- timestamp, trunk, policy, event (`warned | blocked | reset | override`), usage snapshot, call id

### 7.2 Enforcement flow

**At trunk selection (before dialing a trunk):**
1. Load active policies for the trunk; compute each policy's current window.
2. Check calls, billed + reserved minutes, hourly calls, concurrency, CPS against `limit x safety margin`.
3. If any is exceeded: skip the trunk and continue to the next trunk in the Trunk Group (existing failover). If all trunks are capped: reject with a distinct cause/reason so it is visible in reports.
4. If usable: **atomically reserve** capacity (one call, one concurrency slot, expected seconds). Use a single guarded `UPDATE ... WHERE calls < limit` (or equivalent) so concurrent calls cannot both take the last slot.

**During the call:**
- Cap the call duration to the minutes remaining under the tightest cap (Asterisk dial time limit), so one long call cannot overshoot at the period boundary.

**On hangup:**
- Convert the reservation into real usage: add one call plus billed seconds computed with the provider's `rounding_mode`.
- Release the concurrency slot.
- Count failed/unanswered attempts too if the provider counts attempts.
- Emit a warning event when a threshold is crossed (email/Telegram notification hook).

**Safety nets:**
- Cron job to expire stale reservations (crashed/dropped calls) and reconcile counters against CDR.
- Monthly reconciliation report against the provider's statement.

### 7.3 Admin UI

- New **Trunk Caps** module in the Ext JS panel: policy editor with a plain-language preview (e.g. "Max 50,000 minutes per calendar month, resets on the 1st, Lagos time").
- **Live usage view:** usage vs cap per trunk and period, plus recent blocks.
- Alerts at warning thresholds (email / Telegram).
- Manual reset and temporary override, both written to the audit log.
- Extend **Call Diagnostics** dry-run output to show cap decisions (e.g. "trunk X skipped: monthly cap reached").
- Register menu entry and group permissions (admin only by default). Note: a module can exist in source but not appear in the panel until group/menu permissions are set.

## 8. Work plan and checklist

### Phase 0: Setup (days 1-3)
- [x] Fork repo; add `upstream` remote; create branches
- [ ] Build dev VM on Proxmox; snapshot; install MagnusBilling 8; change default password
- [ ] Build fake-provider Asterisk for test traffic

### Phase 1: Discovery (week 1) -- read before changing
- [x] Read `ARCHITECTURE.md`, `PROJECT.md`, `CONTRIBUTING.md`, relevant `wiki/` pages
- [x] Trace `resources/asterisk/mbilling.php` and the `*Agi.php` classes; find the **trunk selection and dial** code (our main hook point)
- [x] Read Trunk / TrunkGroup / CDR models and controllers; note any existing limit-style fields (e.g. max channels) to avoid duplicating
- [x] Understand how CDR records billed seconds and the trunk used, and when
- [x] Understand `UpdateMysqlCommand.php` migration conventions and versioning
- [x] Study how the Ext JS panel registers a module (menu, permissions, `app/`, `classic/src/view/`)
- [x] **Deliverable:** one-page note listing the exact files/functions to touch and the final schema

### Phase 2: Data model (week 2)
- [x] Migrations for the three tables; models; seed/test data (`TrunkCapSchema`, six tables; see docs/trunkcaps/DISCOVERY.md §5)

### Phase 3: Enforcement (weeks 3-5)
- [x] `TrunkCapService` (window math, check, reserve, commit, release)
- [x] Hooks in trunk selection, dial (max duration), and hangup/CDR paths
- [x] Reconciliation/cleanup cron (stale reservations; monthly provider reconciliation report still to do)
- [x] Notification hook for thresholds (e-mail digest; Telegram pending §10 Q6)

### Phase 4: UI (weeks 5-6)
- [ ] Trunk Caps module, live usage view, overrides, audit log view
- [ ] Call Diagnostics integration

### Phase 5: Testing (weeks 6-7)
- [ ] Unit tests for window arithmetic: month boundaries, billing-day resets, rolling windows, configurable timezones
- [ ] Concurrency test: 100 simultaneous calls on a trunk limited to 10 -> exactly 10 pass
- [ ] Cap-hit tests: failover to second trunk; clean rejection when all capped; max-duration cutoff
- [ ] Crash test: kill Asterisk mid-call; reservations cleaned up
- [ ] Accuracy test: a week of real traffic, compare counters with provider's numbers

### Phase 6: Deploy and maintain (week 8+)
- [ ] Pilot on one low-risk trunk with generous margins; widen gradually
- [ ] Backup before every deploy; one-command rollback
- [ ] Decide update strategy: deploy from our fork **or** use upstream `protected/commands/update.sh`, not both. Note that script updates existing MB8 installs only.

## 9. Definition of done (milestone 1)

- Cap policies can be created and edited per trunk from the panel.
- No trunk exceeds its configured cap (including under concurrent load) in testing.
- Capped trunks are skipped and traffic fails over correctly; all-capped case rejects cleanly with a visible cause.
- Counters match provider-side figures within an agreed tolerance over a pilot week.
- Warnings fire at thresholds; overrides and resets are audited.
- Migrations apply cleanly on a fresh install and on an existing install.
- Docs added for admins; changes isolated and documented for easy upstream merges.

## 10. Open questions (resolve early)

1. For each provider: are caps in **minutes, call attempts, or both**? Hard cutoff or overage charges? (Drives default `on_limit` and margin.)
2. Provider **rounding/billing increments** per trunk (needed for accurate counters).
3. Do we count **failed/unanswered attempts** toward call limits?
4. Expected **peak simultaneous calls** (to size production and decide whether Asterisk is sufficient; FreeSWITCH was considered and deferred).
5. Is this for internal operation only, or a product we may distribute (licensing implications)?
6. Notification channels for warnings (email, Telegram, both).
7. Production topology: single server vs standby for high availability.

## 11. Out of scope (for now)

- Merging with FreePBX (rejected: both own Asterisk config; very different architectures).
- Migrating to FreeSWITCH (revisit only if scale or licensing demands it).
- Any change that rewrites core billing logic beyond the cap hooks.
