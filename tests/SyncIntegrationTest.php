<?php

use Coding1688\Files\Console\Commands\SyncCommand;
use Coding1688\Files\FileStorage;
use Coding1688\Files\Http\Resources\FileStorageAccountResource;
use Coding1688\Files\Jobs\FileSyncBatch;
use Coding1688\Files\Management\FileService;
use Coding1688\Files\Management\FileStorageAccountService;
use Coding1688\Files\Management\ManagementContext;
use Coding1688\Files\Models\File;
use Coding1688\Files\Models\FileConfiguration;
use Coding1688\Files\Models\FileStorageAccount;
use Coding1688\Files\Models\FileSyncTask;
use Coding1688\Files\StorageRegistry;
use Coding1688\Files\StorageSynchronizer;
use Coding1688\Files\UploadConfiguration;
use Illuminate\Cache\ArrayStore;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Encryption\Encrypter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SyncIntegrationTest extends TestCase
{
    private Manager $db;

    protected function setUp(): void
    {
        new Application;
        app()->instance('config', new Repository);
        $this->db = new Manager(app());
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->db->bootEloquent();
        app()->instance('db', $this->db->getDatabaseManager());
        app()->instance('db.schema', $this->db->getConnection()->getSchemaBuilder());
        app()->instance('cache', new Illuminate\Cache\Repository(new ArrayStore));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(app());
        (require dirname(__DIR__).'/database/migrations/2026_09_27_101909_create_file_tables.php')->up();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Container::setInstance(null);
    }

    public function test_initial_migration_persists_preferred_disks_and_recreates_configuration(): void
    {
        $configuration = FileConfiguration::create(['allowed_extensions' => ['jpg']]);
        self::assertNull($configuration->fresh()->preferred_disks);
        $configuration->update(['preferred_disks' => ['archive', 'public']]);
        self::assertSame(['archive', 'public'], $configuration->fresh()->preferred_disks);

        $migration = require dirname(__DIR__).'/database/migrations/2026_09_27_101909_create_file_tables.php';
        $migration->down();
        self::assertFalse($this->db->getConnection()->getSchemaBuilder()->hasTable('file_configurations'));
        config(['files.tables.configuration' => 'custom_file_settings']);
        $migration->up();
        $configuration = FileConfiguration::create(['allowed_extensions' => [], 'preferred_disks' => ['archive']]);
        self::assertSame(['archive'], $configuration->fresh()->preferred_disks);
        $migration->down();
        self::assertFalse($this->db->getConnection()->getSchemaBuilder()->hasTable('custom_file_settings'));
    }

    public static function copyCases(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('copyCases')]
    public function test_copy_runs_outside_transaction_and_checkpoints_once(bool $corrupt): void
    {
        $file = File::create(['disk' => 'source', 'path' => 'a.txt', 'original_name' => 'a.txt', 'mime_type' => 'text/plain', 'size' => 5, 'checksum' => hash('sha256', 'hello'), 'visibility' => 'public']);
        $task = FileSyncTask::create(['source_disk' => 'source', 'target_disk' => 'target', 'max_file_id' => $file->id, 'total' => 1]);
        $task->refresh();
        $source = $this->createStub(FilesystemAdapter::class);
        $target = $this->createMock(FilesystemAdapter::class);
        $stream = function () {
            $s = fopen('php://temp', 'r+');
            fwrite($s, 'hello');
            rewind($s);

            return $s;
        };
        $source->method('readStream')->willReturnCallback($stream);
        $target->method('exists')->willReturn(false);
        $target->expects(self::once())->method('writeStream')->willReturnCallback(function () {
            self::assertSame(0, $this->db->getConnection()->transactionLevel());

            return true;
        });
        $target->method('readStream')->willReturnCallback($corrupt ? function () {
            $s = fopen('php://temp', 'r+');
            fwrite($s, 'wrong');
            rewind($s);

            return $s;
        } : $stream);
        if ($corrupt) {
            $target->expects(self::once())->method('delete')->with('a.txt')->willReturn(true);
        }
        $registry = $this->createStub(StorageRegistry::class);
        $registry->method('providers')->willReturn(['source' => ['enabled' => true, 'visibilities' => ['public']], 'target' => ['enabled' => true, 'visibilities' => ['public']]]);
        $registry->method('disk')->willReturnCallback(fn ($disk) => $disk === 'source' ? $source : $target);
        $sync = new StorageSynchronizer($registry);
        self::assertFalse($sync->process($task->id, false));
        self::assertTrue($sync->process($task->id, false));
        self::assertTrue($sync->process($task->id, false));
        $task->refresh();
        self::assertSame($corrupt ? 'failed' : 'completed', $task->status);
        self::assertSame($corrupt ? 0 : 1, (int) $task->copied);
        self::assertSame($corrupt ? 0 : 1, $file->replicas()->count());
        (require dirname(__DIR__).'/database/migrations/2026_09_27_101909_create_file_tables.php')->down();
        self::assertFalse($this->db->getConnection()->getSchemaBuilder()->hasTable('files'));
    }

    public function test_default_command_dispatches_first_job_using_host_queue(): void
    {
        config(['queue.default' => 'host', 'queue.connections.host.driver' => 'database']);
        File::create(['disk' => 'source', 'path' => 'a.txt', 'original_name' => 'a.txt', 'mime_type' => 'text/plain', 'size' => 5, 'checksum' => hash('sha256', 'hello'), 'visibility' => 'public']);
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects(self::once())->method('dispatch')->with(self::callback(fn ($job) => $job instanceof FileSyncBatch && $job->connection === 'host' && $job->queue === 'file-sync'));
        app()->instance(Dispatcher::class, $dispatcher);
        $registry = $this->createStub(StorageRegistry::class);
        $registry->method('providers')->willReturn(['source' => ['enabled' => true], 'target' => ['enabled' => true]]);
        $command = $this->getMockBuilder(SyncCommand::class)->onlyMethods(['argument', 'option', 'info'])->getMock();
        $command->expects(self::exactly(2))->method('argument')->willReturnCallback(fn ($key) => $key);
        $command->method('option')->willReturn(false);
        self::assertSame(0, $command->handle($registry, new StorageSynchronizer($registry)));
        self::assertSame('pending', FileSyncTask::first()->status);
    }

    public function test_file_management_works_without_host_uploader_relation(): void
    {
        $file = File::create(['disk' => 'source', 'path' => 'a.txt', 'original_name' => 'a.txt', 'mime_type' => 'text/plain', 'size' => 5, 'checksum' => hash('sha256', 'hello'), 'visibility' => 'public']);
        $registry = $this->createStub(StorageRegistry::class);
        $service = new FileService(new FileStorage($registry), new UploadConfiguration($registry));
        self::assertSame(1, $service->list([])->total());
        self::assertSame($file->id, $service->detail($file->id)->id);
    }

    public function test_storage_resource_never_returns_credentials(): void
    {
        config(['app.locale' => 'ja']);
        app()->instance('encrypter', new Encrypter(random_bytes(32), 'AES-256-CBC'));
        $account = FileStorageAccount::create([
            'disk' => 'archive', 'name_i18n' => ['en' => 'Archive', 'ja' => '保管庫'], 'provider' => 'aws', 'visibility' => 'private', 'enabled' => false,
            'configuration' => ['bucket' => 'test'], 'credentials' => ['key' => 'PRIVATE_KEY', 'secret' => 'PRIVATE_SECRET'],
        ]);
        $registry = $this->createStub(StorageRegistry::class);
        $registry->method('providers')->willReturn([]);
        app()->instance(StorageRegistry::class, $registry);
        $data = (new FileStorageAccountResource($account))->toArray(Request::create('/'));
        self::assertSame('保管庫', $data['name']);
        self::assertTrue($data['has_secret_key']);
        self::assertStringNotContainsString('PRIVATE_', json_encode($data));
        self::assertArrayNotHasKey('credentials', $data);
    }

    public function test_failed_upload_audit_rolls_back_rows_and_cleans_written_bytes(): void
    {
        $disk = $this->createMock(FilesystemAdapter::class);
        $disk->expects(self::once())->method('putFileAs')->with(self::anything(), self::anything(), self::anything(), ['visibility' => 'private'])->willReturn('uploads/test.txt');
        $disk->expects(self::once())->method('delete')->with('uploads/test.txt')->willReturn(true);
        $registry = $this->createStub(StorageRegistry::class);
        $registry->method('disk')->willReturn($disk);
        app()->instance(ManagementContext::class, new class extends ManagementContext
        {
            public function actorId(): int|string|null
            {
                return null;
            }

            public function audit(string $action, ?string $subject = null, array $changes = []): void
            {
                throw new RuntimeException('audit failure');
            }
        });
        $service = new FileService(new FileStorage($registry), new UploadConfiguration($registry));
        try {
            $service->store(['files' => [UploadedFile::fake()->createWithContent('test.txt', 'hello')], 'disk' => 'private', 'type' => 'general', 'visibility' => 'private']);
            self::fail('Expected audit failure');
        } catch (RuntimeException $exception) {
            self::assertSame('audit failure', $exception->getMessage());
        }
        self::assertSame(0, File::count());
    }

    public function test_storage_configuration_service_persists_and_audits_without_host_models(): void
    {
        app()->instance('encrypter', new Encrypter(random_bytes(32), 'AES-256-CBC'));
        $context = new class extends ManagementContext
        {
            public array $actions = [];

            public function actorId(): int|string|null
            {
                return null;
            }

            public function audit(string $action, ?string $subject = null, array $changes = []): void
            {
                $this->actions[] = $action;
            }
        };
        app()->instance(ManagementContext::class, $context);
        $registry = $this->createStub(StorageRegistry::class);
        $uploads = $this->createStub(UploadConfiguration::class);
        $uploads->method('defaults')->willReturn(['providers' => [], 'allowed_extensions' => ['jpg'], 'limits' => ['hard_max_upload_mb' => 32]]);
        $uploads->method('enabledDisks')->willReturn(['public']);
        $uploads->method('defaultDisk')->willReturn('public');
        $uploads->method('maxSizeKb')->willReturn(2048);
        $uploads->method('allowedExtensions')->willReturn(['jpg']);
        $service = new FileStorageAccountService($uploads, $registry, new StorageSynchronizer($registry));
        $account = $service->create(['disk' => 'archive', 'name_i18n' => ['en' => 'Archive'], 'provider' => 'aws', 'visibility' => 'private', 'enabled' => false, 'configuration' => ['bucket' => 'archive'], 'access_key' => 'KEY', 'secret_key' => 'SECRET']);
        self::assertSame('SECRET', $account->fresh()->credentials['secret']);
        self::assertStringNotContainsString('SECRET', $this->db->getConnection()->table('file_storages')->value('credentials'));
        $service->saveSettings(['default_disk' => 'public', 'max_upload_mb' => 2, 'allowed_extensions' => ['jpg']]);
        self::assertSame(2, FileConfiguration::find(1)->max_upload_mb);
        $service->delete($account->id);
        self::assertNull($account->fresh());
        self::assertSame(['file.storage.create', 'file.storage.saveSettings', 'file.storage.delete'], $context->actions);
    }
}
