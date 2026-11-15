"""Bounded ZIP intake. Never invoke PHP or use ZipFile.extract/extractall."""

from dataclasses import asdict, dataclass
import hashlib
import re
import stat
import unicodedata
import zipfile
from pathlib import Path


class IntakeError(ValueError):
    def __init__(self, code, message):
        super().__init__(message)
        self.code = code


@dataclass(frozen=True)
class Limits:
    max_archive_bytes: int = 50 * 1024 * 1024
    max_entries: int = 10000
    max_files: int = 5000
    max_file_bytes: int = 16 * 1024 * 1024
    max_expanded_bytes: int = 200 * 1024 * 1024
    max_compression_ratio: int = 200
    max_path_chars: int = 180
    yara_timeout_seconds: int = 10
    max_findings: int = 2000

    def __post_init__(self):
        if any(type(value) is not int or value <= 0 for value in asdict(self).values()):
            raise ValueError("All limits must be positive integers")


RESERVED = re.compile(r"^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)", re.I)


def safe_name(info, limits):
    name = info.orig_filename
    if "\x00" in name or "\\" in name or name.startswith("/"):
        raise IntakeError("unsafe_path", "Absolute, NUL, or backslash member path")
    path = name[:-1] if info.is_dir() else name
    parts = path.split("/")
    if not path or len(path) > limits.max_path_chars or len(parts) > 20:
        raise IntakeError("unsafe_path", "Empty, too long, or excessively nested member path")
    for part in parts:
        if (part in ("", ".", "..") or part.endswith((".", " "))
                or RESERVED.match(part) or any(ord(c) < 32 or c in '<>:"|?*' for c in part)
                or unicodedata.normalize("NFC", part) != part):
            raise IntakeError("unsafe_path", "Non-portable or unsafe archive member path")
    mode = info.external_attr >> 16
    kind = stat.S_IFMT(mode)
    if kind not in (0, stat.S_IFREG, stat.S_IFDIR):
        raise IntakeError("special_file", "Links and special files are not permitted")
    if kind == stat.S_IFDIR and not info.is_dir():
        raise IntakeError("special_file", "Inconsistent directory entry")
    if info.flag_bits & 1:
        raise IntakeError("encrypted_archive", "Encrypted ZIP members are not supported")
    if info.compress_type not in (zipfile.ZIP_STORED, zipfile.ZIP_DEFLATED):
        raise IntakeError("compression_method", "Only stored and deflated ZIP members are supported")
    return path


def stage_archive(source, staging, limits):
    """Snapshot input, preflight ALL entries, then extract bounded streams privately.

    Caller owns a fresh TemporaryDirectory and deletes it on every exit path.
    Hashes and reads refer to the same snapshot, avoiding path-replacement races.
    """
    snapshot = staging / "submission.zip"
    digest = hashlib.sha256()
    size = 0
    with Path(source).open("rb") as incoming, snapshot.open("xb") as outgoing:
        while chunk := incoming.read(65536):
            size += len(chunk)
            if size > limits.max_archive_bytes:
                raise IntakeError("archive_size", "Compressed package exceeds archive byte limit")
            digest.update(chunk)
            outgoing.write(chunk)
    package = {"filename": Path(source).name, "size_bytes": size, "sha256": digest.hexdigest()}
    root = staging / "files"
    root.mkdir()
    with zipfile.ZipFile(snapshot) as archive:
        entries = archive.infolist()
        if not entries or len(entries) > limits.max_entries:
            raise IntakeError("entry_count", "ZIP is empty or exceeds entry limit")
        names = {}
        planned = []
        declared_total = 0
        files = 0
        for info in entries:
            name = safe_name(info, limits)
            key = name.casefold()
            if key in names:
                raise IntakeError("duplicate_path", "Duplicate or case-colliding archive path")
            names[key] = info.is_dir()
            target = root.joinpath(*name.split("/"))
            if not target.resolve().is_relative_to(root.resolve()):
                raise IntakeError("unsafe_path", "Member escapes private staging directory")
            if len(str(target)) > 245:
                raise IntakeError("unsafe_path", "Member exceeds portable absolute path limit")
            if info.is_dir():
                if info.file_size:
                    raise IntakeError("directory_payload", "Directory entries must not contain data")
            else:
                files += 1
                declared_total += info.file_size
                if files > limits.max_files:
                    raise IntakeError("file_count", "ZIP exceeds file count limit")
                if info.file_size > limits.max_file_bytes:
                    raise IntakeError("file_size", "ZIP member exceeds per-file byte limit")
                if declared_total > limits.max_expanded_bytes:
                    raise IntakeError("expanded_size", "ZIP exceeds expanded byte limit")
                if info.file_size / max(info.compress_size, 1) > limits.max_compression_ratio:
                    raise IntakeError("compression_ratio", "ZIP member exceeds compression ratio limit")
            planned.append((info, name, target))
        if not files:
            raise IntakeError("empty_package", "ZIP has no regular files")
        for key in names:
            parts = key.split("/")
            if any(names.get("/".join(parts[:i])) is False for i in range(1, len(parts))):
                raise IntakeError("path_conflict", "A file is also used as a directory")
        total = 0
        inventory = []
        for info, name, target in sorted(planned, key=lambda item: item[1]):
            if info.is_dir():
                target.mkdir(parents=True, exist_ok=True)
                continue
            target.parent.mkdir(parents=True, exist_ok=True)
            file_hash = hashlib.sha256()
            actual = 0
            with archive.open(info) as incoming, target.open("xb") as outgoing:
                while chunk := incoming.read(65536):
                    actual += len(chunk)
                    total += len(chunk)
                    if actual > limits.max_file_bytes or total > limits.max_expanded_bytes:
                        raise IntakeError("stream_limit", "Actual extracted data exceeds limits")
                    file_hash.update(chunk)
                    outgoing.write(chunk)
            if actual != info.file_size:
                raise IntakeError("size_mismatch", "ZIP member does not match declared size")
            inventory.append({"path": name, "size_bytes": actual, "sha256": file_hash.hexdigest()})
    return package, inventory, root
