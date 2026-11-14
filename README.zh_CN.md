# pl-files

[English](README.md) | [简体中文](README.zh_CN.md) | [繁體中文](README.zh_TW.md) | [日本語](README.ja.md) | [한국어](README.ko.md)

[Moonsea](https://moonsea.dev) · [GitHub](https://github.com/coding1688/pl-files)

## 0.5.0 版本基线

三个 Moonsea 包统一从 `v0.5.0` 重新开始。原标签和 GitHub Releases 已清理，提交历史保留。请显式更新 Composer 版本约束和锁文件。这是新的版本基线，不是从已移除版本自动升级。

files 初始迁移现在直接创建可空 JSON 字段 `preferred_disks`，独立补列迁移已移除。现有数据库不会重跑初始迁移。使用此基线前，请检查配置的数据表：缺少该列时通过宿主迁移补齐；已有该列则保留数据。不要对需要保留数据的数据库执行 `migrate:fresh`。历史迁移记录可能仍引用已移除文件，切换前请检查回滚方案。

## 功能

适用于 Laravel 的文件上传、引用追踪、可配置存储账号与校验复制。

- 底层存储 API 返回统一的文件元数据。
- 提供文件、引用、副本、存储账号、上传设置和同步任务的 Eloquent 模型。
- 追踪文件引用，阻止删除仍被引用的文件记录。
- 通过校验和验证副本，保存进度检查点，并选择用于访问文件的磁盘。
- 可选的 JSON 管理 API，涵盖文件、存储账号、上传设置和同步任务。
- 提供英语、简体中文、繁体中文、日文和韩文消息。

不包含 Vue 组件或后台主题。认证、授权、租户隔离和业务上传规则由宿主应用负责。

## 环境要求

声明支持 PHP `^8.2` 和 Laravel 组件 `^11 | ^12 | ^13`；所选 Laravel 版本可能要求更高版本的 PHP。本文描述当前源码，请选择匹配的发布版本。

本地存储使用 Laravel 文件系统磁盘。S3 兼容驱动还需要 `league/flysystem-aws-s3-v3`。数据库中的存储凭据使用 Laravel 加密，宿主必须配置并妥善保管 `APP_KEY`。

## 安装

```bash
composer require coding1688/pl-files
php artisan vendor:publish --tag=files-config
php artisan migrate
```

如果 Composer 当前配置的软件源找不到此包，请先添加源码仓库再安装：

```bash
composer config repositories.pl-files vcs https://github.com/coding1688/pl-files.git
```

Laravel 自动发现 `FilesServiceProvider`。公开本地文件需配置 `public` 磁盘并运行 `php artisan storage:link`。基础本地安装不需要云账号或队列进程。

包迁移自动加载。若使用 `--tag=files-migrations` 发布迁移，应设置 `files.load_migrations=false`，由宿主管理这些迁移，不要同时加载两份。首次迁移前配置好表名。

## 配置

使用 `config/files.php` 和 `config('files.…')`。

| 设置 | 用途 |
| --- | --- |
| `management` | 可选路由、前缀、中间件、guard、Gate 和名称语言 |
| `models` / `tables` | 模型子类和数据库表名 |
| `uploads` | 允许的磁盘、上传限制、扩展名和提供者默认值 |
| `sync.connection` / `sync.queue` | 队列连接覆盖和队列名称 |
| `preferred_disks` | 优先使用的已验证副本磁盘 |
| `reference_models` | 存储管理引用检查使用的宿主模型 |
| `load_migrations` | 自动加载包迁移 |
| `user_table` | 创建者外键使用的可选用户表 |

完整默认值见配置文件。优先级为 **包默认值 → 旧配置 `pl.files` → `files`**。映射合并，列表整体替换，包括空列表。旧值在服务提供者注册时导入；新的运行时覆盖应使用 `files.*`。数据库中的上传设置可覆盖静态默认值。

业务表外键由宿主迁移管理。`owner_tables` 仅用于兼容旧版包创建的约束回滚。

## 底层上传

在已授权的控制器操作中：

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

`store()` 写入文件并返回 `StoredFile`，不会创建数据库记录，也不会完成业务授权或校验。磁盘、目录和可见性应由服务端选择。按需保存返回的元数据；数据库写入失败时清理未使用的文件。

如需协调上传和文件记录管理，可将已校验输入传给 `Coding1688\Files\Management\FileService`，或启用下方管理 API。

## 可选管理 API

默认关闭。启用时，将以下配置合并到 `config/files.php`：

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

在宿主服务提供者中定义 Gate。以下示例委托给应用需要实现的权限：

```php
use Illuminate\Support\Facades\Gate;

Gate::define('manage-files', fn ($user) => $user->can('manage-storage'));
```

所有内置路由都要求已认证用户且通过此 Gate，即使修改中间件列表也一样。默认 `web` 组保留 CSRF 保护。令牌 API 应配置宿主的认证 API 中间件和 guard。修改路由配置后重建路由缓存。

以下路径相对于 `/files-management` 或自定义前缀：

| 方法 | 路径 | 操作 |
| --- | --- | --- |
| GET | `item/list` | 分页文件列表 |
| GET | `item/options` | 上传及存储选项 |
| GET | `item/detail/{id}` | 文件元数据、引用和副本 |
| POST | `item/upload` | 上传文件并创建元数据 |
| POST | `item/delete/{id}` | 删除无引用文件及其副本 |
| GET | `storage/list` | 不含凭据内容的存储账号 |
| GET | `storage/settings` | 当前有效上传设置 |
| POST | `storage/save-settings` | 保存上传设置 |
| POST | `storage/create` | 创建存储账号 |
| POST | `storage/update/{id}` | 更新存储账号 |
| POST | `storage/delete/{id}` | 删除未使用的存储账号 |
| GET | `storage/tasks` | 最近同步任务 |
| POST | `storage/sync` | 开始复制 |
| POST | `storage/retry/{id}` | 重试失败任务 |

发送 `Accept: application/json`。上传使用 multipart 的 `files[]`、`disk`、`type` 和 `visibility`。响应使用 `data`；分页文件响应还包含 `total`、`last_page`。创建和上传返回 201，同步和重试返回 202。校验及认证错误遵循宿主 Laravel 异常处理。

路由名称以 `files.management.` 开头。存储资源仅返回凭据是否存在的标志，不返回凭据内容。这是**全局管理 API**，并非按租户隔离的文件浏览器。

## 接入现有后台

保留自己的路由和控制器，关闭包内置管理路由，复用以下组件：

```text
Management/FileService
Management/FileStorageAccountService
Management/ManagementContext
Http/Requests/FileRequest
Http/Requests/FileStorageAccountRequest
Http/Resources/FileResource
Http/Resources/FileStorageAccountResource
```

服务接收已校验的数组。直接调用服务属于可信应用操作，调用方负责授权和租户过滤。`FileService::store()` 可用于已授权的业务上传流程。

绑定 `ManagementContext` 子类可提供操作者 ID、权限检查和审计持久化。默认上下文使用配置的认证 guard，并发出包含操作、对象、变更和操作者 ID 的 `files.management.audit` 事件。审计元数据不要包含凭据。菜单、安全密码检查和前端界面由宿主实现。

## 引用和删除

业务模型使用 `Concerns\HasFileReferences`，实现 `fileReferenceFields()`；基于 ID 的字段还需实现 `fileReferenceIdFields()`。仅配置 `reference_models` 不会安装模型观察者。业务列及其外键由宿主管理。

仍被引用的 File 记录无法通过普通模型删除操作删除。管理服务还会移除原文件和副本。数据库事务无法撤销远程对象删除：某个磁盘失败时，先前的对象可能已经删除。需要可靠恢复的应用应实现删除任务流程。

## 复制

```bash
php artisan files:sync public archive --now
php artisan files:sync public archive
php artisan queue:work --queue=file-sync
```

先配置目标提供者或磁盘。只复制已登记的文件。连接默认使用宿主队列连接，可通过 `FILES_SYNC_CONNECTION` 和 `FILES_SYNC_QUEUE` 覆盖，并相应调整 worker 的连接和队列。同步驱动在当前进程内执行。

复制会校验源和目标的校验和、记录进度，并避免重复累计已完成检查点。所有 worker 必须共享支持原子锁的缓存。任务锁在 600 秒后过期，存储请求和 worker 超时必须小于该期限。队列重试间隔必须超过任务的 180 秒超时。

## 模型和数据库连接

覆盖模型必须继承对应的包模型。引用更新要求所有者、文件、引用模型位于同一连接；复制要求任务、文件、副本模型位于同一连接；存储管理参与模型同样需要使用同一连接。跨连接操作会被拒绝。自定义连接的迁移需由宿主显式管理。

支持可选的 `uploader()` 关系，但不强制要求。本包不会导入宿主的管理员模型。

## 开发和升级

```bash
composer install
composer test
```

集成测试需要 `pdo_sqlite`，使用隔离的内存数据库。CI 定义了 PHP/Laravel 兼容性任务，请查看目标版本的 CI 结果。

升级前检查配置优先级、模型和表名覆盖、旧外键以及队列设置。在数据库副本上测试迁移和回滚。未迁移凭据就修改 `APP_KEY` 可能导致加密的存储凭据无法读取。

## 许可证

MIT。参见 [LICENSE](LICENSE)。

Copyright © 2026 [coding1688 · Moonsea](https://moonsea.dev).
