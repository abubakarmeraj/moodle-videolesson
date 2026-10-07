# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright 2026 CodeFortex
"""Explicit public-source inventory, reproducible source archives and safety checks."""
import argparse
import gzip
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import platform
import re
import subprocess
import tarfile
import zipfile
import zlib

ROOT = Path(__file__).resolve().parents[1]
PUBLIC_MANIFEST = 'docs/release/PUBLIC-SOURCE-MANIFEST.json'
SKIP = {'.git', '.lab', 'node_modules', '__pycache__', '.venv'}


def sha(data):
    return hashlib.sha256(data).hexdigest()


def json_bytes(value):
    return (json.dumps(value, indent=2, ensure_ascii=False) + '\n').encode()


def write(path, value):
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(json_bytes(value))


def source_paths():
    raw = subprocess.check_output(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], cwd=ROOT)
    result = sorted(set(raw.decode().strip('\0').split('\0')) - {''})
    for name in result:
        p = ROOT / name
        if set(PurePosixPath(name).parts) & SKIP or p.is_symlink() or not p.is_file():
            raise ValueError('Non-public or unsafe source path: ' + name)
    return result


def inventory(names):
    return [{'path': n, 'sha256': sha((ROOT / n).read_bytes()), 'bytes': (ROOT / n).stat().st_size}
            for n in sorted(names)]


def scan(payload):
    # Construct exact private historical markers so this scanner can itself ship.
    private = re.compile('|'.join((r'D:[\\/]+Agency[\\/]+Setup', r'C:[\\/]+Users[\\/]+' + 'FC',
                       'silicon' + 'bubble', r'/opt/lms-' + 'video-worker',
                       r'/var/lib/video-lesson-' + 'lab', 'VideoLesson-' + r'OS[23]')), re.I)
    secret = re.compile(r'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----|AKIA[A-Z0-9]{16}|'
                        r'gh[pousr]_[A-Za-z0-9]{30,}|X-Amz-' + r'Signature=[a-f0-9]{32,}|'
                        r'MoodleSession[^\s=]*=[A-Za-z0-9]{12,}', re.I)
    findings = []
    for name, data in payload.items():
        if not safe(name):
            findings.append({'path': name, 'kind': 'unsafe-path'})
        try:
            text = data.decode('utf-8')
        except UnicodeError:
            continue
        for label, pattern in [('private-coupling', private), ('secret-signature', secret)]:
            if pattern.search(text):
                findings.append({'path': name, 'kind': label})
    if findings:
        raise ValueError(json.dumps(findings))
    return {'files_scanned': len(payload), 'findings': [],
            'scope': 'public path/type and selected private/credential text signatures; bounded, not universal detection'}


def safe(name):
    p = PurePosixPath(name)
    return bool(name) and not p.is_absolute() and '..' not in p.parts and '\\' not in name and ':' not in name and not set(p.parts) & SKIP


def snapshot():
    names = source_paths()
    product = inventory(n for n in names if n.startswith(('plugin/mod_videolesson/', 'service/video-service/')))
    service = [r for r in product if r['path'].startswith('service/video-service/')]
    write(ROOT / 'docs/release/PRODUCT-MANIFEST.json', product)
    write(ROOT / 'docs/release/SERVICE-MANIFEST.json', service)
    identity = {'plugin_component': 'mod_videolesson', 'plugin_version': 2026100701,
                'plugin_release': 'CodeFortex 3.1.0-rc1', 'service_version': '0.5.0-rc1',
                'service_package_version': '0.5.0rc1', 'protocol': 1,
                'schema_migrations': ['001', '002', '003', '004', '005'],
                'moodle_line': '5.2', 'license': 'GPL-3.0-or-later',
                'product_manifest_sha256': sha(json_bytes(product)), 'service_manifest_sha256': sha(json_bytes(service)),
                'status': 'prepared RC1; not published; final artifact smoke pending'}
    write(ROOT / 'docs/release/IDENTITY.json', identity)
    # Re-list newly generated public metadata; exclude only the self-referential index.
    records = inventory(n for n in source_paths() if n != PUBLIC_MANIFEST)
    write(ROOT / PUBLIC_MANIFEST, records)
    return identity


def verify_source():
    manifest = ROOT / PUBLIC_MANIFEST
    records = json.loads(manifest.read_bytes())
    names = source_paths()
    if records != inventory(n for n in names if n != PUBLIC_MANIFEST):
        raise ValueError('Public source differs from reviewed manifest; review then snapshot, never silently package')
    scan({n: (ROOT / n).read_bytes() for n in names})
    return json.loads((ROOT / 'docs/release/IDENTITY.json').read_bytes()), names


def verify_archive(path):
    path = Path(path)
    if path.suffix == '.zip':
        with zipfile.ZipFile(path) as z:
            infos = z.infolist()
            if any(not safe(i.filename) or (i.external_attr >> 16) & 0o170000 != 0o100000 for i in infos):
                raise ValueError('Unsafe ZIP member')
            if len({i.filename for i in infos}) != len(infos):
                raise ValueError('Duplicate ZIP member')
            payload = {i.filename: z.read(i) for i in infos}
    else:
        with tarfile.open(path, 'r:gz') as t:
            members = t.getmembers()
            if any(not m.isfile() or not safe(m.name) or m.mode != 0o644 or m.uid or m.gid for m in members):
                raise ValueError('Unsafe tar member')
            if len({m.name for m in members}) != len(members):
                raise ValueError('Duplicate tar member')
            payload = {m.name: t.extractfile(m).read() for m in members}
    roots = {PurePosixPath(n).parts[0] for n in payload}
    if len(roots) != 1:
        raise ValueError('Incorrect archive root')
    prefix = next(iter(roots)) + '/'
    release = json.loads(payload.pop(prefix + 'RELEASE-MANIFEST.json'))
    actual = [{'path': n.removeprefix(prefix), 'sha256': sha(b), 'bytes': len(b)} for n, b in sorted(payload.items())]
    if actual != release['files']:
        raise ValueError('Embedded release inventory differs')
    scan(payload)
    if payload[prefix + 'LICENSE'] != (ROOT / 'LICENSE').read_bytes():
        raise ValueError('GPL license missing or differs')
    return release


def build(output):
    identity, names = verify_source()
    # Build only from the exact committed public tree; no experimental source adoption.
    if subprocess.check_output(['git', 'status', '--porcelain'], cwd=ROOT).strip():
        raise ValueError('Public tree must be clean before build')
    commit = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip()
    for n in names:
        if subprocess.check_output(['git', 'show', commit + ':' + n], cwd=ROOT) != (ROOT / n).read_bytes():
            raise ValueError('Checkout differs from exact committed blob: ' + n)
    output = Path(output)
    output.mkdir(parents=True, exist_ok=True)
    results = []
    for kind, prefix, filename in (
        ('plugin', 'videolesson/', 'mod_videolesson-3.1.0-rc1.zip'),
        ('service', 'video-lesson/', 'codefortex-video-service-0.5.0-rc1.tar.gz')):
        if kind == 'plugin':
            chosen = [n for n in names if n.startswith('plugin/mod_videolesson/')]
            payload = {prefix + n.removeprefix('plugin/mod_videolesson/'): (ROOT / n).read_bytes() for n in chosen}
            payload.update({prefix + n: (ROOT / n).read_bytes() for n in names
                            if n.startswith('docs/licenses/') and '/python/' not in n})
        else:
            chosen = [n for n in names if n.startswith(('service/', 'deploy/', 'docs/'))
                      and not n.startswith('docs/development/')]
            payload = {prefix + n: (ROOT / n).read_bytes() for n in chosen}
            # The complete source index is repository-only, not a promise that this
            # component tarball contains the entire plugin/tests repository.
            payload.pop(prefix + PUBLIC_MANIFEST, None)
        for n in ('LICENSE', 'THIRD_PARTY_NOTICES.md', 'README.md', 'SECURITY.md'):
            # Do not replace the plugin's distribution-local README with repo links.
            if n != 'README.md' or kind == 'service':
                payload[prefix + n] = (ROOT / n).read_bytes()
        scan(payload)
        release = {**identity, 'source_commit': commit,
                   'public_source_manifest_sha256': sha((ROOT / PUBLIC_MANIFEST).read_bytes()),
                   'component': kind,
                   'files': [{'path': n.removeprefix(prefix), 'sha256': sha(b), 'bytes': len(b)} for n, b in sorted(payload.items())]}
        manifest_bytes = json_bytes(release)
        payload[prefix + 'RELEASE-MANIFEST.json'] = manifest_bytes
        target = output / filename
        if target.exists():
            raise ValueError('Never overwrite a prior artifact')
        if kind == 'plugin':
            with zipfile.ZipFile(target, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
                for name, data in sorted(payload.items()):
                    info = zipfile.ZipInfo(name, (1980, 1, 1, 0, 0, 0))
                    info.create_system = 3
                    info.external_attr = 0o100644 << 16
                    z.writestr(info, data, compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
        else:
            with target.open('wb') as stream, gzip.GzipFile(fileobj=stream, mode='wb', filename='', mtime=0, compresslevel=9) as gz:
                with tarfile.open(fileobj=gz, mode='w', format=tarfile.PAX_FORMAT) as t:
                    for name, data in sorted(payload.items()):
                        info = tarfile.TarInfo(name)
                        info.size, info.mode, info.mtime = len(data), 0o644, 0
                        info.uid = info.gid = 0
                        t.addfile(info, io.BytesIO(data))
        verify_archive(target)
        results.append({'filename': filename, 'bytes': target.stat().st_size, 'file_count': len(payload),
                        'sha256': sha(target.read_bytes()), 'release_manifest_sha256': sha(manifest_bytes)})
    result = {**identity, 'source_commit': commit, 'public_source_manifest_sha256': sha((ROOT / PUBLIC_MANIFEST).read_bytes()),
              'artifacts': results, 'deployment_bundle': 'included in service tar.gz',
              'build_tools': {'python': platform.python_version(), 'zlib': zlib.ZLIB_VERSION,
                              'node': '24.17.0', 'npm': '11.13.0', 'babel': '7.28.0', 'terser': '5.43.1'},
              'normalization': 'ZIP1980; tar/gzip mtime0; uid/gid0; files0644; sorted entries',
              'publication': 'not authorized by this preparation'}
    write(output / 'RELEASE-MANIFEST.json', result)
    (output / 'SHA256SUMS').write_text(''.join(r['sha256'] + '  ' + r['filename'] + '\n' for r in results), newline='\n')
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=('snapshot', 'check', 'build', 'verify-archive'))
    parser.add_argument('--output', type=Path)
    parser.add_argument('--archive', type=Path)
    args = parser.parse_args()
    if args.action == 'snapshot':
        result = snapshot()
    elif args.action == 'check':
        result, names = verify_source()
        result['public_files'] = len(names)
    elif args.action == 'build':
        if not args.output:
            parser.error('--output required')
        result = build(args.output)
    else:
        if not args.archive:
            parser.error('--archive required')
        result = verify_archive(args.archive)
        result.pop('files')
    print(json.dumps(result, indent=2))


if __name__ == '__main__':
    main()
