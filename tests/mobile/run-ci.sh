#!/usr/bin/env bash
# CI-only: build a synthetic portal database, serve the portal as a fixed identity, capture mobile screenshots.
set -euo pipefail
trap 'jobs -p | xargs -r kill' EXIT
export MOBILE_TEST_ENV=1 HAMBELELA_DB_HOST=127.0.0.1 HAMBELELA_DB_NAME=mobile_test HAMBELELA_DB_USER=mobile_test HAMBELELA_DB_PASS=mobile_test_password
export WC_STORE_URL= WC_CONSUMER_KEY= WC_CONSUMER_SECRET=
MYSQL="mysql -h127.0.0.1 -umobile_test -pmobile_test_password mobile_test"

# Schema: base operations schema first, then every other migration (tolerating re-applied ALTERs).
$MYSQL --force < operations-migration.sql 2>/dev/null || true
for file in $(ls *migration*.sql | grep -v '^operations-migration.sql$'); do $MYSQL --force < "$file" >/dev/null 2>&1 || true; done

for spec in owner:8821 front:8822 marketing:8823 packer:8824; do
  role=${spec%%:*}; port=${spec##*:}
  MOBILE_TEST_IDENTITY=$role php -d auto_prepend_file="$PWD/tests/mobile/bootstrap.php" -d display_errors=0 -d log_errors=1 -d error_log="/tmp/php-$role.log" -S 127.0.0.1:$port -t "$PWD" >"/tmp/server-$role.log" 2>&1 &
done
for port in 8821 8822 8823 8824; do for i in {1..40}; do curl -s -o /dev/null "http://127.0.0.1:$port/login.php" && break; sleep .25; done; done

php tests/mobile/seed.php people   # employees/roles first so warm-up requests authenticate
# Warm-up requests let each page create its lazily-managed tables and columns.
for path in apps/operations/orders-board.php apps/operations/orders-board-data.php apps/operations/bookkeeping.php apps/operations/consignments.php apps/operations/packing-list-data.php apps/operations/checklists.php; do
  curl -s -o /dev/null -w "warm-up $path %{http_code}\n" "http://127.0.0.1:8821/$path"
done
php tests/mobile/seed.php

node tests/mobile/visual.mjs
echo "---- PHP errors (owner) ----"; tail -n 40 /tmp/php-owner.log 2>/dev/null || true
