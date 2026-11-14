<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $files = (string) config('files.tables.files', 'files');
        $references = (string) config('files.tables.references', 'file_references');
        $replicas = (string) config('files.tables.replicas', 'file_replicas');
        $storages = (string) config('files.tables.storage_accounts', 'file_storages');
        $configuration = (string) config('files.tables.configuration', 'file_configurations');
        $syncTasks = (string) config('files.tables.sync_tasks', 'file_sync_tasks');
        $userTable = config('files.user_table');
        $userTable = is_string($userTable) && $userTable !== '' && Schema::hasTable($userTable) ? $userTable : null;

        Schema::create($files, function (Blueprint $table) use ($userTable): void {
            $table->id();
            $table->string('disk', 32)->index();
            $table->string('type', 32)->default('general')->index();
            $table->string('path', 512);
            $table->string('original_name', 256);
            $table->string('extension', 16)->nullable();
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size');
            $table->string('visibility', 16)->default('public');
            $table->char('checksum', 64)->index();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->json('metadata')->nullable();
            if ($userTable !== null) {
                $table->foreignId('created_by')->nullable()->constrained($userTable)->nullOnDelete();
            } else {
                $table->unsignedBigInteger('created_by')->nullable();
            }
            $table->timestamps();

            $table->unique(['disk', 'path']);
        });

        Schema::create($references, function (Blueprint $table) use ($files): void {
            $table->id();
            $table->foreignId('file_id')->constrained($files)->restrictOnDelete();
            $table->string('reference_type', 128);
            $table->unsignedBigInteger('reference_id');
            $table->string('field', 128);
            $table->timestamps();
            $table->index(['reference_type', 'reference_id']);
            $table->unique(['file_id', 'reference_type', 'reference_id', 'field'], 'file_reference_unique');
        });

        Schema::create($configuration, function (Blueprint $table): void {
            $table->id();
            $table->string('default_disk', 32)->nullable();
            $table->unsignedInteger('max_upload_mb')->default(32);
            $table->json('allowed_extensions');
            $table->json('preferred_disks')->nullable();
            $table->timestamps();
        });

        Schema::create($storages, function (Blueprint $table): void {
            $table->id();
            $table->string('disk', 32)->unique();
            $table->json('name_i18n');
            $table->string('provider', 32);
            $table->string('visibility', 16)->default('private');
            $table->boolean('enabled')->default(false);
            $table->json('configuration');
            $table->text('credentials')->nullable();
            $table->timestamps();
        });

        Schema::create($replicas, function (Blueprint $table) use ($files): void {
            $table->id();
            $table->foreignId('file_id')->constrained($files)->cascadeOnDelete();
            $table->string('disk', 32);
            $table->string('path', 512);
            $table->char('checksum', 64);
            $table->timestamps();
            $table->unique(['file_id', 'disk']);
            $table->unique(['disk', 'path']);
        });

        Schema::create($syncTasks, function (Blueprint $table) use ($userTable): void {
            $table->id();
            $table->string('source_disk', 32);
            $table->string('target_disk', 32);
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedBigInteger('last_file_id')->default(0);
            $table->unsignedBigInteger('max_file_id')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('copied')->default(0);
            $table->unsignedBigInteger('skipped')->default(0);
            $table->unsignedBigInteger('failed')->default(0);
            $table->json('errors')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            if ($userTable !== null) {
                $table->foreignId('created_by')->nullable()->constrained($userTable)->nullOnDelete();
            } else {
                $table->unsignedBigInteger('created_by')->nullable();
            }
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $files = (string) config('files.tables.files', 'files');

        Schema::dropIfExists((string) config('files.tables.sync_tasks', 'file_sync_tasks'));
        Schema::dropIfExists((string) config('files.tables.replicas', 'file_replicas'));
        Schema::dropIfExists((string) config('files.tables.storage_accounts', 'file_storages'));
        Schema::dropIfExists((string) config('files.tables.configuration', 'file_configurations'));
        foreach ((array) config('files.owner_tables', []) as $owner) {
            if (is_string($owner) && $owner !== '' && Schema::hasTable($owner)) {
                $name = $owner.'_cover_file_id_foreign';
                if (collect(Schema::getForeignKeys($owner))->contains(fn (array $key): bool => $key['name'] === $name)) {
                    Schema::table($owner, fn (Blueprint $table) => $table->dropForeign($name));
                }
            }
        }
        Schema::dropIfExists((string) config('files.tables.references', 'file_references'));
        Schema::dropIfExists($files);
    }
};
