# Production Deployment on Plesk Obsidian

This guide describes deploying this codebase to Plesk Obsidian 18.0.82, PHP 8.4.x, and MySQL. It documents the inspected implementation; no production deployment is performed. Commands marked **Plesk Terminal / SSH** run on the server as the subscription user from the Laravel project root unless stated otherwise. Commands marked **Plesk interface** are control-panel actions. Replace every example path and value with the actual values from your subscription.

Project constraints and lockfiles resolve PHP `^8.3`, Laravel Framework 13.39.0, Vue 3, TypeScript, Vite 7, and Tailwind CSS 4. PHP 8.4 satisfies the Composer constraint.

## 1. Application facts for deployment

- The web root must be the Laravel project's `public/` directory. The repository root must not be web-accessible.
- `.env.example` is the source for environment names. Production APP_KEY, DB credentials, and SMTP credentials are private and must not be committed.
- Current example settings are `SESSION_DRIVER=database`, `CACHE_STORE=file`, and `QUEUE_CONNECTION=sync`. Migrations create the `sessions` table. No queued job or scheduled task is currently registered; a worker and scheduler cron are not currently required.
- Lesson videos have a 1,000 MB application validation maximum. They are stored on the Laravel `local` disk rooted at `storage/app/private`, then streamed by an authenticated student route. Branding files use `storage/app/public` and the public storage link.
- Zoom Server-to-Server OAuth credentials are entered under Admin → Zoom, stored encrypted in MySQL, and used by backend-only services. Tokens are cached server-side. Zoom settings are not environment variables.
- `.env.example` uses `MAIL_MAILER=log`; password resets use Laravel's mail broker, so production SMTP must be configured for delivery.
- Create the initial production administrator with `php artisan app:create-admin`. The development seeder is restricted to local/testing and must not be run in production.

## A. Prepare Plesk

### A1. Create the domain or subdomain and set the document root

**Plesk interface:** Create the domain/subdomain under **Websites & Domains**, configure its DNS at the DNS provider, and enable hosting/PHP support. Set the domain's **Hosting Settings → Document root** to the Laravel project's `public/` directory. Example Linux webspace:

```text
/var/www/vhosts/example.com/online-learning/        # application/repository root
/var/www/vhosts/example.com/online-learning/public  # document root
```

Plesk document-root values are often relative to the webspace; `online-learning/public` is only an example. Use the path accepted by the hosting settings for this domain. Plesk supports a custom document root for frameworks that use an application `public` directory. If the subscription cannot select it, ask the Plesk administrator/provider to configure it. Do not publish the repository root into `httpdocs` as a workaround.

### A2. Create a MySQL database and user

**Plesk interface:** Go to **Websites & Domains → Databases**, create a dedicated MySQL database and user, and grant the user access to that database. Record the database name, host, port, username, and password exactly as Plesk provides them. The host may be `localhost` or provider-specific. Use a strong unique password. Start with an empty database; do not point this installation at an unrelated/shared production database.

### A3. Select PHP 8.4 and required extensions

**Plesk interface:** Go to **Websites & Domains → [domain] → PHP**, enable PHP, choose PHP 8.4.x, and select a supported PHP-FPM handler where available. Confirm the website PHP runtime and PHP CLI used by Terminal/SSH: they may use different executables and `php.ini` files.

Enable for the web runtime and CLI: `ctype`, `filter`, `hash`, `mbstring`, `openssl`, `session`, `tokenizer`, `pdo`, `pdo_mysql`, and `fileinfo`. cURL is recommended for reliable Zoom API HTTPS requests. XML support (`dom`, `libxml`, `simplexml`, `xml`, `xmlreader`, `xmlwriter`) is recommended for PHP tooling; tests/dev tools can require more extensions than web runtime. The application XLSX implementation does not use `ZipArchive` or a PHP Excel package.

**Plesk Terminal / SSH:** Verify the command-line runtime before Composer/Artisan work:

```bash
php -v
php --ini
composer --version
```

If CLI PHP is not 8.4, use the PHP 8.4 executable path provided by the host for Composer and Artisan. Do not assume a server-specific Plesk PHP path.

### A4. Set PHP and HTTP upload limits

**Plesk interface:** Set these in the domain's PHP settings (not only the operating-system CLI `php.ini`):

```ini
file_uploads = On
upload_max_filesize = 1024M
post_max_size = 1100M
max_execution_time = 1800
max_input_time = 1800
```

The Laravel validator permits 1,000 MB (1,024,000 KB). `upload_max_filesize` must be at least this large; `post_max_size` must be larger for multipart form overhead. Confirm Plesk applied the values to the selected website handler. Ensure the PHP temporary directory and `storage/app/private` have sufficient free disk. Temporary and stored copies may coexist during upload. Increasing `memory_limit` is not required.

Nginx, Apache, a CDN, or a hosting firewall may impose a separate request-body cap. If Nginx proxies to Apache, configure the Nginx cap above the PHP post limit (for example, around 1,100 MB) in **Apache & Nginx Settings** using the provider-approved directive. Ask the administrator to raise Apache's cap too if applicable. Do not put an Nginx directive in Apache settings or vice versa. Reload services only through provider/Plesk procedures.

Verify website PHP values in the Plesk domain settings/PHP information view, and separately check CLI values with `php --ini`/`php -i`. Never leave a public `phpinfo()` page on the site.

## B. Deploy from GitHub

### B1. Connect and place the repository

Configured remote:

```text
git@github.com:jacqueturalba/online-learning-progress-system.git
```

The browser URL is [github.com/jacqueturalba/online-learning-progress-system](https://github.com/jacqueturalba/online-learning-progress-system). Remote default branch is `main`; confirm the approved production branch and commit with the maintainer before deployment.

**Plesk interface:** Under **Websites & Domains → Git**, add the remote GitHub repository and authenticate through Plesk or a dedicated read-only GitHub deploy key. Add only a deploy key's public portion to GitHub. Never put a private key or token in source files, deployment scripts, or command history. Select the approved branch.

Use manual deployment (or no auto-publishing if the Plesk checkout itself is the application root). Do not enable publish-on-push for this Laravel application: publishing source alone does not install Composer packages, build Vite files, run reviewed migrations, or rebuild Laravel caches.

Place the repository/application root in a private subscription directory such as `<webspace>/online-learning`, and keep the document root at `<webspace>/online-learning/public`. Plesk separates repository location from deployment/publish directory; review both paths carefully. Do not deploy repository files into the public `httpdocs` root.

### B2. Protect secrets and persistent storage

The current `.gitignore` ignores the root `.env` and `.env.*.local`, but does **not** ignore `.env.production` or arbitrary `.env.*` files. Therefore:

- Create only the server-side root `.env` described below. Do not create production settings under another tracked-capable filename inside the repo.
- Keep DB/SMTP values in the server `.env`; Zoom credentials belong in Admin → Zoom. Never put Zoom secrets/tokens into frontend code or Git.
- Preserve `storage/app/private` (videos) and `storage/app/public/branding` (logos) during source updates and back them up.
- Before committing or publishing, inspect `git status`; ensure no secrets, `.env` variants, production data, uploads, or logs are staged.
- If any `.env.*` file is needed locally, add an ignore rule before creating it and verify that Git ignores it. Do not assume `.env.production` is ignored.

## C. Configure Laravel

### C1. Install production dependencies

**Plesk Terminal / SSH**, from the application root (replace the example path):

```bash
cd /var/www/vhosts/example.com/online-learning
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
```

`composer.lock` is committed; use `composer install`, not `composer update`, on production. If Composer uses a different PHP binary than the domain, invoke it with the host-provided PHP 8.4 runtime.

### C2. Create and configure `.env`

From the Laravel application root, create `.env` only when absent:

```bash
test -f .env || cp .env.example .env
chmod 600 .env
```

Edit in Plesk File Manager/Terminal or a trusted SSH editor. Replace placeholders with real provider values; never copy development credentials. Existing names supported by `.env.example`/`config/` include:

```dotenv
APP_NAME="ZCMC ONLINE"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://your-real-domain.example
APP_LOCALE=en
APP_FALLBACK_LOCALE=en

DB_CONNECTION=mysql
DB_HOST=<host-from-plesk>
DB_PORT=3306
DB_DATABASE=<database-name-from-plesk>
DB_USERNAME=<database-user-from-plesk>
DB_PASSWORD=<database-password>

FILESYSTEM_DISK=local
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
CACHE_STORE=file
QUEUE_CONNECTION=sync
LOG_CHANNEL=stack
LOG_LEVEL=warning

MAIL_MAILER=smtp
MAIL_SCHEME=<smtp-scheme-from-provider>
MAIL_HOST=<smtp-host-from-provider>
MAIL_PORT=<smtp-port-from-provider>
MAIL_USERNAME=<smtp-user-from-provider>
MAIL_PASSWORD=<smtp-password-from-provider>
MAIL_FROM_ADDRESS=<verified-sender-address>
MAIL_FROM_NAME="${APP_NAME}"

STUDENT_DEFAULT_PASSWORD=<unique-long-random-temporary-password>
VITE_APP_NAME="${APP_NAME}"
```

The SMTP provider must supply the scheme, hostname, port, credentials, and verified sender address. `STUDENT_DEFAULT_PASSWORD` is the configured default used by the admin password reset flow; replace the example value with a private strong value. No Zoom `.env` keys are defined by this project. Do not enable `APP_DEBUG` publicly. The app timezone is fixed to `Asia/Manila` in `config/app.php` and is not an environment key.

### C3. Generate `APP_KEY` only for a new installation

Only when this is a genuinely new installation and `APP_KEY` is blank, run once:

```bash
php artisan key:generate
```

Keep an existing key. Encrypted Zoom settings and encrypted sessions rely on it. Changing or losing the key makes existing encrypted values unreadable. Protect and back it up securely; never commit it.

### C4. Migrate and create the initial administrator

Back up the database and review migrations before applying them:

```bash
php artisan migrate:status
php artisan migrate --force
```

Do **not** run `php artisan db:seed` in production. It creates demo data and intentionally refuses outside local/testing. Create the first admin interactively:

```bash
php artisan app:create-admin
```

Use a unique password of at least 12 characters and store it in an approved credential manager.

### C5. Public storage link and permissions

Run once for public branding files:

```bash
php artisan storage:link
```

This links `public/storage` to `storage/app/public`; it does not publish lesson videos. Videos stay on the `local` private disk under `storage/app/private`.

**Plesk interface / SSH:** Ensure the PHP-FPM/subscription system user can read code and write `storage/` and `bootstrap/cache/`. If SSH is the subscription user, typical Linux permissions are:

```bash
chmod -R u+rwX,g+rwX storage bootstrap/cache
```

If PHP-FPM uses another account, correct ownership through Plesk/provider support. Do not use `chmod 777`. Ensure `storage/app/private`, `storage/app/public`, `storage/framework/{cache,sessions,views}`, `storage/logs`, and `bootstrap/cache` exist and are writable by PHP. `.env`, `vendor`, private storage, and logs must not be web accessible.

## D. Build frontend assets

The Blade layout uses Laravel Vite entry points `resources/css/app.css` and `resources/js/app.ts`. **Plesk Terminal / SSH or CI build environment:**

```bash
npm ci --include=dev
npm run build
test -f public/build/manifest.json
```

`package-lock.json` is committed. Vite and Vue/TypeScript tooling are development dependencies, so install them during the build. `npm run build` runs `vue-tsc --noEmit` followed by Vite.

`public/build/manifest.json` and its assets are Git-ignored. Build on Plesk or build in CI/a trusted machine and deploy the complete `public/build/` directory. Node.js/npm are needed only where assets are built; PHP requests do not need Node or a running Vite server afterward. Do not run `npm run dev` in production.

## E. Production services and security

### Laravel optimization

After `.env`, dependencies, and migrations are ready, run:

```bash
php artisan optimize:clear
php artisan optimize
```

This clears stale optimized files and builds production config, event, route, and view caches. After changing `.env`, clear and rebuild caches. Do not call `env()` from application code outside configuration files.

### Session, cache, queue, scheduler

Current configuration uses database sessions, file cache, and synchronous queues. The migrations create the `sessions` table; `storage/framework` must be writable for file cache and compiled views.

Code inspection found no `ShouldQueue` jobs and no scheduled commands in `routes/console.php`. No queue worker, Supervisor process, `queue:work`, or Scheduler cron entry is currently required. Do not switch `QUEUE_CONNECTION` to `database`/`redis` unless queue tables/services and worker lifecycle are deliberately provisioned. Recheck the implementation before adding cron or workers for a future feature.

### HTTPS / SSL

**Plesk interface:** Issue/enable a certificate for the domain (for example, through the installed Let's Encrypt extension), enable HTTPS redirection if appropriate, and confirm the certificate chain. Set `APP_URL=https://...` and `SESSION_SECURE_COOKIE=true`. Verify sign-in, reset-password links, uploads, and authenticated playback over HTTPS.

### Mail

The example mailer is `log`, which does not deliver reset links to users. Configure the real SMTP provider settings in `.env`, verify the sender with that provider, and test the forgot-password flow. Protect mail credentials as database credentials.

### Zoom API

Once HTTPS is available, use **Admin → Zoom** to save the Server-to-Server OAuth account ID, client ID, and secret and run the connection test. The app encrypts them in MySQL and uses the stable Laravel `APP_KEY`; tokens and API calls remain backend-only. Confirm the Zoom Marketplace app is active and has the scopes required for meeting creation, registrants, and attendance reports. Confirm outbound HTTPS/DNS access to `zoom.us` and `api.zoom.us`. Never return credentials/tokens to browser code or expose them in logs.

### Private lesson video upload/playback

The application maximum is 1,000 MB. PHP FPM, HTTP proxy/server/CDN/firewall body limits, upload temp space, and private storage must all allow this request size. Use at least A4's limits, with `post_max_size` greater than `upload_max_filesize`. Keep enough free disk for temporary and stored copies. `memory_limit` does not need to be raised to store a file.

Keep video files under `storage/app/private` and do not symlink that directory into `public/`. The current controller streams through an authenticated route with byte-range support. `public/storage` is only for the public disk (branding), not private lessons. Preserve both uploaded-content directories across source deployments. For versioned releases, create persistent shared storage and link each release's `storage/` to it before serving; filesystem paths are relative to the Laravel root's `storage/` directory.

### Backups

Before go-live and each release, create a verified Plesk/equivalent backup of:

- MySQL, including student, learning progress, batch, registration, and attendance data.
- `storage/app/private` (videos) and `storage/app/public/branding` (logos).
- `.env` and the application key, encrypted and access restricted, plus the deployed commit reference.

Keep encrypted copies off-server, restrict access and retention, and periodically test restoration. Never store backups in public GitHub or a public web directory.

## F. Repeatable GitHub update procedure

Deploy only a reviewed production branch/commit. Keep Plesk Git in manual/no-auto-publish mode so maintenance commands run before traffic reaches updated code.

1. **Plesk interface:** Create/verify a fresh database and persistent-upload backup. Record the current commit. Confirm `.env` and upload paths will be preserved.
2. **Plesk interface:** Pull the approved GitHub branch/commit into the configured application repository. Or, from **Plesk Terminal / SSH** in the app root:

   ```bash
   git status --short
   git fetch origin
   git pull --ff-only origin <production-branch>
   ```

   Replace `<production-branch>` with the approved branch. Stop if the worktree is dirty, there is a conflict, or `.env`/persistent storage could be overwritten. Do not `reset` or `clean` production as a shortcut.
3. **Plesk Terminal / SSH:** Install committed PHP/Node dependencies and build assets:

   ```bash
   composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
   npm ci --include=dev
   npm run build
   test -f public/build/manifest.json
   ```

   If building in CI, deploy the complete build output and verify the manifest/assets are in the active public directory.
4. **Plesk Terminal / SSH:** Review migration status, then migrate during the approved maintenance window:

   ```bash
   php artisan migrate:status
   php artisan migrate --force
   ```

5. **Plesk Terminal / SSH:** Clear and rebuild caches:

   ```bash
   php artisan optimize:clear
   php artisan optimize
   ```

6. **Plesk interface:** Confirm PHP version/limits, document root, SSL, and permissions remain correct. Current sync queues need no restart and current code needs no scheduler action.
7. **Verify:** Open `/up`, sign in, check student/admin pages, review Laravel and Plesk logs, test password-reset delivery, private lesson playback, and a small upload. If Zoom is configured, run its connection test.

Routine deployment must not run `composer update`, `npm update`, `php artisan db:seed`, or an unreviewed migration rollback.

## G. Troubleshooting

### 403 or 404

- Confirm Plesk's document root points to this Laravel checkout's `public/`, not the repository root or missing directory.
- Confirm `public/index.php`, `public/.htaccess` (Apache), and `public/build/` exist.
- Check Plesk Apache/Nginx/rewrite settings, DNS, and application/server logs. Ask the provider to inspect rewrite/proxy rules if only nested routes fail.

### 500 Internal Server Error

- Keep `APP_DEBUG=false` and do not expose stack traces publicly.
- Inspect `storage/logs/laravel.log` and Plesk PHP-FPM/Apache/Nginx logs.
- Check PHP 8.4/extensions, Composer `vendor/`, `.env` readability, `APP_KEY`, Vite manifest, and write access to `storage/` and `bootstrap/cache/`.
- In Plesk Terminal/SSH, run `php artisan optimize:clear`, fix the logged cause, then `php artisan optimize`.

### Database connection errors

- Recheck Plesk's exact DB host/name/user/password/port and the user's database privileges.
- Confirm `pdo_mysql` for both the website runtime and CLI.
- After changing `.env`, run `php artisan optimize:clear`, then `php artisan migrate:status`.

### Vite manifest missing

- At the Laravel root run `npm ci --include=dev` and `npm run build`, or deploy complete CI output.
- Confirm `public/build/manifest.json` exists in the release Plesk serves. Git ignores build output; Composer does not create it.

### Storage permission errors

- Ensure the PHP-FPM/subscription account can write storage and bootstrap cache directories and read/write the private video and public branding directories.
- Do not use `chmod 777`. Check ownership, provider ACLs, or SELinux if ordinary permissions look correct.
- With versioned releases, verify persistent storage is linked into the active release.

### Video size errors (413 / validation)

- PHP limit, HTTP proxy/web server, CDN, and firewall limits are independent. Check the Plesk domain handler's `upload_max_filesize` (at least `1024M`) and larger `post_max_size` (for example `1100M`), execution/input time, and every request-body cap.
- Check free disk in PHP temp and private storage. CLI `php.ini` values do not prove PHP-FPM values; use the Plesk domain PHP panel. Do not raise `memory_limit` to fix a request-size limit.

### Private video playback failure

- Confirm the student is authenticated, the lesson is published, and its `video_path` exists in `storage/app/private/lesson-videos`.
- Check PHP read permissions, persistence, application/Plesk logs, and that the authenticated `/api/lessons/{lesson}/video` request succeeds. Byte-range playback may return HTTP 206.
- Ensure a proxy allows range responses. Never expose private videos through `public/` or `public/storage`.

### Zoom API connection errors

- Save complete Server-to-Server credentials and use Admin → Zoom's connection test. Verify account ID/client ID/secret and Marketplace app scopes.
- Check outbound HTTPS/DNS to `zoom.us` and `api.zoom.us`, system clock/certificate trust, and stable `APP_KEY`.
- Credentials are encrypted in the DB. If the key is changed/lost, stored values cannot be decrypted; follow protected key/credential recovery rather than logging secrets.

## H. Rollback and security

### Rollback

1. Enter a controlled maintenance window if needed. Record the failing commit and review logs.
2. Restore/redeploy last-known-good code and matching frontend assets. Keep production `.env` and persistent video/logo directories intact.
3. Clear and rebuild caches with the restored code: `php artisan optimize:clear`, then `php artisan optimize`.
4. Roll back code only if that code supports the current schema. Do not run `php artisan migrate:rollback` blindly. If a migration changed data or schema incompatibly, restore the matching pre-deployment database backup and associated files as a coordinated recovery.
5. Verify sign-in, progress, private playback, reports, and Zoom access before ending maintenance.

### Security checklist

- Keep `APP_DEBUG=false`, `.env` outside the web root, and its permissions restricted (for example, `600` on Linux).
- Never commit `.env`, `.env.production`, production database/SMTP passwords, `APP_KEY`, Zoom secrets/tokens, database backups, private videos, or logs. The current `.gitignore` does not ignore `.env.production`.
- Keep Plesk's document root at `public/`; never serve the repository root, `.env`, `vendor/`, `storage/`, or logs directly.
- Keep videos on the private `local` disk and preserve the authenticated streaming route. `public/storage` must not point at private lesson storage.
- Use HTTPS, strong unique admin passwords, restricted Plesk/GitHub accounts, encrypted off-server backups, and minimal file permissions. Do not log or share secrets.
- Keep `APP_KEY` stable and backed up securely. Rotate it only with a planned key-rotation process that addresses encrypted data.

## Manual Plesk work required before go-live

This repository does not configure a Plesk subscription or contain production credentials. An authorized operator must create the domain/subdomain and DNS, MySQL database/user, PHP 8.4 handler/extensions/settings, Laravel `public/` document root, GitHub authentication/path, writable persistent storage, SSL certificate, SMTP credentials, production `.env` and `APP_KEY`, and initial admin. If using Zoom, configure its Marketplace app, scopes, and admin-stored credentials. Verify provider-side Nginx/Apache/CDN upload limits. Review the release and backup before migrating any production database.

## Official references

- [Plesk Obsidian: PHP settings](https://docs.plesk.com/en-US/obsidian/administrator-guide/70742/)
- [Plesk Obsidian: hosting settings and document root](https://docs.plesk.com/en-US/obsidian/quick-start-guide/plesk-functionality-explained/managing-web-hosting.74401/)
- [Plesk Obsidian: remote Git hosting](https://docs.plesk.com/en-US/obsidian/administrator-guide/website-management/git-support/using-remote-git-hosting.75848/)
- [Laravel 13: deployment](https://laravel.com/docs/13.x/deployment)
- [Laravel 13: queues](https://laravel.com/docs/13.x/queues)
