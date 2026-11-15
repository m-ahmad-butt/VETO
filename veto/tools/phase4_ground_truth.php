<?php
// Evaluation witness: direct reads only. Does not load WordPress or observer code.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli('database', 'veto', getenv('VETO_DB_PASSWORD'), 'veto');
$db->set_charset('utf8mb4');
$receipt = '/var/www/html/wp-content/plugins/submission/phase4-receipts.json';
$p = '/var/www/html/wp-content/phase4-persistent.txt';
$r = array('method'=>'Direct PHP file reads and SQL, no WordPress bootstrap or VETO observer',
    'receipts'=>is_file($receipt) ? json_decode(file_get_contents($receipt), true, 512, JSON_THROW_ON_ERROR) : array(),
    'persistent_file'=>is_file($p) ? file_get_contents($p) : null,
    'transient_exists'=>file_exists('/var/www/html/wp-content/phase4-transient.txt'));
$options = array();
foreach (array('phase4_value','phase4_cron_executed','cron') as $name) {
    $stmt = $db->prepare('SELECT option_value FROM wp_options WHERE option_name=?');
    $stmt->bind_param('s',$name); $stmt->execute();
    $row = $stmt->get_result()->fetch_row(); $options[$name] = $row ? $row[0] : null;
}
$r['options'] = $options;
$r['accounts'] = $db->query("SELECT u.user_login,m.meta_value FROM wp_users u JOIN wp_usermeta m ON m.user_id=u.ID WHERE u.user_login='phase4_admin' AND m.meta_key='wp_capabilities'")->fetch_all(MYSQLI_ASSOC);
echo json_encode($r, JSON_THROW_ON_ERROR);
