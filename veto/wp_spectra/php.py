"""Conservative byte lexer: mask comments/strings without changing byte offsets.

Not a PHP parser. Dynamic names, malformed syntax, and interpolation can evade
checks. Raw YARA remains complementary, including its comment/string false positives.
"""

import re


def code_only(data):
    out = bytearray(b" " * len(data))
    i = 0
    active = False
    while i < len(data):
        if not active:
            opening = re.search(rb"<\?(?:php\b|=)", data[i:], re.I)
            if not opening:
                break
            i += opening.end()
            active = True
            continue
        if data[i:i + 2] == b"?>":
            active = False
            i += 2
        elif data[i:i + 2] in (b"//", b"/*") or data[i:i + 1] == b"#" and data[i:i + 2] != b"#[":
            if data[i:i + 2] == b"/*":
                end = data.find(b"*/", i + 2)
                i = len(data) if end < 0 else end + 2
            else:
                end = data.find(b"\n", i)
                close = data.find(b"?>", i)
                if close >= 0 and (end < 0 or close < end):
                    i = close
                else:
                    i = len(data) if end < 0 else end
        elif data[i:i + 3] == b"<<<":
            match = re.match(rb"<<<[ \t]*['\"]?([A-Za-z_][A-Za-z_0-9]*)['\"]?[ \t]*\r?\n", data[i:])
            if not match:
                out[i] = data[i]
                i += 1
                continue
            end = re.search(rb"(?m)^[ \t]*" + re.escape(match[1]) + rb"\b", data[i + match.end():])
            i = len(data) if not end else i + match.end() + end.end()
        elif data[i] in (34, 39, 96):
            quote = data[i]
            if quote == 96:
                out[i] = 96  # retain a backtick indicator, mask command contents
            i += 1
            while i < len(data):
                if data[i] == 92:
                    i += 2
                elif data[i] == quote:
                    i += 1
                    break
                else:
                    i += 1
        else:
            out[i] = data[i]
            i += 1
    return bytes(out)


# Each tuple: stable ID, severity, title, bounded linear regex, rationale, limitation.
CHECKS = [
    ("PHP001", "high", "Dynamic PHP evaluation", rb"\beval\s*\(",
     "Evaluates generated PHP code; review the source of its argument.", "Some legitimate loaders evaluate generated code."),
    ("PHP002", "medium", "Operating-system command execution", rb"(?<![\w$>:])(?:shell_exec|system|exec|passthru|popen|proc_open)\s*\(",
     "Can launch processes outside WordPress.", "Maintenance tools may legitimately launch processes."),
    ("PHP003", "low", "Encoded or compressed payload handling", rb"(?<![\w$>:])(?:base64_decode|gzinflate|gzuncompress|str_rot13|hex2bin)\s*\(",
     "Encoding can conceal code or data and merits contextual review.", "Encoding is common in legitimate plugins."),
    ("PHP004", "medium", "User-controlled dynamic include", rb"\b(?:include|include_once|require|require_once)\s*\(?\s*\$_(?:GET|POST|REQUEST|COOKIE)\s*\[",
     "An include path appears to come directly from request data.", "Validation elsewhere is not resolved by this scanner."),
    ("PHP005", "low", "WordPress account creation capability", rb"(?<![\w$>:])(?:wp_create_user|wp_insert_user)\s*\(",
     "Can create accounts; correlate with intended plugin functionality.", "Registration and membership plugins legitimately create users."),
    ("PHP006", "low", "WordPress scheduled-task registration", rb"(?<![\w$>:])(?:wp_schedule_event|wp_schedule_single_event)\s*\(",
     "Can schedule recurring or delayed activity.", "Scheduled tasks are normal for many plugins."),
    ("PHP007", "low", "Outbound request capability", rb"(?<![\w$>:])(?:wp_remote_get|wp_remote_post|wp_remote_request|curl_exec|fsockopen)\s*\(",
     "May contact external services; destination and context need review.", "This is capability evidence, not an observed network connection."),
    ("PHP008", "low", "Filesystem mutation capability", rb"(?<![\w$>:])(?:file_put_contents|fwrite|unlink|rename|copy|chmod)\s*\(",
     "Can modify filesystem state.", "Caching, installation, and updates commonly write files."),
    ("PHP009", "medium", "Backtick command execution syntax", rb"`",
     "PHP backtick syntax executes a shell command.", "Lexical evidence requires manual review; syntax is not validated."),
]


def findings(data):
    masked = code_only(data)
    for match in re.finditer(rb"\bupdate_option\s*\(\s*['\"]page_on_front['\"]\s*,", data, re.I):
        start = match.start()
        if masked[start:start + len(b"update_option")].lower() == b"update_option":
            yield "PHP010", "medium", "Homepage replacement capability", start, match.end(), "Directly selects a different WordPress front page; review whether the site owner authorized it.", "A legitimate site setup plugin may intentionally set the front page; indirect calls are not resolved."
    for rule_id, severity, title, pattern, rationale, limitation in CHECKS:
        for match in re.finditer(pattern, masked, re.I):
            prefix = masked[max(0, match.start() - 64):match.start()].rstrip()
            if prefix.endswith((b"->", b"::")) or re.search(rb"\bfunction\s*&?\s*$", prefix, re.I):
                continue
            yield rule_id, severity, title, match.start(), match.end(), rationale, limitation
