# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright 2026 CodeFortex
"""Release metadata/license/artwork guards; no infrastructure access."""
import importlib.util
import json
from pathlib import Path
import re
import tomllib
import unittest
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]


class ReleaseContract(unittest.TestCase):
    def test_identity(self):
        version = (ROOT / 'plugin/mod_videolesson/version.php').read_text()
        self.assertIn('$plugin->version = 2026100701;', version)
        self.assertIn("$plugin->release = 'CodeFortex 3.1.0-rc1';", version)
        self.assertEqual((ROOT / 'service/video-service/VERSION').read_text().strip(), '0.5.0-rc1')
        metadata = tomllib.loads((ROOT / 'service/video-service/pyproject.toml').read_text())
        self.assertEqual(metadata['project']['version'], '0.5.0rc1')
        self.assertEqual(metadata['project']['license'], 'GPL-3.0-or-later')
        self.assertIn("TESTED_SERVICE = '0.5.0-rc1'", (ROOT / 'plugin/mod_videolesson/classes/local/service_setup.php').read_text())
        self.assertEqual([p.name for p in sorted((ROOT / 'service/video-service/migrations').glob('*.sql'))],
                         ['001.sql', '002.sql', '003.sql', '004.sql', '005.sql'])

    def test_artwork(self):
        for name, size in [('monologo', 24), ('thumbnail', 120)]:
            path = ROOT / ('plugin/mod_videolesson/pix/' + name)
            text = path.with_suffix('.svg').read_text()
            svg = ET.fromstring(text)
            self.assertEqual(svg.tag, '{http://www.w3.org/2000/svg}svg')
            self.assertEqual((int(svg.attrib['width']), int(svg.attrib['height'])), (size, size))
            self.assertIn('SPDX-License-Identifier: GPL-3.0-or-later', text)
            self.assertNotIn('<!DOCTYPE', text)
            self.assertNotIn('href=', text)
            self.assertNotIn('\\n', text)
            png = path.with_suffix('.png').read_bytes()
            self.assertEqual(png[:8], b'\x89PNG\r\n\x1a\n')
            self.assertEqual((int.from_bytes(png[16:20], 'big'), int.from_bytes(png[20:24], 'big')), (size, size))

    def test_licenses_and_notices(self):
        license_text = (ROOT / 'LICENSE').read_text()
        self.assertIn('Version 3, 29 June 2007', license_text)
        self.assertIn('END OF TERMS AND CONDITIONS', license_text)
        for component in ('plugin/mod_videolesson', 'service/video-service'):
            self.assertEqual((ROOT / component / 'LICENSE').read_text().strip(), license_text.strip())
        for name in ('Plyr-3.7.8-MIT.txt', 'hls.js-1.6.2-Apache-2.0.txt', 'Apache-2.0.txt'):
            self.assertGreater((ROOT / 'docs/licenses' / name).stat().st_size, 400)
        inventory = json.loads((ROOT / 'docs/development/rc1/THIRD-PARTY-INVENTORY.json').read_text())
        for row in inventory['bundles']:
            self.assertTrue(row['exact_match'])
            import hashlib
            self.assertEqual(hashlib.sha256((ROOT / row['path']).read_bytes()).hexdigest(), row['local_sha256'])

    def test_installer_guard(self):
        spec = importlib.util.spec_from_file_location('guard', ROOT / 'deploy/installer/manifest.py')
        guard = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(guard)
        self.assertEqual(guard.verify(ROOT)['service_version'], '0.5.0-rc1')
        with self.assertRaises(RuntimeError):
            guard.verify(ROOT, expected='0' * 64)


if __name__ == '__main__':
    unittest.main()
