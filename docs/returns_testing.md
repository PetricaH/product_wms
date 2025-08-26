# Return Processing Testing Guide

## Generating Test Data
Run `php scripts/generate_return_test_data.php` to insert sample products, orders and a pending return.

## API Smoke Tests
Use `scripts/test_returns_api.sh` against a running instance:
```bash
BASE_URL=http://localhost/api/returns ./scripts/test_returns_api.sh
```

## Unit Tests
```bash
composer install
vendor/bin/phpunit --filter ReturnServiceTest
```

## Integration Tests
```bash
vendor/bin/phpunit --filter ReturnWorkflowTest
```

## Performance Tests
```bash
vendor/bin/phpunit --filter ReturnPerformanceTest
```

These tests cover edge cases such as duplicate returns, invalid order numbers and inventory conflicts.
