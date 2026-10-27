# Contributing

Use PHP 8.3+ and Composer 2. Run `composer install`, `composer validate --strict`,
`composer lint`, and `composer test`. Keep database claims atomic, preserve event
IDs on retries, and include meaningful failure-boundary tests for delivery changes.
Do not promise exactly-once delivery. Use dedicated disposable databases for integration tests.
