# Validation record

Run date: October 27, 2026.

## Executed locally

- PHP 8.3.6, SQLite 3.45.1.
- Laravel framework 13.38.0 (current compatible stable patch resolved by Composer).
- Orchestra Testbench 11.3.0, Testbench Core 11.6.0.
- PHPUnit 11.5.57.
- `composer test`: **17 tests, 72 assertions, passed**.
- `composer lint`: all package, migration, example, and test PHP files passed syntax checks.
- `composer validate --strict`: passed.
- Separate temporary consumer project installed `outbox/laravel-outbox` from a Composer
  path repository with `--no-dev`, resolving standalone Illuminate 13.38.0 components.
  A SQLite smoke check recorded in a transaction, claimed, and acknowledged an event successfully.

The test suite includes a synchronized race between two independent PHP processes,
a deterministic stale-candidate interleaving, isolation of uncommitted rows, rollback,
expired-lease recovery, retry timing, final-attempt crashes, stale tokens, and duplicate
Laravel database-queue deliveries with a transactional unique-key consumer inbox.

## Not executed locally

MySQL, PostgreSQL, PHP 8.4, PHP 8.5, and GitHub-hosted Actions were not available as
running test environments in this session. `.github/workflows/tests.yml` configures
those checks. Their passing status is not claimed.

Crash tests deliberately stop at the publish/ack boundary by omitting acknowledgement.
They validate the stored state and recovery protocol, not physical disk power-loss
behavior or every transport's acknowledgement durability.

This is a reusable library: `composer.lock` is excluded from source control and the
archive; fresh dependency resolution can select newer compatible releases.
