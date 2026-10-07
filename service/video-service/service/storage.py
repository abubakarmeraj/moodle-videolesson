# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright 2026 CodeFortex
"""Operator-owned private S3 endpoints/buckets and service-assigned keys. No user URLs."""
import os
import boto3
from botocore.config import Config
from . import config

def client(probe=False):
    access = config.storage_setting('STORAGE_ACCESS_KEY', 'R2_ACCESS_KEY')
    secret = config.storage_setting('STORAGE_SECRET_KEY', 'R2_SECRET_KEY')
    if not access or not secret:
        raise RuntimeError('Storage credentials unavailable')
    return boto3.client('s3', endpoint_url=config.ENDPOINT, region_name=config.REGION,
        aws_access_key_id=access, aws_secret_access_key=secret, verify=config.STORAGE_VERIFY,
        config=Config(signature_version='s3v4', connect_timeout=2 if probe else 10, read_timeout=3 if probe else 30,
            retries=({'total_max_attempts':1, 'mode':'standard'} if probe else {'max_attempts':3, 'mode':'standard'}),
            s3={'addressing_style':config.ADDRESSING_STYLE},
            request_checksum_calculation='when_required', response_checksum_validation='when_required'))

def readiness():
    """Read-only role-scoped reachability; never returns endpoints, names, keys or errors."""
    result = {'raw_reachable': False, 'processed_reachable': False}
    try:
        s3 = client(probe=True)
    except Exception:
        return result
    for field, bucket in (('raw_reachable', config.RAW), ('processed_reachable', config.PROCESSED)):
        try:
            s3.head_bucket(Bucket=bucket)
            result[field] = True
        except Exception:
            pass
    return result

def raw_key(video, upload):
    return f"{config.TENANT}/{video}/{upload}/source"

def processed_prefix(video, revision, job, fence):
    # A re-upload creates a new job whose fence starts again at one. Include job identity
    # so a stale previous job can never overwrite the next job's published bytes.
    return f"{config.TENANT}/{video}/{revision}/{job}/attempt-{fence}/"

def list_parts(upload):
    result = []
    for page in client().get_paginator('list_parts').paginate(Bucket=config.RAW,
            Key=upload['object_key'], UploadId=upload['multipart_id']):
        result.extend({'PartNumber':p['PartNumber'], 'ETag':p['ETag'], 'Size':p['Size']} for p in page.get('Parts', []))
    return result
