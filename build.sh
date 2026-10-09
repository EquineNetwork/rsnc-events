#!/bin/sh
# Builds dist/rsnc-events.zip (a folder "rsnc-events/" inside, as WordPress expects).
set -eu
cd "$(dirname "$0")"
rm -rf dist && mkdir -p dist/rsnc-events
cp -R rsnc-events.php readme.txt includes assets dist/rsnc-events/
(cd dist && zip -qr rsnc-events.zip rsnc-events && rm -rf rsnc-events)
echo "Built dist/rsnc-events.zip"
