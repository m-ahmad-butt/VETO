# Rule Catalog and Default Limits

These are original educational indicators, not an exhaustive malware database or
a vulnerability scanner. All findings require contextual review.

## PHP lexical rules

The lexer masks comments, quoted strings, heredoc/nowdoc content, and non-PHP
regions while preserving byte offsets. It avoids obvious method/static calls and
function declarations. It recognizes `<?php` and `<?=`, not configurable short
open tags. It does not parse PHP syntax, track taint, resolve dynamic function
names, unpack encoded payloads, prove reachability, or inspect interpolation.

| ID | Severity | Indicator | Common legitimate explanation |
|---|---|---|---|
| PHP001 | High | `eval(...)` | Generated-code loaders |
| PHP002 | Medium | Process/command execution functions | Maintenance/conversion tools |
| PHP003 | Low | Decode/decompress functions | Encoded assets and serialized data |
| PHP004 | Medium | Include/require directly referencing request superglobals | Validation outside the local pattern may exist |
| PHP005 | Low | WordPress account creation functions | Membership/registration |
| PHP006 | Low | WordPress scheduling functions | Housekeeping and background jobs |
| PHP007 | Low | HTTP/socket request functions | API integrations |
| PHP008 | Low | Filesystem mutation functions | Cache, installation, updates |
| PHP009 | Medium | Backtick execution syntax | Administrator tooling |

## YARA rules

Real `yara-python` compilation/matching is required; missing YARA fails the run.
The rules inspect raw bytes in every staged regular file, including non-PHP files.
Consequently they can match comments, documentation, or inert strings. That is an
explicit false-positive limitation, not proof of reachable execution.

| ID | Severity | Indicator |
|---|---|---|
| YARA001 | High | `eval` immediately wrapping base64/decompression functions |
| YARA002 | High | `eval` directly receiving a request superglobal |

YARA includes are disabled, compile warnings are errors, matching has a per-file
timeout, and excessive-match warnings fail the scan rather than silently dropping
coverage. No remote signature feed is contacted.

Implementation reference: [official YARA Python API](https://yara.readthedocs.io/en/stable/yarapython.html).

## Package and hash rules

| ID | Severity | Indicator |
|---|---|---|
| PKG001 | Low | Multiple top-level package entries |
| PKG002 | Medium | MZ or ELF executable magic |
| PKG003 | Low | Nested archive; contents are not recursively inspected |
| PKG004 | Medium | PHP opening tags under an unexpected file extension |
| PKG005 | Medium | No conventional main-file Plugin Name header |
| PKG006 | Low | Multiple candidate plugin main files |
| HASH001 | Catalog-defined | Exact file SHA-256 match to a configured reference |

Plugin-header discovery searches the first 8192 bytes of PHP files at ZIP root or
one plugin-folder level. Unusual layouts/encodings may not be recognized. MZ/ELF
magic is a structural indicator; it does not validate that bytes form an executable.

## Resource limits

| Setting | Default |
|---|---|
| Compressed package | 50 MiB |
| Archive entries (including directories) | 10,000 |
| Regular files | 5,000 |
| Single expanded file | 16 MiB |
| Total expanded bytes | 200 MiB |
| Per-member expansion ratio | 200:1 |
| Relative path | 180 characters; at most 20 components |
| Absolute extracted path | 245 characters for portability |
| YARA timeout | 10 seconds per file |
| Findings | 2,000; exceeding the cap fails the run |

Limits are configurable through the CLI except fixed portable component/depth and
absolute-path checks. Larger packages may be rejected intentionally. Use a short,
trusted `--work-dir` parent if the default OS temporary path is unusually long.

Only stored and deflated ZIP compression is supported. Encrypted ZIPs, links,
special files, duplicate/case-colliding names, unsafe paths, and directory/file
conflicts are rejected. Nested archives remain opaque and are reported explicitly.

## Development fixtures

`tools/build_fixtures.py` deterministically builds nine ZIPs from versioned text
sources and generated member definitions. The manifest records package and member
hashes, expected status, and expected rule IDs. Suspicious PHP examples return
before their example functions; tests never execute PHP. These are controlled,
inert development samples, not independently labeled malware or held-out evaluation.

Final precision/recall measurements belong in Phase 4 using separate evaluation
cases, independent ground truth, frozen configuration, and disclosed sample sizes.
