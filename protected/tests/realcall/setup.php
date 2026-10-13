<?php
/**
 * Trunk caps real-call test: create or refresh the tcrc-* test records on a
 * DEV MagnusBilling install, then regenerate the PJSIP config the same way
 * the panel does.
 *
 *   php protected/tests/realcall/setup.php          (run as root on the dev VM)
 *
 * Creates: provider tcrc-provider; trunks tcrc-a (127.0.0.1:5091) and
 * tcrc-b (127.0.0.1:5092), each answered by a SIPp fake provider; trunk
 * group tcrc-group (order); plan tcrc-plan; test prefix 9997 rated to that
 * group; provider buy rate 60/60; customer tcrc with a SIP account. Secrets
 * are random and written to /root/tcrc.env (mode 600), never printed.
 *
 * NEVER run on production: it writes Asterisk config and test records.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = realpath(__DIR__ . '/../../..');
require_once $root . '/yii/framework/yii.php';
$config = require $root . '/protected/config/cron.php';
Yii::createConsoleApplication($config);
chdir($root);

const PREFIX = '9997';
$db = Yii::app()->db;

function one($sql, $params = [])
{
    return Yii::app()->db->createCommand($sql)->queryScalar($params);
}

function run($sql, $params = [])
{
    return Yii::app()->db->createCommand($sql)->execute($params);
}

function idOf($table, $column, $value)
{
    $id = one("SELECT id FROM $table WHERE $column = :v LIMIT 1", [':v' => $value]);
    return $id === false ? null : (int) $id;
}

$env = is_readable('/root/tcrc.env') ? parse_ini_file('/root/tcrc.env') : [];
$secret = isset($env['TCRC_SIP_PASS']) ? $env['TCRC_SIP_PASS'] : bin2hex(random_bytes(12));

// Provider, trunks, group
if (! idOf('pkg_provider', 'provider_name', 'tcrc-provider')) {
    run("INSERT INTO pkg_provider (provider_name, description, credit_control) VALUES ('tcrc-provider', 'Trunk caps real-call test (SIPp fake upstream)', 0)");
}
$provider = idOf('pkg_provider', 'provider_name', 'tcrc-provider');

foreach (['tcrc-a' => 5091, 'tcrc-b' => 5092] as $code => $port) {
    if (! idOf('pkg_trunk', 'trunkcode', $code)) {
        run("INSERT INTO pkg_trunk (id_provider, trunkcode, host, fromdomain, providertech, providerip, user, secret, allow,
                link_sms, sms_res, language, insecure, qualify, port, status, context)
             VALUES (:p, :c, '127.0.0.1', '127.0.0.1', 'pjsip', '127.0.0.1', '', '', 'ulaw,alaw', '', '', 'en', 'port,invite', 'no', :port, 1, 'billing')",
            [':p' => $provider, ':c' => $code, ':port' => (string) $port]);
    }
}
$trunkA = idOf('pkg_trunk', 'trunkcode', 'tcrc-a');
$trunkB = idOf('pkg_trunk', 'trunkcode', 'tcrc-b');

if (! idOf('pkg_trunk_group', 'name', 'tcrc-group')) {
    run("INSERT INTO pkg_trunk_group (name, type, description) VALUES ('tcrc-group', 1, 'Trunk caps real-call test')");
}
$group = idOf('pkg_trunk_group', 'name', 'tcrc-group');
run('DELETE FROM pkg_trunk_group_trunk WHERE id_trunk_group = :g', [':g' => $group]);
run('INSERT INTO pkg_trunk_group_trunk (id_trunk_group, id_trunk) VALUES (:g, :a), (:g, :b)', [':g' => $group, ':a' => $trunkA, ':b' => $trunkB]);

// Plan, prefix, sell rate, provider buy rate (60/60)
if (! idOf('pkg_plan', 'name', 'tcrc-plan')) {
    run("INSERT INTO pkg_plan (name) VALUES ('tcrc-plan')");
}
$plan = idOf('pkg_plan', 'name', 'tcrc-plan');
if (! idOf('pkg_prefix', 'prefix', PREFIX)) {
    run("INSERT INTO pkg_prefix (prefix, destination) VALUES (:p, 'TCRC TEST')", [':p' => PREFIX]);
}
$prefix = idOf('pkg_prefix', 'prefix', PREFIX);
run('DELETE FROM pkg_rate WHERE id_plan = :plan AND id_prefix = :prefix', [':plan' => $plan, ':prefix' => $prefix]);
run('INSERT INTO pkg_rate (id_plan, id_trunk_group, id_prefix, rateinitial, initblock, billingblock, status)
     VALUES (:plan, :g, :prefix, 0.01, 1, 1, 1)', [':plan' => $plan, ':g' => $group, ':prefix' => $prefix]);
run('DELETE FROM pkg_rate_provider WHERE id_provider = :p AND id_prefix = :prefix', [':p' => $provider, ':prefix' => $prefix]);
run('INSERT INTO pkg_rate_provider (id_provider, id_prefix, buyrate, buyrateinitblock, buyrateincrement)
     VALUES (:p, :prefix, 0.005, 60, 60)', [':p' => $provider, ':prefix' => $prefix]);

// Customer and SIP account
if (! idOf('pkg_user', 'username', 'tcrc')) {
    run("INSERT INTO pkg_user (id_group, username, password, callingcard_pin, id_plan, credit, active, firstname)
         VALUES (3, 'tcrc', :pw, :pin, :plan, 100000, 1, 'Trunk caps test')",
        [':pw' => bin2hex(random_bytes(10)), ':pin' => random_int(100000, 999999), ':plan' => $plan]);
}
run("UPDATE pkg_user SET id_plan = :plan, credit = 100000, active = 1 WHERE username = 'tcrc'", [':plan' => $plan]);
$user = idOf('pkg_user', 'username', 'tcrc');

if (! idOf('pkg_sip', 'name', 'tcrc-sip')) {
    run("INSERT INTO pkg_sip (id_user, name, accountcode, defaultuser, secret, host, context, allow, insecure, callerid)
         VALUES (:u, 'tcrc-sip', 'tcrc', 'tcrc-sip', :s, 'dynamic', 'billing', 'ulaw,alaw', 'no', '15550001')",
        [':u' => $user, ':s' => $secret]);
}
run("UPDATE pkg_sip SET secret = :s, id_user = :u WHERE name = 'tcrc-sip'", [':s' => $secret, ':u' => $user]);

file_put_contents('/root/tcrc.env', "TCRC_SIP_USER=tcrc-sip\nTCRC_SIP_PASS=$secret\nTCRC_PREFIX=" . PREFIX
    . "\nTCRC_TRUNK_A=$trunkA\nTCRC_TRUNK_B=$trunkB\nTCRC_GROUP=$group\n");
chmod('/root/tcrc.env', 0600);

// Regenerate PJSIP config exactly like the panel (SipController / TrunkController)
$_SERVER['HTTP_HOST'] = 'tcrc-setup';
AsteriskAccess::instance()->generateSipPeers();
$trunks = Trunk::model()->findAll([
    'select'    => 'trunkcode, user, secret, disallow, allow, directmedia, context, dtmfmode, insecure, nat, qualify, type, host, fromdomain,fromuser, register_string,port,transport,encryption,sendrpid,maxuse,sip_config',
    'condition' => 'providertech = :key AND status = 1',
    'params'    => [':key' => 'pjsip'],
]);
AsteriskAccess::instance()->writeAsteriskFile($trunks, '/etc/asterisk/pjsip_magnus.conf', 'trunkcode');
passthru("asterisk -rx 'module reload res_pjsip.so' >/dev/null 2>&1");

echo "tcrc test records ready: trunk A=$trunkA, B=$trunkB, group=$group, plan=$plan, prefix " . PREFIX . ", user=$user\n";
echo "Secrets are in /root/tcrc.env\n";
