<?php
/**
 * Trunk cap engine: manual schema control.
 *
 *   php cron.php TrunkCapMigrate            same as "status"
 *   php cron.php TrunkCapMigrate up         apply pending steps (also run by UpdateMysql)
 *   php cron.php TrunkCapMigrate down confirm   drop all trunk cap tables and data
 *
 * This software is released under the terms of the GNU Lesser General Public License v3
 * A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 */
class TrunkCapMigrateCommand extends CConsoleCommand
{
    public function run($args)
    {
        $action = isset($args[0]) ? $args[0] : 'status';
        $schema = new TrunkCapSchema();

        switch ($action) {
            case 'status':
                echo 'Trunk caps schema version ' . $schema->currentVersion()
                    . ' (latest ' . $schema->latestVersion() . ')' . PHP_EOL;
                return 0;

            case 'up':
                $schema->up();
                return 0;

            case 'down':
                if (! isset($args[1]) || $args[1] !== 'confirm') {
                    fwrite(STDERR, 'This drops all trunk cap policies, counters and the audit log.' . PHP_EOL
                        . 'Run "php cron.php TrunkCapMigrate down confirm" to proceed.' . PHP_EOL);
                    return 1;
                }
                $schema->down();
                return 0;
        }

        fwrite(STDERR, 'Unknown action "' . $action . '". Use status, up or down.' . PHP_EOL);
        return 1;
    }
}
