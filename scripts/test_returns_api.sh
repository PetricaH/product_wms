#!/bin/bash
# Simple API smoke tests for return endpoints
BASE_URL=${BASE_URL:-http://localhost:8000/api/returns}

set -e

curl -s "$BASE_URL/index.php/lookup/R-100" | grep -q 'order_number' && echo "Lookup ok"

curl -s -X POST "$BASE_URL/index.php/start" -H 'Content-Type: application/json' \
     -d '{"order_number":"R-100","processed_by":1}' | grep -q 'return_id' && echo "Start ok"

curl -s -X POST "$BASE_URL/index.php/1/verify-item" -H 'Content-Type: application/json' \
     -d '{"product_id":1001,"quantity":1}' | grep -q 'success' && echo "Verify ok"

curl -s -X POST "$BASE_URL/index.php/1/complete" -H 'Content-Type: application/json' \
     -d '{"verified_by":2}' | grep -q 'completed' && echo "Complete ok"
