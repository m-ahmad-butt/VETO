<?php
// Direct SQL snapshot: never load WordPress or submitted plugin code.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli('database', 'veto', getenv('VETO_DB_PASSWORD'), 'veto');
$db->set_charset('utf8mb4');
$db->query('SET SESSION MAX_EXECUTION_TIME=5000');
$db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$db->query('START TRANSACTION WITH CONSISTENT SNAPSHOT');
$result = array('tables' => array(), 'accounts' => array(), 'cron' => array(), 'option_hashes' => array());
$tables = $db->query('SHOW TABLES')->fetch_all(MYSQLI_NUM);
if (count($tables) > 100) throw new Exception('Database table limit exceeded');
$totalRows = 0;
foreach ($tables as $table) {
    $name = $table[0];
    $quoted = '`' . str_replace('`', '``', $name) . '`';
    $rows = $db->query("SELECT * FROM $quoted LIMIT 5001");
    $hashes = array();
    while ($row = $rows->fetch_assoc()) {
        if (++$totalRows > 10000 || count($hashes) >= 5000) throw new Exception('Database row limit exceeded');
        $encoded = json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE);
        if (strlen($encoded) > 1048576) throw new Exception('Database row byte limit exceeded');
        $hashes[] = hash('sha256', $encoded);
    }
    sort($hashes);
    $schema = $db->query("SHOW CREATE TABLE $quoted")->fetch_row()[1];
    $result['tables'][$name] = array('rows' => count($hashes), 'sha256' => hash('sha256', implode('', $hashes)), 'schema_sha256' => hash('sha256', $schema));
}
$users = $db->query("SELECT ID,user_login,user_email FROM wp_users ORDER BY ID");
while ($user = $users->fetch_assoc()) {
    $id = (int)$user['ID'];
    $roles = $db->query("SELECT meta_value FROM wp_usermeta WHERE user_id=$id AND meta_key='wp_capabilities'")->fetch_row();
    $user['roles'] = $roles ? $roles[0] : '';
    $result['accounts'][(string)$id] = $user;
}
$cronRow = $db->query("SELECT option_value FROM wp_options WHERE option_name='cron'")->fetch_row();
$options = $db->query('SELECT option_name,option_value FROM wp_options LIMIT 5001');
while ($option = $options->fetch_assoc()) {
    $result['option_hashes'][$option['option_name']] = hash('sha256', $option['option_value']);
}
$cron = $cronRow ? @unserialize($cronRow[0], array('allowed_classes' => false)) : array();
if (is_array($cron)) foreach ($cron as $time => $hooks) {
    if (!is_array($hooks)) continue;
    foreach ($hooks as $hook => $instances) foreach ($instances as $key => $event) {
        $result['cron'][$time . ':' . $hook . ':' . $key] = $event;
    }
}
$db->commit();
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
