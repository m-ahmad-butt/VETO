"""Harmless, controlled runtime fixtures; only use inside VETO's test sandbox."""
import hashlib
import json
from pathlib import Path
import stat
import zipfile

BASE=Path(__file__).resolve().parents[1]
HEADER="<?php\n/*\nPlugin Name: VETO %s Controlled Runtime Fixture\nVersion: 1.0.0\n*/\ndefined('ABSPATH') || exit;\n"

BEHAVIORS=r'''
function veto_fixture_activation() {
    file_put_contents(WP_CONTENT_DIR . '/veto-persistent.txt', 'created');
    file_put_contents(WP_CONTENT_DIR . '/veto-transient.txt', 'brief evidence');
    unlink(WP_CONTENT_DIR . '/veto-transient.txt');
    update_option('veto_fixture_option', 'controlled database change');
    global $wpdb;
    $wpdb->query("CREATE TABLE IF NOT EXISTS veto_fixture_table (id INT PRIMARY KEY, message VARCHAR(80))");
    $wpdb->query("INSERT INTO veto_fixture_table VALUES (1, 'controlled direct SQL')");
    $id = wp_create_user('veto_fixture_admin', 'DUMMY-LOCAL-ONLY-password', 'fixture@example.invalid');
    if (!is_wp_error($id)) (new WP_User($id))->set_role('administrator');
    wp_schedule_single_event(time(), 'veto_fixture_cron');
    wp_remote_get('http://example.invalid/veto-blocked', array('timeout'=>2));
    wp_remote_get('http://observer:8081/veto-fixture-controlled', array('timeout'=>2));
    gethostbyname('veto-fixture-dns.example.invalid');
}
register_activation_hook(__FILE__, 'veto_fixture_activation');
add_action('veto_fixture_cron', function() {
    update_option('veto_fixture_cron_ran', 'yes');
    file_put_contents(WP_CONTENT_DIR . '/veto-cron.txt', 'cron executed');
});
add_action('wp', function() {
    file_put_contents(WP_CONTENT_DIR . '/veto-persistent.txt', 'modified on frontend');
    update_option('veto_fixture_frontend_ran', 'yes');
});
add_action('admin_init', function() {
    if (is_user_logged_in()) update_option('veto_fixture_admin_ran', 'yes');
});
'''

def build():
    dest=BASE/'fixtures/dynamic'
    dest.mkdir(parents=True,exist_ok=True)
    samples={
        'benign':HEADER%'Benign'+"add_shortcode('veto_demo', function() { return 'VETO benign'; });\n",
        'behaviors':HEADER%'Behaviors'+BEHAVIORS,
        'activation_error':HEADER%'Failure'+"register_activation_hook(__FILE__,function(){throw new RuntimeException('VETO controlled activation failure');});\n",
        'timeout':HEADER%'Timeout'+"register_activation_hook(__FILE__,function(){sleep(60);});\n",
        'monitor_limit':HEADER%'Monitor Limit'+"register_activation_hook(__FILE__,function(){file_put_contents(WP_CONTENT_DIR . '/veto-oversize.txt', str_repeat('x',17*1024*1024));});\n",
    }
    manifest={'purpose':'Controlled Phase 2 development fixtures; no production data or real malware','samples':[]}
    for name,source in samples.items():
        data=source.encode()
        target=dest/(name+'.zip')
        with zipfile.ZipFile(target,'w') as archive:
            info=zipfile.ZipInfo(name+'/main.php',(2026,1,1,0,0,0))
            info.create_system=3
            info.external_attr=(stat.S_IFREG|0o600)<<16
            info.compress_type=zipfile.ZIP_DEFLATED
            archive.writestr(info,data)
        (dest/(name+'.php.txt')).write_text(source,encoding='utf-8',newline='\n')
        manifest['samples'].append({'name':name,'zip_sha256':hashlib.sha256(target.read_bytes()).hexdigest(),'file_sha256':hashlib.sha256(data).hexdigest()})
    (dest/'manifest.json').write_text(json.dumps(manifest,indent=2)+'\n',encoding='utf-8')
    return manifest

if __name__=='__main__':
    build()
    print('Built five controlled dynamic fixtures')
