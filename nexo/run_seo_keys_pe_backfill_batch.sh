#!/usr/bin/env bash
set -euo pipefail
cd /home/trikio/roodos_v4/nexo
mkdir -p logs

set -a
[ -f .env ] && source .env
set +a

python3 -u scripts/backfill_seo_keys_ec.py --table seo_keys_PE --country pe --max-batches 1 --batch-size 10000 >> logs/backfill_seo_keys_pe.log 2>&1
