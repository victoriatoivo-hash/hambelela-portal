#!/usr/bin/env bash
set -euo pipefail
trap 'jobs -p | xargs -r kill' EXIT
export CW_TEST_ENV=1 HAMBELELA_DB_HOST=127.0.0.1 HAMBELELA_DB_NAME=cw_phase2_test HAMBELELA_DB_USER=cw_test HAMBELELA_DB_PASS=cw_test_password WC_STORE_URL= WC_CONSUMER_KEY= WC_CONSUMER_SECRET=
for spec in owner:8811 admin:8812 employee:8813 capabilityless:8814 logged_out:8815; do role=${spec%%:*}; port=${spec##*:}; CW_TEST_IDENTITY=$role php -d auto_prepend_file="$PWD/tests/fixtures/cost-workbook-http-bootstrap.php" -S 127.0.0.1:$port -t "$PWD" >"/tmp/cw-$role.log" 2>&1 & done
for port in 8811 8812 8813 8814 8815; do for i in {1..30}; do curl -s "http://127.0.0.1:$port/index.php" >/dev/null && break; sleep .2; done; done
node tests/cost-workbook-history-http.mjs
npx playwright test --version >/dev/null
node tests/cost-workbook-history-visual.mjs
