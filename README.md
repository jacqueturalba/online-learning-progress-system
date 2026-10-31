# Learnwell

A student-first learning and progress platform built with Laravel 13, PHP 8.3+, MySQL 8+, Vue 3, TypeScript, Vite 7, and Tailwind CSS 4.

Laravel sessions and the database remain the source of truth for identity and learning progress. Redis is not required.

## Requirements

- PHP 8.3+ with PDO MySQL, OpenSSL, Mbstring, Tokenizer, XML, Ctype, JSON, and Fileinfo extensions
- Composer 2.8+
- Node.js 20.19+ or 22.12+ and npm
- MySQL 8+
- Git (recommended)

### Windows / XAMPP environment

The local development environment uses:

- XAMPP with PHP 8.3+
- MySQL provided by XAMPP
- Git Bash or Windows PowerShell
- Laravel Artisan development server
- Node.js and npm for frontend assets

Example project location:

```text
C:\xampp8.3.36\htdocs\online-learning
```

Apache is not required when using `php artisan serve`. Start the MySQL service through XAMPP Control Panel.

## Local installation

### 1. Clone or open the project

```bash
cd /c/xampp8.3.36/htdocs/online-learning
```

### 2. Install PHP dependencies

```bash
composer install
```

If Composer encounters SSL certificate errors on Windows, verify the PHP CA bundle configuration and check whether antivirus HTTPS scanning is intercepting SSL connections.

Do not disable Composer or PHP SSL certificate verification as a workaround.

### 3. Install frontend dependencies

```bash
npm install
```

The project uses Vite 7. Install a compatible Laravel Vite plugin version if it is not already included in the project dependencies:

```bash
npm install -D laravel-vite-plugin@^2
```

Avoid installing Laravel Vite Plugin 3.x without upgrading Vite because version 3.x requires Vite 8.

### 4. Configure the environment

Copy the environment example:

Git Bash:

```bash
cp .env.example .env
```

Windows PowerShell:

```powershell
Copy-Item .env.example .env
```

Generate the application key:

```bash
php artisan key:generate
```

## Environment and database

Configure `.env` with the appropriate local settings:

```env
APP_NAME=Learnwell
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=learnwell
DB_USERNAME=root
DB_PASSWORD=

SESSION_DRIVER=database
CACHE_STORE=file
QUEUE_CONNECTION=sync
```

Adjust the database credentials according to your MySQL configuration.

### Create the database

1. Start MySQL in XAMPP Control Panel.
2. Open phpMyAdmin.
3. Create an empty database named `learnwell`.
4. Confirm that the database settings in `.env` match your MySQL configuration.

Never commit `.env` or expose production credentials in source control.

## Frontend configuration

The project uses Laravel Vite integration with Vue 3 and Tailwind CSS 4.

### Vite configuration

The `vite.config.ts` configuration must use `laravel-vite-plugin` rather than treating the project as a standalone Vue application.

Expected entry points:

```text
resources/css/app.css
resources/js/app.ts
```

The Blade layout loads the frontend through:

```php
@vite(['resources/css/app.css', 'resources/js/app.ts'])
```

The production build generates:

```text
public/
└── build/
    ├── manifest.json
    └── assets/
```

Do not create a separate `index.html` entry point for this Laravel application.

### Build frontend assets

```bash
npm run build
```

The build command runs TypeScript validation before compiling the frontend:

```text
vue-tsc --noEmit && vite build
```

A successful build is required before Laravel can load the production frontend assets.

If the build reports TypeScript errors, resolve them rather than disabling type checking.

## Migrations and development seed data

Run:

```bash
php artisan migrate
php artisan db:seed
```

Seeded development-only credentials (change them immediately and never use them in production):

- Admin: `admin@example.test` / `ChangeMe123!`
- Students: `jamie@example.test`, `taylor@example.test`, `morgan@example.test` / `ChangeMe123!`

The seeder creates four starter categories and two sample lessons per category.

Category and lesson counts are database-driven and can be changed by administrators.

## Start locally

Run the following commands in separate terminals.

**Terminal 1 – Laravel application**

```bash
php artisan serve
```

**Terminal 2 – Vite development server**

```bash
npm run dev
```

Open:

```text
http://127.0.0.1:8000
```

Vite handles frontend assets during development. Keep the Vite terminal running while using the application.

### Alternative: Test using production-built assets

If you want to test without the Vite development server:

```bash
npm run build
php artisan optimize:clear
php artisan serve
```

Laravel will load the compiled assets from `public/build/manifest.json`.

## Tests

Run the Laravel test suite:

```bash
php artisan test
```

Feature tests use an in-memory SQLite database and do not modify the configured MySQL database.

## Browser flow test

Build the frontend:

```bash
npm run build
```

Install Chromium once:

```bash
npx playwright install chromium
```

Start Laravel using the seeded development database:

```bash
php artisan serve
```

Then run:

```bash
npm run test:e2e
```

The smoke flow registers a student and completes a seeded lesson.

## Common local setup issues

### 1. Composer SSL certificate error (cURL error 60)

If Composer cannot connect to Packagist and reports:

```text
SSL certificate problem: unable to get local issuer certificate
```

Check the following:

- PHP's loaded `php.ini`.
- The configured `curl.cainfo`.
- The configured `openssl.cafile`.
- Whether antivirus HTTPS scanning is intercepting SSL connections.

If Avast Web Shield intercepts HTTPS, PHP may receive a certificate issued by Avast Web/Mail Shield instead of the original server certificate.

Configure PHP to trust the appropriate Avast root certificate or adjust HTTPS scanning through Avast's supported settings.

Do not disable SSL peer verification.

### 2. Vite manifest not found

Error:

```text
Vite manifest not found at:
public/build/manifest.json
```

This usually means the frontend has not been successfully compiled.

Run:

```bash
npm install
npm run build
```

Check that `public/build/manifest.json` exists.

### 3. Vite cannot resolve index.html

Error:

```text
Could not resolve entry module "index.html"
```

Check that `vite.config.ts` includes `laravel-vite-plugin` and defines the Laravel entry points.

The project should use Laravel's Blade layout and `@vite()` directive, not a standalone Vue `index.html`.

### 4. TypeScript errors during build

Run:

```bash
npx vue-tsc --noEmit
```

Resolve reported errors before running:

```bash
npm run build
```

### 5. Dependency compatibility issues

The current frontend uses Vite 7.

Check installed versions:

```bash
npm ls vite laravel-vite-plugin
```

Use a Laravel Vite plugin version compatible with the installed Vite version.

Avoid `npm install --force` and `npm install --legacy-peer-deps` to bypass dependency conflicts.

## Production deployment (Nginx / PHP-FPM / Plesk)

1. Install the required PHP extensions, Composer, Node.js/npm, and MySQL.
2. Configure the web server document root to point to the application's `public/` directory.
3. Deploy the application source and install production dependencies:

```bash
composer install --no-dev --classmap-authoritative
npm ci
npm run build
```

4. Configure the production `.env` securely with:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com
SESSION_SECURE_COOKIE=true
```

Configure the production MySQL credentials and a unique application key.

5. Run migrations:

```bash
php artisan migrate --force
```

6. Optimize Laravel:

```bash
php artisan optimize
```

7. Ensure `storage/` and `bootstrap/cache/` are writable by the PHP-FPM user.
8. Serve the application over HTTPS.
9. Deny public access to `.env`, source files, vendor files, and other non-public application paths.

The production server must have the compiled `public/build/manifest.json` and its associated assets.

Do not run `npm run dev` in production.

Commit `composer.lock` and `package-lock.json` so dependency versions remain consistent across environments.

## Production database migration

Back up the database before deploying schema changes.

Run:

```bash
php artisan migrate --force
```

as part of the release after reviewing the migrations.

Keep the previous application release available for rollback.

If a migration is not safely reversible, restore the pre-deployment database backup rather than assuming a code rollback will reverse database changes.

## Storage, cache, and optional Redis

The first version uses:

- Database sessions
- File-based cache
- Synchronous queue processing

No queue worker or Redis service is required.

Back up `storage/` if it contains user-managed files. Lesson videos are external URLs in this version.

Redis can be introduced later by installing the appropriate Redis client/extension and configuring the cache, session, or queue drivers after provisioning Redis.

## Backup and rollback

### Database backup

Take regular MySQL backups and store encrypted copies outside the production server.

Example:

```bash
mysqldump -u DB_USERNAME -p DB_DATABASE > backup.sql
```

Before every production migration:

1. Create a database backup.
2. Verify that the backup file exists.
3. Review the migration changes.
4. Keep the previous application release available.

### Application rollback

1. Repoint the web server to the previous application release.
2. Restore the matching application configuration.
3. Clear Laravel's optimized caches:

```bash
php artisan optimize:clear
```

4. Restore the matching database backup only when the new schema or data cannot safely support the previous application version.

Test database restoration periodically.

## Foundation notes

- Roles are stored in a single `admin` / `student` column with route middleware. A more detailed policy layer can be introduced when needed.
- Category progress summaries are calculated from lesson progress. The category progress table stores start/completion timestamps only, avoiding a duplicate percentage source.
- A lesson is marked complete by the backend when recorded playback reaches 90%. The frontend displays the server response. The threshold is a single backend rule and can be adjusted as product behavior becomes clearer.
- Password reset endpoints use Laravel's password broker. Local mail is logged; configure SMTP for production delivery.
- Reporting endpoints expose paginated progress and student data for future reports.
- AI and Zoom APIs are intentionally not integrated.
## Safe production administrator setup

The development seeder only runs in local and testing environments; never use its demo accounts in production. After configuring production environment variables and running php artisan migrate --force, create the first administrator with php artisan app:create-admin. It prompts for a name, email, and hidden password and will not elevate an existing account. Configure production SMTP, HTTPS, APP_DEBUG=false, and a unique APP_KEY before opening the service to students.

Playback completion is derived from server-timed, plausible forward progress and the stored lesson duration. Large seeks and client-supplied duration values do not earn completion. Because browser playback is client-controlled, this validation does not provide DRM or cryptographic proof of playback.
