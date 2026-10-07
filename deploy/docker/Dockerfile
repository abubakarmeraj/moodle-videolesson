# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright 2026 CodeFortex
FROM python:3.12-slim-bookworm@sha256:782412e85d0f0984994c290652577d4018aff08145c85b262bb63dc0c7522254
RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg=7:5.1.9-0+deb12u1 ca-certificates && rm -rf /var/lib/apt/lists/* && useradd --uid 10001 --user-group --create-home vlmedia
WORKDIR /app
COPY service/video-service/requirements.txt service/video-service/requirements.lock ./
RUN pip install --no-cache-dir -r requirements.txt
COPY service/video-service/service ./service
COPY service/video-service/migrations ./migrations
COPY service/video-service/VERSION .
USER 10001:10001
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
