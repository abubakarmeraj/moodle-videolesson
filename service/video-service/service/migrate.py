# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright 2026 CodeFortex
"""Explicit deployment command; never run migrations inside an HTTP request."""
from pathlib import Path
from .db import transaction
for migration in sorted((Path(__file__).parents[1] / 'migrations').glob('[0-9][0-9][0-9].sql')):
    with transaction() as c:
        for statement in migration.read_text().split(';'):
            if statement.strip(): c.execute(statement)
    print('Service schema migration '+migration.stem+' complete')
