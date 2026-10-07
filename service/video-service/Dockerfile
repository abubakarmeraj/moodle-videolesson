# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright 2026 CodeFortex
FROM python:3.12-slim-bookworm@sha256:782412e85d0f0984994c290652577d4018aff08145c85b262bb63dc0c7522254
RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg ca-certificates \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --uid 10001 --create-home cfvideo
WORKDIR /app
COPY requirements.txt requirements.lock ./
RUN pip install --no-cache-dir -r requirements.txt
COPY service /app/service
COPY migrations /app/migrations
COPY VERSION /app/VERSION
USER 10001:10001
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
CMD ["uvicorn", "service.api:app", "--host", "0.0.0.0", "--port", "8090", "--no-access-log", "--no-proxy-headers", "--limit-concurrency", "80", "--timeout-keep-alive", "5", "--timeout-graceful-shutdown", "30"]
