#!/bin/sh
set -eu

cd "$(dirname "$0")"

if [ ! -d node_modules ] || [ ! -f node_modules/playwright/package.json ]; then
  npm ci --no-audit --no-fund
fi

if ! command -v import >/dev/null 2>&1; then
  apt-get update
  DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends imagemagick
fi

Xvfb :99 -screen 0 1440x900x24 -nolisten tcp >/tmp/xvfb.log 2>&1 &
XVFB_PID=$!
trap 'kill "$XVFB_PID" 2>/dev/null || true' EXIT

export DISPLAY=:99
exec node server.js
