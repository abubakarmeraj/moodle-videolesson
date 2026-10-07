#!/usr/bin/env bash
# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright 2026 CodeFortex
set -euo pipefail
# No curl-pipe-shell, password arguments, or source-tree mutation.
cd -- "$(dirname -- "${BASH_SOURCE[0]}")"
exec python3 install.py "$@"
