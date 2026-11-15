"""Exercise real frontend/admin HTTP requests from the trusted observer."""
import http.cookiejar
import json
import os
import urllib.parse
import urllib.request
import sys

base = 'http://wordpress:8080'
timeout = float(os.environ.get('VETO_REQUEST_TIMEOUT', '10'))
jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
opener.addheaders = [('User-Agent', 'VETO-controlled-driver/0.2')]
def get(path, data=None):
    request = urllib.request.Request(base + path, data=data)
    with opener.open(request, timeout=timeout) as response:
        content = response.read(1024 * 1024 + 1)
        if len(content) > 1024 * 1024:
            raise RuntimeError('HTTP response exceeds limit')
        return response.status, response.url, content

if sys.argv[1] == 'frontend':
    status, url, content = get('/')
    print(json.dumps({'status': status, 'path': '/','bytes': len(content)}))
elif sys.argv[1] == 'admin':
    get('/wp-login.php')
    data = urllib.parse.urlencode({'log': 'veto_admin', 'pwd': os.environ['VETO_ADMIN_PASSWORD'], 'wp-submit': 'Log In', 'redirect_to': base + '/wp-admin/', 'testcookie': '1'}).encode()
    status, url, content = get('/wp-login.php', data)
    if '/wp-admin/' not in url or not any(cookie.name.startswith('wordpress_logged_in_') for cookie in jar):
        raise RuntimeError('Authenticated administrator login failed')
    status, url, content = get('/wp-admin/plugins.php')
    print(json.dumps({'status': status, 'path': '/wp-admin/plugins.php', 'authenticated': True, 'bytes': len(content)}))
else:
    raise RuntimeError('Unsupported driver stage')
