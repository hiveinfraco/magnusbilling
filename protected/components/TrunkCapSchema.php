<?php
/**
 * Trunk cap engine: schema migrations.
 *
 * Kept on its own version track (pkg_configuration.trunkcaps_schema_version)
 * so it never collides with upstream's linear 8.0.0.x chain in
 * UpdateMysqlCommand. Every step is idempotent; add new steps at the end of
 * steps() and never edit a released one.
 *
 * This software is released under the terms of the GNU Lesser General Public License v3
 * A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 */
class TrunkCapSchema
{
    const VERSION_KEY = 'trunkcaps_schema_version';
    const CRON_FILE   = '/etc/cron.d/magnusbilling-trunkcaps';

    /** @var CDbConnection */
    private $db;
    private $log;

    public function __construct($db = null, $log = null)
    {
        $this->db  = $db ?: Yii::app()->db;
        $this->log = $log ?: function ($message, $error = false) {
            fwrite($error ? STDERR : STDOUT, '[TrunkCaps] ' . $message . PHP_EOL);
        };
    }

    /**
     * Entry point called from UpdateMysqlCommand. Never lets a trunk cap
     * migration failure abort the upstream update.
     */
    public static function migrate()
    {
        try {
            (new self())->up();
        } catch (Exception $e) {
            fwrite(STDERR, '[TrunkCaps] Migration failed: ' . $e->getMessage() . PHP_EOL);
        }
    }

    public function latestVersion()
    {
        return count($this->steps());
    }

    public function currentVersion()
    {
        $value = $this->db->createCommand(
            'SELECT config_value FROM pkg_configuration WHERE config_key = :key LIMIT 1'
        )->queryScalar([':key' => self::VERSION_KEY]);

        return $value === false ? 0 : (int) $value;
    }

    public function up()
    {
        $current = $this->currentVersion();
        $steps   = $this->steps();

        foreach ($steps as $i => $step) {
            $version = $i + 1;
            if ($version <= $current) {
                continue;
            }
            $this->say('Applying trunk caps schema step ' . $version . '.');
            call_user_func($step);
            $this->setVersion($version);
        }

        $this->say('Trunk caps schema is at version ' . $this->currentVersion() . '.');
    }

    /**
     * Drops every trunk cap table and the version key. Destroys all cap
     * policies, counters and the audit log.
     */
    public function down()
    {
        foreach (array_keys(self::modules()) as $module) {
            $this->db->createCommand(
                'DELETE gm FROM pkg_group_module gm JOIN pkg_module m ON m.id = gm.id_module WHERE m.module = :module'
            )->execute([':module' => $module]);
            $this->db->createCommand('DELETE FROM pkg_module WHERE module = :module')->execute([':module' => $module]);
        }
        $this->say('Removed the Trunk Caps menu entries.');

        foreach ([
            'pkg_trunk_cap_event',
            'pkg_trunk_cap_reservation',
            'pkg_trunk_cap_lock',
            'pkg_trunk_cap_minute',
            'pkg_trunk_cap_usage',
            'pkg_trunk_cap_policy',
        ] as $table) {
            $this->db->createCommand('DROP TABLE IF EXISTS `' . $table . '`')->execute();
            $this->say('Dropped ' . $table . '.');
        }

        $this->db->createCommand("DELETE FROM pkg_configuration WHERE config_key IN (:key, 'trunkcaps_alert_email')")
            ->execute([':key' => self::VERSION_KEY]);
        $this->say('Removed ' . self::VERSION_KEY . ' and trunkcaps_alert_email.');

        if (file_exists(self::CRON_FILE) && @unlink(self::CRON_FILE)) {
            $this->say('Removed ' . self::CRON_FILE . '.');
        }
    }

    private function steps()
    {
        return [
            [$this, 'step1CreateTables'],
            [$this, 'step2ReservationAccounting'],
            [$this, 'step3SweepAndAlerts'],
            [$this, 'step4PanelModules'],
        ];
    }

    /** Admin menu entries under Routes, the policy pause column. */
    private function step4PanelModules()
    {
        if (! $this->columnExists('pkg_trunk_cap_policy', 'override_until')) {
            $this->db->createCommand(
                "ALTER TABLE `pkg_trunk_cap_policy` ADD `override_until` datetime DEFAULT NULL
                 COMMENT 'UTC; policy not enforced until then' AFTER `strict`"
            )->execute();
        }

        foreach (self::modules() as $module => $m) {
            $this->db->createCommand(
                "INSERT INTO pkg_module (text, module, icon_cls, id_module, priority)
                 SELECT :text, :module, :icon, 10, :priority FROM DUAL
                 WHERE NOT EXISTS (SELECT 1 FROM pkg_module WHERE module = :existing)"
            )->execute([':text' => $m[0], ':module' => $module, ':icon' => $m[1], ':priority' => $m[2], ':existing' => $module]);

            $this->db->createCommand(
                "INSERT INTO pkg_group_module (id_group, id_module, action, show_menu, createShortCut, createQuickStart)
                 SELECT 1, m.id, :action, 1, 0, 0 FROM pkg_module m
                 WHERE m.module = :module
                   AND EXISTS (SELECT 1 FROM pkg_group_user g WHERE g.id = 1)
                   AND NOT EXISTS (SELECT 1 FROM pkg_group_module gm WHERE gm.id_group = 1 AND gm.id_module = m.id)"
            )->execute([':action' => $m[3], ':module' => $module]);
        }
        $this->say('Added the Trunk Caps menu entries for the administrator group. Users must log in again to see them.');
    }

    /** module => [menu text, icon, priority under Routes, admin action] */
    private static function modules()
    {
        return [
            'trunkcappolicy' => ["t('Trunk Caps')", 'x-fa fa-tachometer', 4, 'crud'],
            'trunkcapusage'  => ["t('Trunk Cap Usage')", 'x-fa fa-bar-chart', 5, 'r'],
            'trunkcapevent'  => ["t('Trunk Cap Log')", 'x-fa fa-list', 6, 'r'],
        ];
    }

    /**
     * Warning e-mail bookkeeping, the alert address setting, and the
     * per-minute sweeper cron entry.
     */
    private function step3SweepAndAlerts()
    {
        if (! $this->columnExists('pkg_trunk_cap_event', 'notified_at')) {
            $this->db->createCommand(
                'ALTER TABLE `pkg_trunk_cap_event` ADD `notified_at` datetime DEFAULT NULL AFTER `id_user`,
                 ADD KEY `idx_event_notified` (`event`, `notified_at`)'
            )->execute();
        }

        $this->db->createCommand(
            "INSERT INTO pkg_configuration
                (config_title, config_key, config_value, config_description, config_group_title, status)
             SELECT 'Trunk caps alert e-mail', 'trunkcaps_alert_email', '',
                'Comma-separated addresses that receive trunk cap warnings. Uses the admin SMTP settings.', 'global', 1
             FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM pkg_configuration WHERE config_key = 'trunkcaps_alert_email')"
        )->execute();

        $this->installCron();
    }

    private function installCron()
    {
        if (! is_dir(dirname(self::CRON_FILE)) || ! is_writable(dirname(self::CRON_FILE))) {
            $this->say('Skipped ' . self::CRON_FILE . ' (directory not writable). Schedule "php cron.php TrunkCapSweep" every minute by hand.');
            return;
        }
        file_put_contents(
            self::CRON_FILE,
            "# MagnusBilling trunk caps: release stale reservations, send cap warnings\n"
                . "* * * * * root flock -n /tmp/trunkcaps-sweep.lock php /var/www/html/mbilling/cron.php TrunkCapSweep >/dev/null 2>&1\n"
        );
        chmod(self::CRON_FILE, 0644);
        $this->say('Installed ' . self::CRON_FILE . '.');
    }

    /**
     * In-flight call counts per window, per-call maximum duration, and the
     * per-policy reservation mode (adaptive | full).
     */
    private function step2ReservationAccounting()
    {
        foreach (['pkg_trunk_cap_usage', 'pkg_trunk_cap_minute'] as $table) {
            if (! $this->columnExists($table, 'reserved_calls')) {
                $this->db->createCommand(
                    "ALTER TABLE `$table` ADD `reserved_calls` int unsigned NOT NULL DEFAULT '0' AFTER `attempts`"
                )->execute();
            }
        }
        if (! $this->columnExists('pkg_trunk_cap_policy', 'reserve_mode')) {
            $this->db->createCommand(
                "ALTER TABLE `pkg_trunk_cap_policy` ADD `reserve_mode` enum('adaptive','full') NOT NULL DEFAULT 'adaptive'
                 COMMENT 'full = reserve each call''s maximum duration; never overshoots' AFTER `on_limit`"
            )->execute();
        }
        if (! $this->columnExists('pkg_trunk_cap_reservation', 'max_seconds')) {
            $this->db->createCommand(
                "ALTER TABLE `pkg_trunk_cap_reservation` ADD `max_seconds` int unsigned NOT NULL DEFAULT '0'
                 COMMENT 'dial time limit given to the call' AFTER `expected_seconds`"
            )->execute();
        }
    }

    private function columnExists($table, $column)
    {
        return (int) $this->db->createCommand(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
        )->queryScalar([':t' => $table, ':c' => $column]) > 0;
    }

    private function step1CreateTables()
    {
        $this->db->createCommand(
            "CREATE TABLE IF NOT EXISTS `pkg_trunk_cap_policy` (
              `id` int NOT NULL AUTO_INCREMENT,
              `id_trunk` int NOT NULL,
              `name` varchar(100) NOT NULL,
              `active` tinyint(1) NOT NULL DEFAULT '1',
              `period_type` enum('hour','day','week','month','custom') NOT NULL DEFAULT 'month',
              `period_length_sec` int DEFAULT NULL COMMENT 'custom periods only',
              `anchor` enum('calendar','billing_day','rolling') NOT NULL DEFAULT 'calendar',
              `reset_day` tinyint DEFAULT NULL COMMENT 'billing_day: day of month (month) or ISO weekday (week)',
              `timezone` varchar(64) NOT NULL DEFAULT 'Africa/Lagos',
              `window_start` time DEFAULT NULL COMMENT 'time-of-day window; NULL = all day',
              `window_end` time DEFAULT NULL,
              `weekdays` set('mon','tue','wed','thu','fri','sat','sun') DEFAULT NULL COMMENT 'NULL = every day',
              `max_calls` int DEFAULT NULL,
              `max_calls_per_hour` int DEFAULT NULL,
              `max_minutes` int DEFAULT NULL,
              `max_concurrent` int DEFAULT NULL,
              `max_cps` int DEFAULT NULL,
              `count_failed_attempts` tinyint(1) NOT NULL DEFAULT '0',
              `safety_margin_pct` tinyint unsigned NOT NULL DEFAULT '100',
              `warn_pct` varchar(50) NOT NULL DEFAULT '80,90',
              `on_limit` enum('skip_trunk','reject') NOT NULL DEFAULT 'skip_trunk',
              `rounding_init_sec` int DEFAULT NULL COMMENT 'NULL = provider buy-rate initblock',
              `rounding_inc_sec` int DEFAULT NULL COMMENT 'NULL = provider buy-rate increment',
              `strict` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = block the trunk if the cap check fails',
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_trunk_active` (`id_trunk`, `active`),
              CONSTRAINT `fk_pkg_trunk_cap_policy_pkg_trunk` FOREIGN KEY (`id_trunk`) REFERENCES `pkg_trunk` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3"
        )->execute();

        $this->db->createCommand(
            "CREATE TABLE IF NOT EXISTS `pkg_trunk_cap_usage` (
              `id_policy` int NOT NULL,
              `window_start` datetime NOT NULL COMMENT 'UTC',
              `calls` int unsigned NOT NULL DEFAULT '0',
              `attempts` int unsigned NOT NULL DEFAULT '0',
              `billed_seconds` bigint unsigned NOT NULL DEFAULT '0',
              `reserved_seconds` bigint unsigned NOT NULL DEFAULT '0',
              `warned_pct` tinyint unsigned NOT NULL DEFAULT '0' COMMENT 'highest warning threshold already sent',
              `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id_policy`, `window_start`),
              CONSTRAINT `fk_pkg_trunk_cap_usage_policy` FOREIGN KEY (`id_policy`) REFERENCES `pkg_trunk_cap_policy` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3"
        )->execute();

        $this->db->createCommand(
            "CREATE TABLE IF NOT EXISTS `pkg_trunk_cap_minute` (
              `id_policy` int NOT NULL,
              `minute_start` datetime NOT NULL COMMENT 'UTC',
              `calls` int unsigned NOT NULL DEFAULT '0',
              `attempts` int unsigned NOT NULL DEFAULT '0',
              `billed_seconds` bigint unsigned NOT NULL DEFAULT '0',
              `reserved_seconds` bigint unsigned NOT NULL DEFAULT '0',
              PRIMARY KEY (`id_policy`, `minute_start`),
              KEY `idx_minute_start` (`minute_start`),
              CONSTRAINT `fk_pkg_trunk_cap_minute_policy` FOREIGN KEY (`id_policy`) REFERENCES `pkg_trunk_cap_policy` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3"
        )->execute();

        $this->db->createCommand(
            "CREATE TABLE IF NOT EXISTS `pkg_trunk_cap_lock` (
              `id_trunk` int NOT NULL,
              PRIMARY KEY (`id_trunk`),
              CONSTRAINT `fk_pkg_trunk_cap_lock_pkg_trunk` FOREIGN KEY (`id_trunk`) REFERENCES `pkg_trunk` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3"
        )->execute();

        $this->db->createCommand(
            "CREATE TABLE IF NOT EXISTS `pkg_trunk_cap_reservation` (
              `id` bigint unsigned NOT NULL AUTO_INCREMENT,
              `id_trunk` int NOT NULL,
              `uniqueid` varchar(50) NOT NULL DEFAULT '',
              `expected_seconds` int unsigned NOT NULL DEFAULT '0',
              `allocations` text COMMENT 'JSON: usage rows and minute buckets charged per policy',
              `created_at` datetime(3) NOT NULL COMMENT 'UTC',
              `expires_at` datetime NOT NULL COMMENT 'UTC; sweeper releases after this',
              `released_at` datetime DEFAULT NULL,
              `dialstatus` varchar(20) DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_trunk_open` (`id_trunk`, `released_at`),
              KEY `idx_trunk_created` (`id_trunk`, `created_at`),
              KEY `idx_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3"
        )->execute();

        $this->db->createCommand(
            "CREATE TABLE IF NOT EXISTS `pkg_trunk_cap_event` (
              `id` bigint unsigned NOT NULL AUTO_INCREMENT,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `id_trunk` int NOT NULL,
              `id_policy` int DEFAULT NULL,
              `event` enum('warned','blocked','reset','override','swept') NOT NULL,
              `snapshot` text COMMENT 'JSON usage snapshot',
              `uniqueid` varchar(50) DEFAULT NULL,
              `id_user` int DEFAULT NULL COMMENT 'panel user for manual actions',
              PRIMARY KEY (`id`),
              KEY `idx_trunk_created` (`id_trunk`, `created_at`),
              KEY `idx_policy_created` (`id_policy`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3"
        )->execute();
    }

    private function setVersion($version)
    {
        $exists = $this->db->createCommand(
            'SELECT COUNT(*) FROM pkg_configuration WHERE config_key = :key'
        )->queryScalar([':key' => self::VERSION_KEY]);

        if ((int) $exists === 0) {
            $this->db->createCommand(
                "INSERT INTO pkg_configuration
                    (config_title, config_key, config_value, config_description, config_group_title, status)
                 VALUES ('Trunk caps schema version', :key, :value,
                    'Internal: schema version of the trunk cap engine.', 'global', 0)"
            )->execute([':key' => self::VERSION_KEY, ':value' => (string) $version]);
        } else {
            $this->db->createCommand(
                'UPDATE pkg_configuration SET config_value = :value WHERE config_key = :key'
            )->execute([':key' => self::VERSION_KEY, ':value' => (string) $version]);
        }
    }

    private function say($message)
    {
        call_user_func($this->log, $message);
    }
}
