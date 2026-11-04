# ZCMC Online

ZCMC Online is a student-first learning and progress platform built with Laravel. Students watch published lessons, save learning progress, submit realizations, and access their own batch-based Zoom information. Administrators manage learning content, students, batches, Zoom registrations and attendance, reports, and branding.

## Features

- Student registration, sign-in, password recovery, and profile password updates.
- Published lesson and category browsing with authenticated video playback, resume progress, and completion tracking.
- Student realization submissions and learning history.
- Admin student and pace-group management, categories, lessons, private video uploads, and branding.
- Batch memberships with retained transfer history, Zoom meetings and individual registrations, attendance synchronization, and student-specific Zoom access.
- Admin progress, realization, transfer, and meeting-attendance reports, including filtered Excel downloads.

Lesson videos are stored on Laravel's private local disk and streamed through an authenticated student endpoint. Public branding assets use a separate public disk. Zoom Server-to-Server OAuth credentials are entered in Admin → Zoom and encrypted in the database; OAuth tokens and API calls stay on the backend.

## Technology and requirements

Versions below reflect the project constraints and lockfiles. The current Laravel lockfile resolves Laravel Framework 13.39.0.

- PHP `^8.3` (production target: PHP 8.4.x), Composer 2.x, MySQL, and PDO MySQL.
- Node.js 20.19+ or 22.12+ and npm to build the frontend.
- Laravel 13, Vue 3, TypeScript, Vite 7, Tailwind CSS 4, and `@lucide/vue`.
- PHP extensions: Ctype, Filter, Hash, Mbstring, OpenSSL, Session, Tokenizer, PDO, PDO MySQL, and Fileinfo. Enable cURL for dependable outbound Zoom API requests. XML support is recommended for PHP tooling.

## Local development (Windows / XAMPP)

The development location used for this project is `C:\xampp8.3.36\htdocs\online-learning`. Start MySQL in XAMPP Control Panel, open PowerShell in the project directory, and install dependencies:

```powershell
composer install
npm ci
Copy-Item .env.example .env
```

Create a local MySQL database (for example, `online_learning`) and edit `.env` with its database name and credentials. Generate an app key once for a new local environment, then migrate and seed development data:

```powershell
php artisan key:generate
php artisan migrate --seed
```

The seeder is restricted to `local` and `testing`. Its sample credentials are local-only; never seed production. Run Laravel and Vite in separate PowerShell terminals:

```powershell
php artisan serve
```

```powershell
npm run dev
```

Open `http://127.0.0.1:8000`. If `.env` already exists, keep it; do not overwrite its key or settings. To test production-built assets, use `npm run build` and serve Laravel without the Vite development process. The build runs `vue-tsc --noEmit` and then Vite, producing `public/build/manifest.json`.

### Local large-video upload limits

Lesson validation allows files up to 1,000 MB. `php artisan serve` uses PHP CLI configuration. Run `php --ini` in the same terminal used for Artisan; on the XAMPP setup this is `C:\xampp8.3.36\php\php.ini`. Compatible settings are:

```ini
upload_max_filesize = 1024M
post_max_size = 1050M
max_execution_time = 1800
max_input_time = 1800
```

Restart `php artisan serve` after changing the CLI `php.ini`. `post_max_size` must exceed the file limit for multipart overhead; the web server may enforce another request-size limit. Raising `memory_limit` is not required. See [DEPLOYMENT.md](DEPLOYMENT.md) for Plesk PHP-FPM, proxy, private-storage, and upload configuration.

## Tests and useful commands

```powershell
php artisan test
npm run build
php artisan route:list
php artisan about
```

Tests use in-memory SQLite and do not modify `.env`'s MySQL database. Laravel's health endpoint is `/up`. For the first production administrator, run `php artisan app:create-admin` after deployment; it prompts for credentials without printing the password.

## Production deployment

See the step-by-step [Plesk Obsidian deployment guide](DEPLOYMENT.md) for PHP 8.4, MySQL, GitHub, secrets, migrations, frontend builds, SSL, private videos, Zoom, backups, and rollback.

## Source repository

[GitHub: jacqueturalba/online-learning-progress-system](https://github.com/jacqueturalba/online-learning-progress-system)

Configured `origin`: `git@github.com:jacqueturalba/online-learning-progress-system.git`.
