"""Run from veto/: python -m wp_spectra scan plugin.zip --output report.json"""

import argparse
from collections import Counter
import json
from pathlib import Path
import sys

from . import __version__
from .intake import Limits
from .scanner import RESOURCES, analyze


def validate_report(report):
    from jsonschema import Draft202012Validator, FormatChecker
    schema = json.loads((RESOURCES / "result.schema.json").read_text(encoding="utf-8"))
    Draft202012Validator(schema, format_checker=FormatChecker()).validate(report)
    findings = report["static_findings"]
    counts = Counter(item["severity"] for item in findings)
    summary = report["summary"]
    if summary["finding_count"] != len(findings) or summary["severity_counts"] != {level: counts[level] for level in ("low", "medium", "high")}:
        raise ValueError("Report summary does not agree with findings")
    if len({item["finding_id"] for item in findings}) != len(findings) or len({item["evidence"]["id"] for item in findings}) != len(findings):
        raise ValueError("Finding and evidence IDs must be unique within an analysis")
    paths = {item["path"] for item in report["inventory"]}
    if any(item["path"] is not None and item["path"] not in paths for item in findings):
        raise ValueError("Finding resource is missing from inventory")
    if report["status"] == "completed":
        flagged = bool(counts["medium"] or counts["high"])
        expected = "review_recommended" if flagged else "capabilities_observed" if counts["low"] else "no_indicators_observed"
        if summary["flagged_for_review"] != flagged or summary["assessment"] != expected:
            raise ValueError("Assessment does not agree with severity policy")


def write_json(path, value):
    """Exclusive UTF-8 output; refuse overwrite so evidence isn't silently lost."""
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    if path.exists():
        raise FileExistsError(f"Output already exists: {path}; choose another filename")
    text = json.dumps(value, indent=2, ensure_ascii=True) + "\n"
    # Exclusive creation is sufficient for CLI evidence; remove partial file on failure.
    created = False
    try:
        with path.open("x", encoding="utf-8", newline="\n") as output:
            created = True
            output.write(text)
    except Exception:
        if created:
            path.unlink(missing_ok=True)
        raise


def main(argv=None):
    parser = argparse.ArgumentParser(description="VETO: static inspection and explicit isolated Docker execution of WordPress plugins")
    parser.add_argument("--version", action="version", version=__version__)
    commands = parser.add_subparsers(dest="command", required=True)
    scan = commands.add_parser("scan", help="Inspect a local plugin ZIP")
    scan.add_argument("package", type=Path)
    scan.add_argument("--output", type=Path, help="Save JSON; omit to print JSON on stdout")
    scan.add_argument("--hash-catalog", type=Path, help="Trusted local reference catalog")
    scan.add_argument("--work-dir", type=Path, help="Trusted existing parent for private temporary staging")
    for option, default in Limits().__dict__.items():
        scan.add_argument("--" + option.replace("_", "-"), type=int, default=default)
    validate = commands.add_parser("validate", help="Validate a saved report against schema 1.0.0")
    validate.add_argument("report", type=Path)
    observe = commands.add_parser("observe", help="Execute a plugin copy in a disposable local Docker sandbox")
    observe.add_argument("package", type=Path)
    observe.add_argument("--output", type=Path, required=True)
    observe.add_argument("--control", action="store_true", help="Exercise clean WordPress without installing the input plugin")
    observe.add_argument("--startup-timeout",type=int,default=120)
    observe.add_argument("--action-timeout",type=int,default=15)
    observe.add_argument("--observation-seconds",type=int,default=3)
    observe.add_argument("--job-timeout",type=int,default=300)
    dynamic_validate=commands.add_parser("validate-dynamic",help="Validate a Phase 2 result including embedded static evidence")
    dynamic_validate.add_argument("report",type=Path)
    serve=commands.add_parser('serve',help='Start the local upload, progress and history application')
    serve.add_argument('--port',type=int,default=8765)
    serve.add_argument('--data-dir',type=Path)
    args = parser.parse_args(argv)
    try:
        if args.command=='serve':
            from .app_server import main as serve_main
            options=['--port',str(args.port)]
            if args.data_dir:options+=['--data-dir',str(args.data_dir)]
            serve_main(options)
            return 0
        if args.command in ("observe","validate-dynamic"):
            from .dynamic import DynamicConfig,observe as run_dynamic,validate_dynamic
            if args.command=="validate-dynamic":
                validate_dynamic(json.loads(args.report.read_text(encoding="utf-8")))
                print("Valid VETO dynamic result 1.0.0")
                return 0
            if args.output.exists():raise FileExistsError("Output already exists; choose another report filename")
            config=DynamicConfig(startup_timeout=args.startup_timeout,action_timeout=args.action_timeout,observation_seconds=args.observation_seconds,job_timeout=args.job_timeout)
            report=run_dynamic(args.package,config=config,control=args.control)
            validate_dynamic(report)
            write_json(args.output,report)
            print(f"{report['status']}: {len(report['runtime_events'])} runtime events; cleanup verified={report['cleanup']['verified']}. Report: {args.output}")
            return 0 if report['status']=='completed' else 2 if report['status']=='rejected' else 3
        if args.command == "validate":
            validate_report(json.loads(args.report.read_text(encoding="utf-8")))
            print("Valid VETO result schema 1.0.0")
            return 0
        limits = Limits(**{key: getattr(args, key) for key in Limits().__dict__})
        if args.output and args.output.resolve() == args.package.resolve():
            raise ValueError("Report output cannot replace the submitted archive")
        if args.output and args.output.exists():
            raise FileExistsError("Output already exists; choose another report filename")
        report = analyze(args.package, limits=limits, hash_catalog=args.hash_catalog, work_dir=args.work_dir)
        validate_report(report)
        if args.output:
            write_json(args.output, report)
            print(f"{report['status']}: {report['summary']['assessment']}; {len(report['static_findings'])} findings. Report: {args.output}", file=sys.stderr)
        else:
            print(json.dumps(report, indent=2, ensure_ascii=True))
        return 0 if report["status"] == "completed" else 2 if report["status"] == "rejected" else 3
    except Exception as error:
        print(f"VETO error: {error}", file=sys.stderr)
        return 3


if __name__ == "__main__":
    sys.exit(main())
