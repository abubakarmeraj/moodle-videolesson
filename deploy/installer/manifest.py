# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright 2026 CodeFortex
"""Verify the shipped service payload without historical workstation provenance."""
import hashlib
import json
from pathlib import Path


def verify(project, manifest=None, expected=None):
    project = Path(project)
    identity = json.loads((project / 'docs/release/IDENTITY.json').read_text())
    manifest = Path(manifest) if manifest else project / 'docs/release/SERVICE-MANIFEST.json'
    expected = expected or identity['service_manifest_sha256']
    if hashlib.sha256(manifest.read_bytes()).hexdigest() != expected:
        raise RuntimeError('Reviewed service manifest digest mismatch')
    records = json.loads(manifest.read_text())
    prefix = 'service/video-service/'
    for row in records:
        relative = Path(row['path'])
        if not row['path'].startswith(prefix) or relative.is_absolute() or '..' in relative.parts:
            raise RuntimeError('Unsafe service manifest path')
        source = project / relative
        if source.is_symlink() or not source.is_file() or hashlib.sha256(source.read_bytes()).hexdigest() != row['sha256']:
            raise RuntimeError('Reviewed service payload differs')
    actual = {p.relative_to(project).as_posix() for p in (project / prefix).rglob('*')
              if p.is_file() and not set(p.parts) & {'__pycache__', '.venv'}}
    if actual != {r['path'] for r in records}:
        raise RuntimeError('Service payload inventory mismatch')
    if (project / prefix / 'VERSION').read_text().strip() != identity['service_version']:
        raise RuntimeError('Service identity mismatch')
    return identity
