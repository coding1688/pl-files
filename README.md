# pl-files

[English](README.md) | [简体中文](README.zh_CN.md) | [繁體中文](README.zh_TW.md) | [日本語](README.ja.md) | [한국어](README.ko.md)

File uploads, reference tracking, configurable storage accounts and verified replication for Laravel.

[Moonsea](https://moonsea.dev) · [Source](https://github.com/coding1688/pl-files) · [Issues](https://github.com/coding1688/pl-files/issues) · [Getting started](GETTING_STARTED.md)

## Version 0.5.0 baseline

The three Moonsea packages restart at `v0.5.0`. Earlier tags and GitHub Releases have been removed; commit history remains. Update Composer version constraints and lock files explicitly. This is a new version baseline, not an automatic upgrade from the removed releases.

The initial files migration now creates nullable JSON `preferred_disks`; the separate add-column migration has been removed. Existing databases do not rerun the initial migration. Before using this baseline, check the configured configuration table: if the column is missing, add it through a host migration; if present, keep its data. Do not run `migrate:fresh` on a database containing data you need. Historical migration records may refer to the removed file, so review rollback plans before switching versions.

## What is included

- A low-level storage API returning normalized file metadata.
- Eloquent models for files, references, replicas, storage accounts, upload settings and sync tasks.
- File-reference tracking and protection against deleting referenced file records.
- Checksum-verified replication, progress checkpoints and selectable serving disks.
- Optional JSON management APIs for files, storage accounts, upload settings and sync tasks.
- English, Simplified Chinese, Traditional Chinese, Japanese and Korean message catalogs.

No Vue components or admin theme are included. Authentication, authorization, tenant scoping and business-specific upload rules belong to the host application.

## Requirements

The package declares PHP `^8.2` and Laravel components `^11 | ^12 | ^13`; the selected Laravel version may require a newer PHP version. Use a release compatible with your application. This README describes the current source, which may contain changes not yet published in a release.

Local storage uses Laravel filesystem disks. S3-compatible drivers additionally require `league/flysystem-aws-s3-v3`. Database-backed storage credentials use Laravel encryption, so the host must configure and protect `APP_KEY`.

## Installation

```bash
composer require coding1688/pl-files
php artisan vendor:publish --tag=files-config
php artisan migrate
```

If Composer cannot find the package through your configured registry, add the source repository before installing:

```bash
composer config repositories.pl-files vcs https://github.com/coding1688/pl-files.git
```

Laravel discovers `FilesServiceProvider` automatically. For public local files, configure the `public` disk and run `php artisan storage:link`. A basic local installation does not need a cloud account or queue worker.

Package migrations load automatically. If you publish them with `--tag=files-migrations`, set `files.load_migrations=false` and maintain those migrations in the host. Do not load both copies. Configure table names before the first migration.

## Configuration

Use `config/files.php` and `config('files.…')`.

| Setting | Purpose |
| --- | --- |
| `management` | Optional routes, prefix, middleware, guard, Gate and supported name locales |
| `models` / `tables` | Model subclasses and database table names |
| `uploads` | Allowed disks, upload limits, extensions and provider defaults |
| `sync.connection` / `sync.queue` | Queue connection override and queue name |
| `preferred_disks` | Preferred disks for serving verified replicas |
| `reference_models` | Host consumers used by storage-management reference checks |
| `load_migrations` | Automatic package migration loading |
| `user_table` | Optional user table for creator foreign keys |

The configuration file documents the complete set of defaults. Precedence is **package defaults → legacy `pl.files` → `files`**. Maps merge; lists replace, including empty lists. Legacy values are imported at provider registration; new runtime overrides must use `files.*`. Database upload settings can override static upload defaults.

Business-table foreign keys are owned by host migrations. `owner_tables` exists only for rollback compatibility with older package-created constraints.

## Low-level uploads

Inside an authorized controller action:

```php
use Coding1688\Files\FileStorage;

$request->validate(['file' => ['required', 'file', 'mimes:jpg,png', 'max:2048']]);

$stored = app(FileStorage::class)->store(
    $request->file('file'),
    'public',
    'uploads/images',
    'public',
);

$attributes = $stored->toArray();
```

`store()` writes bytes and returns a `StoredFile`; it does not create a database row or authorize/validate your business operation. Choose disk, directory and visibility on the server. Persist the returned metadata when needed, and clean up unused bytes if your database write fails.

For coordinated uploads and file-record management, use `Coding1688\Files\Management\FileService` with validated inputs or enable the management API below.

## Optional management API

Disabled by default. To enable it, merge these settings into `config/files.php`:

```php
'management' => [
    'enabled' => true,
    'prefix' => 'files-management',
    'middleware' => ['web', 'auth'],
    'guard' => null,
    'gate' => 'manage-files',
    'locales' => ['en', 'zh_CN', 'zh_TW', 'ja', 'ko'],
],
```

Define the Gate in a host service provider. The following delegates to a permission your application must implement:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('manage-files', fn ($user) => $user->can('manage-storage'));
```

Every bundled route requires both an authenticated user and this Gate, even when you change the middleware list. The default `web` group retains CSRF protection. For token-based APIs, configure the host's authenticated API middleware and guard. Rebuild route caches after changing route configuration.

All paths below are relative to `/files-management` or your configured prefix:

| Method | Path | Operation |
| --- | --- | --- |
| GET | `item/list` | Paginated file list |
| GET | `item/options` | Upload and storage options |
| GET | `item/detail/{id}` | File metadata, references and replicas |
| POST | `item/upload` | Upload files and create metadata |
| POST | `item/delete/{id}` | Delete an unreferenced file and its copies |
| GET | `storage/list` | Storage accounts without credential values |
| GET | `storage/settings` | Effective upload settings |
| POST | `storage/save-settings` | Save upload settings |
| POST | `storage/create` | Create a storage account |
| POST | `storage/update/{id}` | Update a storage account |
| POST | `storage/delete/{id}` | Delete an unused storage account |
| GET | `storage/tasks` | Recent sync tasks |
| POST | `storage/sync` | Start replication |
| POST | `storage/retry/{id}` | Retry a failed task |

Send `Accept: application/json`. Uploads use multipart `files[]`, `disk`, `type` and `visibility`. Responses use `data`; paginated file responses also include `total` and `last_page`. Creates/uploads return 201; sync/retry return 202. Validation and authentication errors follow the host Laravel exception handling.

Route names start with `files.management.`. Storage resources expose only credential-presence flags, never credential values. This is a **global administration API**, not a tenant-isolated file browser.

## Integrating an existing admin application

Keep your own routes and controllers, and leave bundled management routes disabled. Reuse:

```text
Management/FileService
Management/FileStorageAccountService
Management/ManagementContext
Http/Requests/FileRequest
Http/Requests/FileStorageAccountRequest
Http/Resources/FileResource
Http/Resources/FileStorageAccountResource
```

Services accept validated arrays. Direct service calls are trusted application operations; the caller owns authorization and tenant filtering. `FileService::store()` supports already-authorized business upload flows.

Bind a subclass of `ManagementContext` to supply actor IDs, permission checks and audit persistence. The default context uses the configured auth guard and emits `files.management.audit` with action, subject, changes and actor ID. Keep credentials out of audit metadata. Menu definitions, security-password checks and frontend UI remain in the host.

## References and deletion

Opt a business model into `Concerns\HasFileReferences`, implementing `fileReferenceFields()` and, for ID-backed fields, `fileReferenceIdFields()`. `reference_models` alone does not install model observers. The host owns the referenced business columns and their foreign keys.

A referenced File row cannot be deleted through normal model deletion. The management service additionally removes original and replica objects. A database transaction cannot undo a remote object deletion: if one disk fails, previously deleted objects may already be gone. Applications requiring durable recovery should implement a deletion-job workflow.

## Replication

```bash
php artisan files:sync public archive --now
php artisan files:sync public archive
php artisan queue:work --queue=file-sync
```

Configure the target provider/disk first. Only registered files are replicated. The connection defaults to the host queue connection; use `FILES_SYNC_CONNECTION` and `FILES_SYNC_QUEUE` to override it. Adjust the worker's connection/queue accordingly. A synchronous driver runs inline.

Replication checks source/destination checksums, records progress and avoids recounting completed checkpoints. All workers must share an atomic-lock-capable cache. Task locks expire after 600 seconds; storage request and worker timeouts must fit within that lease. Queue retry timing must exceed the 180-second job timeout.

## Models and database connections

Overrides must extend the corresponding package model. Reference updates require owner/file/reference models on one connection. Replication requires task/file/replica models on one connection; storage administration coordinates its participating models on one connection as well. Cross-connection operations are rejected. Custom-connection migrations must be managed explicitly by the host.

Optional `uploader()` relations are supported but not required. No host administrator model is imported by this package.

## Development and upgrades

```bash
composer install
composer test
```

Integration tests require `pdo_sqlite` and use an isolated in-memory database. CI defines PHP/Laravel compatibility jobs; inspect the CI results for the release you intend to use.

Before upgrading, review configuration precedence, model/table overrides, legacy foreign keys and queue settings. Test migrations and rollback on a database copy. Changing `APP_KEY` without a credential migration can make encrypted storage credentials unreadable.

## License

MIT. See [LICENSE](LICENSE).

Copyright © 2026 [coding1688 · Moonsea](https://moonsea.dev).
