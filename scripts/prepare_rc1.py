# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright 2026 CodeFortex
"""Mechanical licensing/Compose metadata normalization, not a runtime migration."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def main():
    for directory in ('service/video-service/service', 'deploy'):
        for path in (ROOT / directory).rglob('*'):
            if not path.is_file() or path.suffix not in ('.py', '.sh', '.yaml') and path.name != 'Dockerfile':
                continue
            text = path.read_text()
            if 'SPDX-License-Identifier:' not in text:
                lines = text.splitlines(keepends=True)
                at = 1 if text.startswith('#!') else 0
                lines.insert(at, '# SPDX-License-Identifier: GPL-3.0-or-later\n# Copyright 2026 CodeFortex\n')
                path.write_text(''.join(lines), encoding='utf-8', newline='\n')
    for path in (ROOT / 'scripts/build_upload_amd.cjs', ROOT / 'tests/test_upload_urls.cjs'):
        text = path.read_text()
        if 'SPDX-License-Identifier:' not in text:
            path.write_text('// SPDX-License-Identifier: GPL-3.0-or-later\n// Copyright 2026 CodeFortex\n' + text,
                            encoding='utf-8', newline='\n')
    for path in (ROOT / 'tests').glob('test_*.py'):
        text = path.read_text()
        if 'SPDX-License-Identifier:' not in text:
            path.write_text('# SPDX-License-Identifier: GPL-3.0-or-later\n# Copyright 2026 CodeFortex\n' + text,
                            encoding='utf-8', newline='\n')
    path = ROOT / 'deploy/docker/compose.yaml'
    text = path.read_text().replace('video-lesson-os2:local', 'codefortex-video-service:0.5.0-rc1')
    text = text.replace('video-lesson-os2', 'video-lesson').replace('OS2_CONFIG', 'VIDEO_CONFIG')
    path.write_text(text, encoding='utf-8', newline='\n')


if __name__ == '__main__':
    main()
