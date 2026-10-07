# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright 2026 CodeFortex
"""Product configuration, client contract and sanitized readiness; no cloud credentials."""
import importlib
import json
import os
import sys
import unittest
from contextlib import contextmanager
from pathlib import Path
from unittest.mock import Mock, patch
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'service/video-service'))
from test_os1_baseline import ENV, configuration

class StorageConfiguration(unittest.TestCase):
    def s3(self, **kwargs):
        return configuration(R2_ENDPOINT='', STORAGE_BACKEND='s3-compatible',
                             STORAGE_ENDPOINT=kwargs.pop('STORAGE_ENDPOINT', 'https://storage.example.invalid'), **kwargs)

    def test_historical_r2_client(self):
        cfg = configuration()
        module = importlib.import_module('service.storage')
        with patch.object(module, 'config', cfg), patch.dict(os.environ, {
                'R2_ACCESS_KEY':'synthetic-access', 'R2_SECRET_KEY':'synthetic-secret'}, clear=True), \
                patch.object(module.boto3, 'client') as factory:
            module.client()
            call = factory.call_args.kwargs
            self.assertEqual(call['endpoint_url'], ENV['R2_ENDPOINT'])
            self.assertEqual(call['region_name'], 'auto')
            self.assertTrue(call['verify'])
            self.assertEqual(call['config'].signature_version, 's3v4')
            self.assertEqual(call['config'].s3['addressing_style'], 'path')
            self.assertEqual(call['aws_access_key_id'], 'synthetic-access')

    def test_generic_client(self):
        cfg = self.s3(STORAGE_REGION='us-east-2', STORAGE_ADDRESSING_STYLE='virtual')
        module = importlib.import_module('service.storage')
        with patch.object(module, 'config', cfg), patch.dict(os.environ, {
                'STORAGE_ACCESS_KEY':'synthetic-access', 'STORAGE_SECRET_KEY':'synthetic-secret'}, clear=True), \
                patch.object(module.boto3, 'client') as factory:
            module.client()
            self.assertEqual(factory.call_args.kwargs['region_name'], 'us-east-2')
            self.assertEqual(factory.call_args.kwargs['config'].s3['addressing_style'], 'virtual')

    def test_reject_endpoints(self):
        for value in ('file:///tmp/a', 'http://localhost:19000', 'https://user:pass@host',
                      'https://host/path', 'https://host?secret=a', 'https://host#fragment',
                      'https://host:99999', 'https://host:0', 'https://host name', 'https://host\\evil',
                      'https://host\x01', 'https://host?', ''):
            with self.subTest(endpoint=value), self.assertRaises(RuntimeError):
                self.s3(STORAGE_ENDPOINT=value)

    def test_explicit_development_http_only(self):
        cfg = self.s3(APP_ENV='development', STORAGE_ENDPOINT='http://127.0.0.1:19000',
                      STORAGE_ALLOW_INSECURE_HTTP='true')
        self.assertTrue(cfg.STORAGE_VERIFY)
        self.assertEqual(cfg.RAW, 'videolesson-test-raw')
        with self.assertRaises(RuntimeError):
            self.s3(STORAGE_ENDPOINT='http://127.0.0.1:19000', STORAGE_ALLOW_INSECURE_HTTP='true')

    def test_invalid_options(self):
        for kwargs in ({'STORAGE_REGION':'bad region'}, {'STORAGE_ADDRESSING_STYLE':'auto'},
                       {'STORAGE_CA_BUNDLE':'/missing/os2-test.pem'}, {'STORAGE_ALLOW_INSECURE_HTTP':'yes'},
                       {'PROCESSED_BUCKET':ENV['RAW_BUCKET']}, {'VIDEO_MAX_DURATION_SECONDS':'21601'}):
            with self.subTest(options=kwargs), self.assertRaises(RuntimeError):
                self.s3(**kwargs)
        with self.assertRaises(RuntimeError):
            configuration(STORAGE_ENDPOINT='https://different.example.invalid')

    def test_duration_common_bound(self):
        self.assertEqual(self.s3().MAX_DURATION, 21600)
        self.assertEqual(self.s3(VIDEO_MAX_DURATION_SECONDS='3600').MAX_DURATION, 3600)

class Readiness(unittest.TestCase):
    def setUp(self):
        self.cfg = configuration()
        self.module = importlib.import_module('service.capabilities')
        self.cursor = Mock()
        self.cursor.fetchall.return_value = [{'version':v} for v in range(1,6)]
        self.redis = Mock()
        self.redis.ping.return_value = True
        self.redis.get.return_value = json.dumps({'free':1024**3,'maxbytes':1024**3,'duration':21600})
        @contextmanager
        def transaction():
            yield self.cursor
        self.patches = [patch.object(self.module, 'config', self.cfg),
                        patch.object(self.module, 'transaction', transaction),
                        patch.object(self.module, 'redis_client', return_value=self.redis),
                        patch.object(self.module.storage, 'readiness', return_value={'raw_reachable':True,'processed_reachable':True})]
        for p in self.patches: p.start()
        self.addCleanup(lambda:[p.stop() for p in reversed(self.patches)])

    def test_ready_whitelist(self):
        value = self.module.describe()
        self.assertTrue(value['schema_ready'] and value['worker_capacity_ready'])
        self.assertEqual(set(value), {'protocol_version','service_version','schema_ready','schema_versions',
            'storage_backend','storage_config_valid','raw_reachable','processed_reachable','redis_ready',
            'worker_capacity_ready','max_duration_seconds','max_source_bytes'})

    def test_schema_not_ready(self):
        self.cursor.fetchall.return_value = [{'version':1}]
        self.assertFalse(self.module.describe()['schema_ready'])

    def test_no_worker(self):
        self.redis.get.return_value = None
        self.assertFalse(self.module.describe()['worker_capacity_ready'])

    def test_unavailable_storage(self):
        self.module.storage.readiness.return_value = {'raw_reachable':False,'processed_reachable':False}
        self.assertFalse(self.module.describe()['raw_reachable'])

    def test_storage_exception_is_not_leaked(self):
        storage = importlib.import_module('service.storage')
        # Stop mocked readiness for this real implementation exercise.
        self.patches[-1].stop()
        with patch.object(storage, 'client', side_effect=RuntimeError('DO NOT EXPOSE')):
            self.assertEqual(storage.readiness(), {'raw_reachable':False,'processed_reachable':False})
