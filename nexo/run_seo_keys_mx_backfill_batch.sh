#!/usr/bin/env bash
set -euo pipefail
cd /home/trikio/roodos_v4/nexo
mkdir -p logs

set -a
[ -f .env ] && source .env
set +a

SHARDS="${SHARDS:-4}"
SHARD_ID="${SHARD_ID:-0}"
BATCH_SIZE="${BATCH_SIZE:-12000}"

python3 -u scripts/backfill_seo_keys_ec.py \
  --table seo_keys_MX --country mx \
  --max-batches 1 --batch-size "$BATCH_SIZE" \
  --shards "$SHARDS" --shard-id "$SHARD_ID" \
  >> logs/backfill_seo_keys_mx.log 2>&1
