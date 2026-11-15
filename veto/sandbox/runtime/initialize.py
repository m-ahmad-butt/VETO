"""Trusted volume initialization, before any submitted plugin is present."""
import os
from pathlib import Path
import shutil

site = Path('/site')
shutil.copytree('/usr/src/wordpress', site, dirs_exist_ok=True)
(site / 'wp-content/plugins').mkdir(exist_ok=True)
# Remove bundled plugins/themes from the baseline; retain one stock theme.
for item in (site / 'wp-content/plugins').iterdir():
    if item.is_dir():
        shutil.rmtree(item)
    elif item.suffix == '.php' and item.name != 'index.php':
        item.unlink()
(site / 'wp-content/mu-plugins').mkdir(exist_ok=True)
guard = Path('/guard')
shutil.copy('/opt/veto/hooks.php', guard / '00-veto.php')
config = '''<?php
define('DB_NAME', 'veto');
define('DB_USER', 'veto');
define('DB_PASSWORD', getenv('VETO_DB_PASSWORD'));
define('DB_HOST', 'database');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
$table_prefix = 'wp_';
define('WP_HOME', 'http://wordpress:8080');
define('WP_SITEURL', 'http://wordpress:8080');
define('DISABLE_WP_CRON', true);
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_AUTO_UPDATE_CORE', false);
define('DISALLOW_FILE_EDIT', true);
define('WP_DEBUG', false);
define('WP_ENVIRONMENT_TYPE', 'local');
define('AUTH_KEY', 'VETO-DUMMY-AUTH-KEY-NO-PRODUCTION-DATA');
define('SECURE_AUTH_KEY', 'VETO-DUMMY-SECURE-AUTH-KEY');
define('LOGGED_IN_KEY', 'VETO-DUMMY-LOGGED-IN-KEY');
define('NONCE_KEY', 'VETO-DUMMY-NONCE-KEY');
define('AUTH_SALT', 'VETO-DUMMY-AUTH-SALT');
define('SECURE_AUTH_SALT', 'VETO-DUMMY-SECURE-AUTH-SALT');
define('LOGGED_IN_SALT', 'VETO-DUMMY-LOGGED-IN-SALT');
define('NONCE_SALT', 'VETO-DUMMY-NONCE-SALT');
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
require_once ABSPATH . 'wp-settings.php';
'''
(site / 'wp-config.php').write_text(config)
for current, dirs, files in os.walk(site):
    os.chown(current, 33, 33)
    for name in files:
        os.chown(os.path.join(current, name), 33, 33)
os.chmod(guard / '00-veto.php', 0o444)
print('Initialized synthetic WordPress baseline')
