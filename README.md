# Learnwell

A small, student-first learning and progress platform built with Laravel 13, PHP 8.3+, MySQL 8+, Vue 3, TypeScript, Vite, and Tailwind CSS 4. Laravel sessions and the database remain the source of truth for identity and progress. Redis is not required.

## Requirements

- PHP 8.3+ with PDO MySQL, OpenSSL, Mbstring, Tokenizer, XML, Ctype, JSON, and Fileinfo extensions
- Composer 2.8+
- Node.js 20.19+ or 22.12+ and npm
- MySQL 8+

## Local installation

```sh
composer install
npm install
cp .env.example .env
php artisan key:generate
```

On Windows PowerShell, use `Copy-Item .env.example .env` instead of `cp`.

## Environment and database

Set `APP_URL`, `APP_ENV`, `APP_DEBUG`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in `.env`. Create the empty MySQL database first. Keep production secrets out of source control. Set `APP_DEBUG=false`, use a strong unique `APP_KEY`, enable HTTPS, and set `SESSION_SECURE_COOKIE=true` in production.

## Migrations and development seed data

```sh
php artisan migrate
php artisan db:seed
```

Seeded development-only credentials (change them immediately and never use these in production):

- Admin: `admin@example.test` / `ChangeMe123!`
- Students: `jamie@example.test`, `taylor@example.test`, `morgan@example.test` / `ChangeMe123!`

The seeder creates the four starter categories and two sample lessons per category. Category and lesson counts are database-driven and can be changed by admins.

## Start locally

Run these in separate terminals:

```sh
php artisan serve
npm run dev
```

Open the URL printed by `artisan serve`. For a production frontend bundle, run `npm run build`.

## Tests

```sh
php artisan test
```

Feature tests use an in-memory SQLite database and do not change the configured MySQL database.

## Browser flow test

Build the frontend with `npm run build`, install the Chromium browser once with `npx playwright install chromium`, and start Laravel with the seeded development database (`php artisan serve`). Then run `npm run test:e2e`. The smoke flow registers a student and completes a seeded lesson.

## Production deployment (Nginx + PHP-FPM)

1. Install the required PHP extensions, Composer, Node/npm, and MySQL. Point Nginx's document root at the app's `public/` directory and configure PHP-FPM with the same PHP version.
2. Deploy source, then run `composer install --no-dev --classmap-authoritative` and `npm install && npm run build` during release preparation. Commit generated lock files after the first successful dependency install so later releases can use the locked dependency set.
3. Configure the production `.env` securely with MySQL credentials, `APP_ENV=production`, `APP_DEBUG=false`, the public HTTPS `APP_URL`, `SESSION_SECURE_COOKIE=true`, and a unique `APP_KEY`.
4. Run `php artisan migrate --force`, then `php artisan optimize`. Ensure `storage/` and `bootstrap/cache/` are writable by the PHP-FPM user. Keep `storage/` and `.env` outside the public document root.
5. Serve the app over HTTPS. Configure Nginx to route requests to `public/index.php` and deny access to dotfiles and non-public application paths.

## Production database migration

Back up the database before deploying schema changes. Run `php artisan migrate --force` as part of the release after reviewing the migration. Keep the previous release available for application rollback. If a migration is not safely reversible, restore the pre-deploy database backup rather than assuming a code rollback also reverses data changes.

## Storage, cache, and optional Redis

The first version uses database sessions, file cache, and a synchronous queue by default; no queue worker or Redis service is needed. Back up `storage/` if it contains user-managed files (lesson videos are external URLs in this version). Redis can be introduced later by installing a Redis client/extension and changing the cache, session, or queue driver settings after provisioning Redis; it is optional and not configured as a requirement here.

## Backup and rollback

Take encrypted, off-server MySQL backups with `mysqldump` and test restores periodically. Keep a recent backup before each migration. To roll back an application release, repoint Nginx to the previous release and clear Laravel's optimized caches; restore the matching database backup only when the new schema or data cannot safely serve the old release.

## Foundation notes

- Roles are a single `admin`/`student` column with route middleware, ready for a more detailed policy layer only if needed.
- Category progress summaries are calculated from lesson progress. The category progress table stores start/completion timestamps only, avoiding a duplicate percentage source.
- A lesson is marked complete by the backend when recorded playback reaches 90%; the frontend displays the server response. The threshold is a single backend rule and can be adjusted as product behavior becomes clearer.
- Password reset endpoints use Laravel's password broker. Local mail is logged; configure SMTP for production delivery.
- Reporting endpoints expose paginated progress and student data for future reports. AI and Zoom APIs are intentionally not integrated.
