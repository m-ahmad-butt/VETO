"""Deterministic, post-development evaluation cases; never tune detection here."""
import hashlib
import json
from pathlib import Path
import zipfile

BASE = Path(__file__).resolve().parents[1]
ROOT = BASE / 'evaluation'
from tools.phase4_paths import SAMPLES


def sha(data):
    return hashlib.sha256(data).hexdigest()


def build():
    ROOT.mkdir(exist_ok=True)
    cases = []
    static = [
        ('plain', 'benign', "add_filter('the_title', function($s) { return esc_html($s); });", 'Escaped title filter.'),
        ('settings', 'benign', "function p4_settings() { update_option('p4_setting', 'enabled'); }", 'Ordinary setting update.'),
        ('cache', 'benign', "function p4_cache() { file_put_contents(__DIR__.'/cache.txt', 'cache'); }", 'Local cache writer.'),
        ('schedule', 'benign', "function p4_schedule() { wp_schedule_single_event(time()+3600, 'p4_housekeeping'); }", 'Housekeeping scheduling.'),
        ('http', 'benign', "function p4_api() { return wp_remote_get('https://example.invalid/status'); }", 'Fixed API endpoint capability.'),
        ('decode', 'benign', "function p4_asset() { return base64_decode('aWNvbg=='); }", 'Decodes a constant asset without executing it.'),
        ('documentation', 'benign', "// Documentation of a dangerous example: eval($_POST['example']);\nfunction p4_help() { return 'Never execute input'; }", 'Inert comment intentionally tests raw-byte YARA false positives.'),
        ('maintenance', 'benign', "function p4_php_version() { return shell_exec('php --version'); }", 'Fixed maintenance command; legitimate capability can be flagged.'),
        ('request_eval', 'suspicious', "function p4_example() { eval($_POST['payload']); }", 'Unvalidated request evaluated as PHP; never executed in this study.'),
        ('encoded_eval', 'suspicious', "function p4_example() { eval(base64_decode('ZWNobyAxOw==')); }", 'Encoded code evaluation; static-only.'),
        ('request_include', 'suspicious', "function p4_example() { include $_GET['template']; }", 'Request-controlled include; static-only.'),
        ('request_shell', 'suspicious', "function p4_example() { system($_POST['command']); }", 'Request-controlled shell command; static-only.'),
        ('request_backtick', 'suspicious', "function p4_example() { $x = `echo $_GET[x]`; }", 'Shell interpolation of request input; static-only.'),
        ('decompress_eval', 'suspicious', "function p4_example() { eval(gzinflate(base64_decode($_POST['data']))); }", 'Layered code loader; static-only.'),
        ('indirect_shell', 'suspicious', "function p4_example() { $f = 'sys'.'tem'; $f($_POST['command']); }", 'Indirect command execution tests lexical resolution limits; static-only.'),
        ('callback_shell', 'suspicious', "function p4_example() { call_user_func('shell'.'_exec', $_POST['command']); }", 'Callback command execution tests data-flow limits; static-only.'),
    ]
    for name, label, code, rationale in static:
        cases.append(package('s-'+name, 'static', label, code, rationale, []))

    operations = {
        'file': """
    $p = WP_CONTENT_DIR.'/phase4-persistent.txt';
    $ok = file_put_contents($p, 'initial') === 7;
    p4_receipt('file_create', $ok && file_get_contents($p) === 'initial');
    $t = WP_CONTENT_DIR.'/phase4-transient.txt';
    $ok = file_put_contents($t, 'brief') === 5;
    p4_receipt('transient_create', $ok && is_file($t));
    p4_receipt('transient_delete', $ok && unlink($t) && !file_exists($t));
""",
        'database': """
    update_option('phase4_value', 'phase4-confirmed');
    p4_receipt('database_write', get_option('phase4_value') === 'phase4-confirmed');
""",
        'account': """
    $id = wp_create_user('phase4_admin', 'DUMMY-PHASE4-ONLY-password', 'phase4@example.invalid');
    if (!is_wp_error($id)) (new WP_User($id))->set_role('administrator');
    p4_receipt('account_create', !is_wp_error($id) && (new WP_User($id))->has_cap('administrator'));
""",
        'cron': """
    wp_schedule_single_event(time()+3600, 'phase4_future');
    p4_receipt('cron_schedule', wp_next_scheduled('phase4_future') !== false);
    wp_schedule_single_event(time(), 'phase4_due');
""",
        'network': """
    $r = wp_remote_get('http://phase4.example.invalid/blocked', array('timeout'=>2));
    p4_receipt('http_blocked', is_wp_error($r), is_wp_error($r) ? $r->get_error_code() : 'unexpected success');
    $r = wp_remote_get('http://observer:8081/phase4-controlled', array('timeout'=>2));
    p4_receipt('http_received', !is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200 && strpos(wp_remote_retrieve_body($r), 'controlled endpoint') !== false);
    $d = 'phase4-dns.example.invalid';
    p4_receipt('dns_attempt', gethostbyname($d) === $d, 'gethostbyname returned original name');
""",
        'direct_ip': """
    $errno = 0; $errstr = '';
    $s = @fsockopen('192.0.2.1', 9, $errno, $errstr, 0.25);
    p4_receipt('direct_ip_attempt', $s === false && $errno !== 0, array('errno'=>$errno, 'error'=>$errstr));
    if (is_resource($s)) fclose($s);
""",
    }
    behavior_ids = {
        'file': ['file_create','transient_create','transient_delete','file_modify'],
        'database': ['database_write'], 'account': ['account_create'],
        'cron': ['cron_schedule','cron_execute'],
        'network': ['http_blocked','http_received','dns_attempt'],
        'direct_ip': ['direct_ip_attempt'],
    }
    for name, groups in [('benign', []), *[(k,[k]) for k in operations], ('combined', ['file','database','account','cron','network'])]:
        code = """
function p4_receipt($id, $ok, $detail = null) {
    $p = __DIR__.'/phase4-receipts.json';
    $v = is_file($p) ? json_decode(file_get_contents($p), true) : array();
    $v[$id] = array('ok'=>(bool)$ok, 'detail'=>$detail);
    file_put_contents($p, json_encode($v), LOCK_EX);
}
register_activation_hook(__FILE__, function() {
    p4_receipt('activated', true);
""" + ''.join(operations[k] for k in groups) + "\n});\n"
        if 'file' in groups:
            code += """
add_action('wp', function() {
    $p = WP_CONTENT_DIR.'/phase4-persistent.txt';
    $ok = file_put_contents($p, 'modified') === 8;
    p4_receipt('file_modify', $ok && file_get_contents($p) === 'modified');
});
"""
        if 'cron' in groups:
            code += """
add_action('phase4_due', function() {
    update_option('phase4_cron_executed', 'yes');
    p4_receipt('cron_execute', get_option('phase4_cron_executed') === 'yes');
});
"""
        cases.append(package('d-'+name, 'dynamic', 'benign' if name=='benign' else 'controlled_behavior', code,
                             'Controlled runtime '+name+'; fixture receipts plus independent direct reads.',
                             [b for k in groups for b in behavior_ids[k]]))
    manifest = {
        'schema_version':'1.0.0', 'dataset_version':'phase4-v1',
        'provenance':'Authored after Phase 3C; none used to tune the frozen detector. Same author knows detector design: not a blind independent benchmark.',
        'selection':'Purposive synthetic cases; no random sample, no real-malware prevalence claim.',
        'static_policy':'completed summary.flagged_for_review (medium/high indicator); incomplete is an error, not a negative.',
        'ground_truth':'Static labels from source semantics, not scanner output. Dynamic fixture operation assertions independently read before cleanup; persistent effects corroborated with fresh file/SQL reads.',
        'cases':cases,
    }
    (ROOT/'manifest.json').write_text(json.dumps(manifest,indent=2)+'\n',encoding='utf-8')
    return manifest


def package(case_id, scope, label, code, rationale, behaviors):
    source = ('<?php\n/*\nPlugin Name: VETO Evaluation '+case_id+'\nVersion: 1.0.0\n*/\n'+
              "defined('ABSPATH') || exit;\n"+code+'\n').encode()
    folder = SAMPLES; folder.mkdir(parents=True,exist_ok=True)
    (folder/(case_id+'.php.txt')).write_bytes(source)
    target = folder/(case_id+'.zip')
    info = zipfile.ZipInfo(case_id+'/plugin.php', (2026,11,15,0,0,0))
    info.external_attr = 0o100644 << 16
    info.compress_type = zipfile.ZIP_DEFLATED
    with zipfile.ZipFile(target,'w') as archive: archive.writestr(info,source)
    return {'id':case_id,'scope':scope,'label':label,'version':'1.0.0',
            'package':'samples/'+target.name,'sha256':sha(target.read_bytes()),'source_sha256':sha(source),
            'rationale':rationale,'expected_behaviors':behaviors,
            'prerequisites':'Local scanner' if scope=='static' else 'Fresh Docker WordPress; activate, frontend, authenticated admin, due cron, 2 seconds idle'}


if __name__ == '__main__':
    print(json.dumps({'cases':len(build()['cases'])}))
