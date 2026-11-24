# Database safety rules

Treat the local development database and its records as persistent user data.

- Never run `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback`, `db:wipe`, `db:seed`, `DROP DATABASE`, `DROP TABLE`, `TRUNCATE`, or an equivalent destructive operation against the local development database unless the user explicitly requests the narrowly gated local reset described below.
- The only gated exception is the exact `migrate:fresh --seed` command for local MySQL database `online_learning` on a loopback host. The user must explicitly request the reset and set `ALLOW_LOCAL_ONLINE_LEARNING_RESET=I_CONFIRM_RESET_LOCAL_ONLINE_LEARNING` for that one shell invocation. The runtime guard consumes the authorization, verifies the active environment and connection, and only permits the nested default `DatabaseSeeder`. Never set this authorization on the user's behalf or run the reset without the user's explicit instruction.
- Do not reset, reseed, recreate, or replace local development data during implementation, testing, refactoring, or verification.
- Before any migration, inspect `.env` and the effective Laravel database configuration, identify the exact database target, and review the migration's `up()` method for destructive behavior. Use forward migrations only. Stop before any destructive migration and obtain explicit user approval.
- Never change `.env` database settings to redirect the application without explicit user approval.
- Before tests, inspect `phpunit.xml`, `.env.testing`, the effective test configuration, and database traits. Tests must use SQLite `:memory:` or an explicitly dedicated test database. Fail closed if test configuration could reach the development database.
- Do not use database reset commands to make tests pass. Keep application data intact.
- The application's Artisan guard blocks standard destructive database commands. This does not make arbitrary SQL through another client, custom script, or interactive shell safe; do not use those against development data.
