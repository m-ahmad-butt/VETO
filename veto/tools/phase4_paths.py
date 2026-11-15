"""Separate versioned Phase 4 sources/metadata from ignored local artifacts."""
from pathlib import Path

BASE = Path(__file__).resolve().parents[1]
LOCAL = BASE.parent / 'Evaluations'
SAMPLES = BASE / 'evaluation/samples' if (BASE / 'evaluation/samples').exists() else LOCAL / 'samples'
RUNS = LOCAL / 'runs'
VERIFICATION = LOCAL / 'verification'
SUBMISSION = LOCAL / 'submission'
PACKAGES = LOCAL / 'packages'


def package_path(case):
    return SAMPLES / Path(case['package']).name


def source_path(relative):
    # Preserve the portable layout inside newly frozen source copies.
    if relative.startswith('evaluation/samples/'):
        return SAMPLES / Path(relative).name
    return BASE / relative
