#!/usr/bin/env bash
# BulkWPSender — start both services on macOS / Linux (for local testing).
set -e
cd "$(dirname "$0")"

if [ ! -d engine/node_modules ]; then
  echo "Installing engine dependencies (first run only)..."
  (cd engine && npm install)
fi

echo "Starting WhatsApp engine on http://localhost:3000 ..."
(cd engine && npm start) &
ENGINE_PID=$!

sleep 3
echo "Starting PHP control panel on http://localhost:8080 ..."
php -S localhost:8080 -t public &
PHP_PID=$!

echo ""
echo "Control panel: http://localhost:8080"
echo "Press Ctrl+C to stop both."
trap "kill $ENGINE_PID $PHP_PID 2>/dev/null" EXIT
wait
