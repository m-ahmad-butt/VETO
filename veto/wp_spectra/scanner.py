"""Public analysis interface. All untrusted package content remains inert."""

from collections import Counter
from dataclasses import asdict
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import re
import tempfile
import time
import uuid
import zipfile
import zlib

from . import __version__, php
from .intake import IntakeError, Limits, stage_archive

RESOURCES = Path(__file__).parent / "resources"
SCHEMA_VERSION = "1.0.0"


def now():
    return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")


def sha256(data):
    return hashlib.sha256(data).hexdigest()


def load_catalog(path):
    raw = path.read_bytes()
    catalog = json.loads(raw)
    if not isinstance(catalog, dict) or not isinstance(catalog.get("version"), str) or not isinstance(catalog.get("entries"), list):
        raise ValueError("Hash catalog needs a string version and entries array")
    entries = {}
    for entry in catalog["entries"]:
        if not isinstance(entry, dict) or set(entry) != {"sha256", "label", "source", "severity"}:
            raise ValueError("Hash entries require sha256, label, source, severity")
        if (not isinstance(entry["sha256"], str) or not re.fullmatch(r"[a-f0-9]{64}", entry["sha256"])
                or entry["severity"] not in ("low", "medium", "high")
                or any(not isinstance(entry[k], str) or not entry[k].strip() for k in ("label", "source"))
                or entry["sha256"] in entries):
            raise ValueError("Invalid or duplicate hash catalog entry")
        entries[entry["sha256"]] = entry
    return catalog["version"], entries, sha256(raw)


def analyze(source, *, limits=None, hash_catalog=None, work_dir=None, on_progress=None, on_checkpoint=None):
    """Return schema 1.0.0 result, including structured rejected/failed outcomes.

    No package subprocesses, includes, eval, network requests, or PHP interpreter.
    Source must be a local regular ZIP file. work_dir must be a trusted existing
    directory; private staging is removed by TemporaryDirectory before return.
    """
    limits = limits or Limits()
    source = Path(source)
    report = {
        "schema_version": SCHEMA_VERSION, "analyzer_version": __version__,
        "analysis_id": str(uuid.uuid4()), "status": "failed", "started_at": now(),
        "finished_at": None, "package": {"filename": source.name, "size_bytes": None, "sha256": None},
        "plugin_metadata": [], "inventory": [], "static_findings": [],
        "runtime_events": [], "errors": [],
        "coverage": {"intake": "not_run", "hashes": "not_run", "php": "not_run", "yara": "not_run", "dynamic": "not_run"},
        "configuration": {"limits": asdict(limits), "rules_sha256": None,
                          "hash_catalog_sha256": None, "hash_catalog_version": None, "hash_reference_count": 0,
                          "yara_version": None, "execution": "static_only"},
        "summary": {"assessment": "incomplete", "flagged_for_review": False,
                    "finding_count": 0, "severity_counts": {"low": 0, "medium": 0, "high": 0}},
        "limitations": [
            "Static indicators are not proof of maliciousness or safety.",
            "No plugin execution, dynamic monitoring, or runtime observations in Phase 1.",
            "PHP checks are lexical, not AST, data-flow, reachability, or vulnerability analysis.",
            "Raw YARA may match comments or inert strings; encoded/indirect behavior can evade rules.",
            "Only the configured local hash references are checked; an empty catalog provides no known-sample detection.",
        ],
    }

    def add(rule_id, severity, title, path, evidence, rationale, limitation, *, engine="package", offset=None, data=None, count=1, reference=None):
        if len(report["static_findings"]) >= limits.max_findings:
            raise RuntimeError("Finding limit reached; analysis is incomplete")
        number = len(report["static_findings"]) + 1
        report["static_findings"].append({
            "finding_id": f"F{number:05}", "rule_id": rule_id, "engine": engine,
            "severity": severity, "title": title, "path": path,
            "line": data[:offset].count(b"\n") + 1 if data is not None and offset is not None else None,
            "byte_offset": offset, "occurrence_count": count,
            "evidence": {"id": f"E{number:05}", "kind": "inline", "text": evidence[:512]},
            "rationale": rationale, "limitation": limitation, "reference": reference,
            "observed_at": now(),
        })

    stage = "intake"
    last_checkpoint=0
    def save_checkpoint(force=False):
        nonlocal last_checkpoint
        if on_checkpoint and (force or time.monotonic()-last_checkpoint>=1):
            on_checkpoint(report)
            last_checkpoint=time.monotonic()
    try:
        if on_progress:
            on_progress('validating', 'Checking the archive and preparing a private copy')
        if not source.is_file():
            raise IntakeError("input_file", "Input must be an existing regular ZIP file")
        with tempfile.TemporaryDirectory(prefix="veto-", dir=work_dir) as private:
            package, inventory, root = stage_archive(source, Path(private), limits)
            report["package"] = package
            report["inventory"] = inventory
            report["coverage"]["intake"] = "completed"
            save_checkpoint(force=True)
            if on_progress:
                on_progress('scanning', 'Inspecting signatures, PHP patterns, metadata and file hashes')
            stage = "configuration"
            import yara
            rule_bytes = (RESOURCES / "static.yar").read_bytes()
            report["configuration"]["rules_sha256"] = sha256(rule_bytes + b"\0" + Path(php.__file__).read_bytes())
            report["configuration"]["yara_version"] = yara.__version__
            rules = yara.compile(source=rule_bytes.decode("utf-8"), includes=False, error_on_warning=True)
            catalog_version, references, catalog_hash = load_catalog(Path(hash_catalog) if hash_catalog else RESOURCES / "known_hashes.json")
            report["configuration"].update(hash_catalog_sha256=catalog_hash,
                                           hash_catalog_version=catalog_version, hash_reference_count=len(references))
            roots = {item["path"].split("/")[0] for item in inventory}
            if len(roots) > 1:
                add("PKG001", "low", "Multiple top-level package entries", None, ", ".join(sorted(roots)),
                    "A single plugin folder is easier to attribute and install.", "Root-level plugin ZIP layouts can be legitimate.")
            stage = "static"
            for item in inventory:
                path = item["path"]
                data = root.joinpath(*path.split("/")).read_bytes()
                if item["sha256"] in references:
                    entry = references[item["sha256"]]
                    add("HASH001", entry["severity"], "Exact reference-file hash match", path,
                        f"SHA-256 {item['sha256']}; label: {entry['label']}",
                        "File bytes match a configured reference exactly.", "The catalog's provenance determines the meaning; a match alone is not malware attribution.",
                        engine="hash", reference=entry["source"])
                if data.startswith((b"MZ", b"\x7fELF")):
                    add("PKG002", "medium", "Native executable signature", path, data[:16].hex(),
                        "A native executable is unusual in many WordPress plugins.", "Some legitimate packages bundle helper binaries.")
                if Path(path).suffix.lower() in (".zip", ".gz", ".tar", ".7z", ".rar"):
                    add("PKG003", "low", "Nested archive is not recursively inspected", path, path,
                        "Embedded archives may contain additional content outside this scan's coverage.", "Bundled assets may legitimately be compressed.")
                is_php = bool(re.search(rb"<\?(?:php\b|=)", data, re.I))
                if is_php and Path(path).suffix.lower() not in (".php", ".phtml", ".php3", ".php4", ".php5", ".inc"):
                    add("PKG004", "medium", "PHP content under an unexpected extension", path, path,
                        "PHP tags occur in a file not normally recognized as PHP.", "Documentation may contain inert PHP examples.")
                # WordPress reads header comments near the beginning of a main file.
                if is_php and Path(path).suffix.lower() == ".php" and len(path.split("/")) <= 2:
                    head = data[:8192].decode("utf-8", "replace")
                    name = re.search(r"(?mi)^[ \t/*#@]*Plugin Name\s*:\s*(.+)", head)
                    if name:
                        version = re.search(r"(?mi)^[ \t/*#@]*Version\s*:\s*(.+)", head)
                        report["plugin_metadata"].append({"main_file": path, "name": name[1].strip(" */\r"),
                                                          "version": version[1].strip(" */\r") if version else None})
                if is_php:
                    grouped = {}
                    for check in php.findings(data):
                        if check[0] not in grouped:
                            grouped[check[0]] = [check, 0]
                        grouped[check[0]][1] += 1
                    for check, count in grouped.values():
                        rid, severity, title, start, end, rationale, limitation = check
                        add(rid, severity, title, path, data[max(0, start - 40):min(len(data), end + 100)].decode("utf-8", "replace"),
                            rationale, limitation, engine="php", offset=start, data=data, count=count)
                warnings = []
                matches = rules.match(data=data, timeout=limits.yara_timeout_seconds,
                                      warnings_callback=lambda *args: warnings.append(str(args)))
                if warnings:
                    raise RuntimeError("YARA reported excessive matches; analysis is incomplete")
                for match in matches:
                    instances = [instance for string in match.strings for instance in string.instances]
                    first = min(instances, key=lambda instance: instance.offset)
                    meta = match.meta
                    add(meta["rule_id"], meta["severity"], meta["title"], path,
                        first.matched_data.decode("utf-8", "replace"), meta["rationale"], meta["limitation"],
                        engine="yara", offset=first.offset, data=data, count=len(instances))
                save_checkpoint()
            if not report["plugin_metadata"]:
                add("PKG005", "medium", "No conventional WordPress plugin header found", None, "No Plugin Name header in a root-level PHP main file or one plugin folder",
                    "The package may not be a conventional installable WordPress plugin.", "Unusual layouts and encodings can evade header discovery.")
            elif len(report["plugin_metadata"]) > 1:
                add("PKG006", "low", "Multiple candidate plugin main files", None,
                    ", ".join(item["main_file"] for item in report["plugin_metadata"]),
                    "Multiple plugin headers make the package entry point ambiguous.", "A package may legitimately contain several plugins.")
            for engine in ("hashes", "php", "yara"):
                report["coverage"][engine] = "completed"
            report["status"] = "completed"
    except IntakeError as error:
        report["status"] = "rejected"
        report["coverage"]["intake"] = "failed"
        report["errors"].append({"stage": stage, "code": error.code, "message": str(error)})
    except (zipfile.BadZipFile, zipfile.LargeZipFile, EOFError, NotImplementedError, zlib.error) as error:
        report["status"] = "rejected" if stage == "intake" else "failed"
        report["coverage"]["intake"] = "failed" if stage == "intake" else report["coverage"]["intake"]
        report["errors"].append({"stage": stage, "code": "invalid_zip", "message": str(error)[:512]})
    except Exception as error:
        report["errors"].append({"stage": stage, "code": type(error).__name__, "message": str(error)[:512]})
        if stage == "static":
            for engine in ("hashes", "php", "yara"):
                report["coverage"][engine] = "failed"
    counts = Counter(f["severity"] for f in report["static_findings"])
    report["summary"]["severity_counts"] = {level: counts[level] for level in ("low", "medium", "high")}
    report["summary"]["finding_count"] = len(report["static_findings"])
    if report["status"] == "completed":
        flagged = bool(counts["medium"] or counts["high"])
        report["summary"]["flagged_for_review"] = flagged
        report["summary"]["assessment"] = "review_recommended" if flagged else "capabilities_observed" if counts["low"] else "no_indicators_observed"
    report["finished_at"] = now()
    save_checkpoint(force=True)
    return report
