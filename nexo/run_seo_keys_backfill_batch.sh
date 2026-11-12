#!/usr/bin/env bash
set -euo pipefail
cd /home/trikio/roodos_v4/nexo
mkdir -p logs

set -a
[ -f .env ] && source .env
set +a

# Process one chunk and exit (checkpoint via picked column)
python3 -u scripts/backfill_seo_keys_ec.py --max-batches 1 --batch-size 3000 >> logs/backfill_seo_keys_ec.log 2>&1
