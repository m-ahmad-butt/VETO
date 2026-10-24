#!/usr/bin/env bash
# Overlay GitLab CI/CD variables into the deployed .env.
# Deploy copies PROD_ENV (a File variable). Separate keys never reach Docker
# unless they are merged here.
set -euo pipefail

ENV_FILE="${1:?usage: overlay-env-from-ci.sh /path/to/.env}"

if [ ! -f "$ENV_FILE" ]; then
  echo "overlay-env-from-ci: missing $ENV_FILE" >&2
  exit 1
fi

if ! command -v python3 >/dev/null 2>&1; then
  echo "overlay-env-from-ci: python3 not found; skipping overlay" >&2
  exit 0
fi

python3 - "$ENV_FILE" <<'PY'
import os
import pathlib
import re
import sys

path = pathlib.Path(sys.argv[1])
text = path.read_text(encoding="utf-8", errors="replace")
keys = [
    "ORIGINAL_URL",
    "DECOY_URL",
    "MYSQL_PASSWORD",
    "MYSQL_ROOT_PASSWORD",
    "WP_ADMIN_PASSWORD",
    "WP_ADMIN_EMAIL",
]
changed = []

def read_ci(name: str) -> str:
    raw = os.environ.get(name, "")
    if raw is None:
        return ""
    raw = str(raw).strip()
    if not raw:
        return ""
    # GitLab "File" variables expose a path, not the contents.
    if os.path.isfile(raw) and os.path.getsize(raw) < 65536:
        try:
            body = pathlib.Path(raw).read_text(encoding="utf-8", errors="replace").strip()
            if body:
                return body.splitlines()[0].strip()
        except OSError:
            pass
    return raw

def format_line(key: str, val: str) -> str:
    if any(ch in val for ch in ' \t#\'"\\$'):
        escaped = val.replace("\\", "\\\\").replace('"', '\\"')
        return f'{key}="{escaped}"'
    return f"{key}={val}"

for key in keys:
    val = read_ci(key)
    if not val:
        continue
    line = format_line(key, val)
    pattern = re.compile(rf"^{re.escape(key)}=.*$", re.M)
    if pattern.search(text):
        text = pattern.sub(line, text, count=1)
    else:
        text = text.rstrip() + "\n" + line + "\n"
    changed.append(key)

if changed:
    path.write_text(text, encoding="utf-8")
    print("overlay-env-from-ci: wrote " + ", ".join(changed))
else:
    print("overlay-env-from-ci: no separate CI variables to overlay")
PY
