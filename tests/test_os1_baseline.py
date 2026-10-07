# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright 2026 CodeFortex
"""Isolated RC5 logic checks; fake Redis is NOT integration qualification."""
import importlib
import math
import os
from pathlib import Path
import sys
import types
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'service/video-service'))
# Synthetic, nonfunctional values only. No environment credentials are consumed.
ENV = dict(APP_ENV='production', SERVICE_ROLE='worker', SERVICE_TENANT='os1_test_tenant',
           RAW_BUCKET='videolesson-test-raw', PROCESSED_BUCKET='videolesson-test-processed',
           R2_ENDPOINT='https://' + '0' * 32 + '.r2.cloudflarestorage.com',
           GATEWAY_ORIGIN='https://media.example.invalid', ALLOWED_ORIGINS='https://lms.example.invalid',
           ALLOWED_HOSTS='video.example.invalid', REDIS_URL='redis://localhost:6379/0')

def configuration(**overrides):
    sys.modules.pop('service.config', None)
    with patch.dict(os.environ, ENV | overrides, clear=True):
        return importlib.import_module('service.config')

class ConfigTests(unittest.TestCase):
    def test_defaults(self):
        c = configuration()
        self.assertEqual((c.MAX_SIZE, c.MAX_DURATION, c.ENCODE_TIMEOUT, c.UPLOAD_TTL),
                         (256 * 1024**3, 21600, 172800, 259200))
        self.assertEqual(c.VERSION, '0.5.0-rc1')
        self.assertEqual(c.FFMPEG_THREADS, 2)

    def test_maximum_and_integer_enforcement(self):
        self.assertEqual(configuration(VIDEO_MAX_SOURCE_BYTES=str(1024**4)).MAX_SIZE, 1024**4)
        for value in (str(1024**4 + 1), '1.5', '-1'):
            with self.assertRaises((RuntimeError, ValueError)):
                configuration(VIDEO_MAX_SOURCE_BYTES=value)

    def test_worker_bounds(self):
        for values in ({'VIDEO_WORKER_CONCURRENCY': '2'}, {'VIDEO_FFMPEG_THREADS': '17'},
                       {'VIDEO_MAX_DURATION_SECONDS': '86401'}, {'VIDEO_UPLOAD_SESSION_SECONDS': '1'}):
            with self.assertRaises(RuntimeError):
                configuration(**values)

    def test_portability_constraints_are_real(self):
        with self.assertRaisesRegex(RuntimeError, 'fixed R2 endpoint'):
            configuration(R2_ENDPOINT='https://storage.example.invalid')
        self.assertEqual(configuration(APP_ENV='development').RAW, 'videolesson-test-raw')

class CapacityTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.config = configuration()
        hardening = types.ModuleType('service.hardening')
        cls.redis = types.SimpleNamespace(get=lambda key: None)
        hardening.redis_client = lambda: cls.redis
        security = types.ModuleType('service.security')
        security.Rejected = type('Rejected', (Exception,), {})
        cls.stubs = patch.dict(sys.modules, {'service.hardening': hardening, 'service.security': security})
        cls.stubs.start()
        sys.modules.pop('service.capacity', None)
        cls.capacity = importlib.import_module('service.capacity')

    @classmethod
    def tearDownClass(cls):
        cls.stubs.stop()

    def test_part_counts(self):
        for size in (1, 512 * 1024**2, 10 * 1024**3, 256 * 1024**3, 1024**4):
            part = self.capacity.part_size(size)
            self.assertLessEqual(math.ceil(size / part), 8192)
            self.assertEqual(part % 1048576, 0)
            self.assertGreaterEqual(part, 8 * 1048576)
        self.assertEqual(self.capacity.part_size(1024**4), 128 * 1048576)

    def test_missing_heartbeat_denied(self):
        with self.assertRaises(Exception) as caught:
            self.capacity.check(1048576, 60, 'standard')
        self.assertEqual(caught.exception.args[0], 'upload_capacity')

    def test_staging_is_not_old_two_gib_assumption(self):
        self.assertGreater(self.capacity.required_space(10 * 1024**3, 8 * 3600, 'full'), 10 * 1024**3)

if __name__ == '__main__':
    unittest.main()
