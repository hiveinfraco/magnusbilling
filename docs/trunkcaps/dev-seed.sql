-- Trunk caps: development seed data. DEV VM ONLY, never production.
--
-- Creates a fake provider, two trunks pointing at the fake-upstream Asterisk,
-- one ordered trunk group, and a set of cap policies that exercise each limit
-- type. Safe to re-run: rows are matched by name.
--
-- Usage:
--   mysql mbilling < docs/trunkcaps/dev-seed.sql
-- Then open each tc-fake-* trunk in the panel and press Save so MagnusBilling
-- regenerates pjsip_magnus.conf (SQL inserts do not write Asterisk config).
--
-- Set @fake_upstream to the fake-provider VM's IP before running.

SET @fake_upstream = '192.0.2.10';  -- TEST-NET placeholder; replace on the dev VM

INSERT INTO pkg_provider (provider_name, description)
SELECT 'tc-fake-provider', 'Trunk caps dev: fake upstream Asterisk'
WHERE NOT EXISTS (SELECT 1 FROM pkg_provider WHERE provider_name = 'tc-fake-provider');
SET @provider = (SELECT id FROM pkg_provider WHERE provider_name = 'tc-fake-provider');

INSERT INTO pkg_trunk (id_provider, trunkcode, host, fromdomain, providertech, providerip,
    user, secret, allow, link_sms, sms_res, language, insecure)
SELECT @provider, t.code, @fake_upstream, @fake_upstream, 'pjsip', @fake_upstream,
    '', '', 'ulaw,alaw', '', '', 'en', 'port,invite'
FROM (SELECT 'tc-fake-a' AS code UNION ALL SELECT 'tc-fake-b') t
WHERE NOT EXISTS (SELECT 1 FROM pkg_trunk WHERE trunkcode = t.code);
SET @trunk_a = (SELECT id FROM pkg_trunk WHERE trunkcode = 'tc-fake-a');
SET @trunk_b = (SELECT id FROM pkg_trunk WHERE trunkcode = 'tc-fake-b');

-- type 1 = ordered: tc-fake-a first, fail over to tc-fake-b
INSERT INTO pkg_trunk_group (name, type, description)
SELECT 'tc-fake-group', 1, 'Trunk caps dev: A then B'
WHERE NOT EXISTS (SELECT 1 FROM pkg_trunk_group WHERE name = 'tc-fake-group');
SET @group = (SELECT id FROM pkg_trunk_group WHERE name = 'tc-fake-group');

INSERT INTO pkg_trunk_group_trunk (id_trunk_group, id_trunk)
SELECT @group, x.id_trunk
FROM (SELECT @trunk_a AS id_trunk, 1 AS ord UNION ALL SELECT @trunk_b, 2) x
WHERE NOT EXISTS (
    SELECT 1 FROM pkg_trunk_group_trunk WHERE id_trunk_group = @group AND id_trunk = x.id_trunk
)
ORDER BY x.ord;

-- Policies. Small limits so they trip quickly in tests.
INSERT INTO pkg_trunk_cap_policy
    (id_trunk, name, period_type, anchor, reset_day, timezone, window_start, window_end, weekdays,
     max_calls, max_calls_per_hour, max_minutes, max_concurrent, max_cps,
     count_failed_attempts, safety_margin_pct, warn_pct, on_limit, strict)
SELECT p.* FROM (
    -- A: monthly minutes on a billing day, plus concurrency and CPS
    SELECT @trunk_a AS id_trunk, 'A monthly minutes' AS name, 'month' AS period_type, 'billing_day' AS anchor,
           15 AS reset_day, 'Africa/Lagos' AS timezone, NULL AS window_start, NULL AS window_end, NULL AS weekdays,
           NULL AS max_calls, NULL AS max_calls_per_hour, 30 AS max_minutes, 3 AS max_concurrent, 2 AS max_cps,
           0 AS count_failed_attempts, 95 AS safety_margin_pct, '80,90' AS warn_pct, 'skip_trunk' AS on_limit, 0 AS strict
    UNION ALL
    -- A: rolling hourly call count, counting failed attempts
    SELECT @trunk_a, 'A rolling hour attempts', 'hour', 'rolling',
           NULL, 'Africa/Lagos', NULL, NULL, NULL,
           20, NULL, NULL, NULL, NULL,
           1, 100, '80', 'skip_trunk', 0
    UNION ALL
    -- B: weekday peak window, daily calls, reject when capped
    SELECT @trunk_b, 'B peak daily calls', 'day', 'calendar',
           NULL, 'Africa/Lagos', '08:00:00', '18:00:00', 'mon,tue,wed,thu,fri',
           10, 5, NULL, NULL, NULL,
           0, 100, '50,80', 'reject', 0
) p
WHERE NOT EXISTS (
    SELECT 1 FROM pkg_trunk_cap_policy WHERE id_trunk = p.id_trunk AND name = p.name
);
