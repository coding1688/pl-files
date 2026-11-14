# pl-files

[English](README.md) | [简体中文](README.zh_CN.md) | [繁體中文](README.zh_TW.md) | [日本語](README.ja.md) | [한국어](README.ko.md)

[Moonsea](https://moonsea.dev) · [GitHub](https://github.com/coding1688/pl-files)

## 0.5.0 版本基準

三個 Moonsea 套件統一從 `v0.5.0` 重新開始。原標籤及 GitHub Releases 已清理，提交歷史保留。請明確更新 Composer 版本限制及鎖定檔。這是新的版本基準，不是從已移除版本自動升級。

files 初始遷移現在直接建立可為空的 JSON 欄位 `preferred_disks`，獨立新增欄位遷移已移除。現有資料庫不會重新執行初始遷移。使用此基準前，請檢查設定的資料表：缺少該欄位時透過宿主遷移補齊；已有欄位則保留資料。不要對需要保留資料的資料庫執行 `migrate:fresh`。歷史遷移記錄可能仍引用已移除檔案，切換前請檢查回復方案。

## 功能

適用於 Laravel 的檔案上傳、引用追蹤、可設定儲存帳號與驗證複寫。

- 底層儲存 API 回傳統一的檔案中繼資料。
- 提供檔案、引用、副本、儲存帳號、上傳設定及同步任務的 Eloquent 模型。
- 追蹤檔案引用，防止刪除仍被引用的檔案記錄。
- 以校驗和驗證副本、儲存進度檢查點，並選擇提供檔案存取的磁碟。
- 可選的 JSON 管理 API，涵蓋檔案、儲存帳號、上傳設定及同步任務。
- 提供英文、簡體中文、繁體中文、日文及韓文訊息。

不含 Vue 元件或後台佈景主題。身分驗證、授權、租戶隔離及業務上傳規則由宿主應用程式負責。

## 環境需求

宣告支援 PHP `^8.2` 及 Laravel 元件 `^11 | ^12 | ^13`；選用的 Laravel 版本可能要求更新的 PHP。本文描述目前原始碼，請選擇相符的發行版本。

本機儲存使用 Laravel 檔案系統磁碟。S3 相容驅動另需 `league/flysystem-aws-s3-v3`。資料庫中的儲存憑證使用 Laravel 加密，宿主必須設定並妥善保管 `APP_KEY`。

## 安裝

```bash
composer require coding1688/pl-files
php artisan vendor:publish --tag=files-config
php artisan migrate
```

若 Composer 設定的套件來源找不到此套件，請先加入原始碼儲存庫再安裝：

```bash
composer config repositories.pl-files vcs https://github.com/coding1688/pl-files.git
```

Laravel 自動探索 `FilesServiceProvider`。公開本機檔案需設定 `public` 磁碟並執行 `php artisan storage:link`。基本本機安裝不需要雲端帳號或佇列處理程序。

套件遷移會自動載入。若以 `--tag=files-migrations` 發佈遷移，請設定 `files.load_migrations=false` 並由宿主管理，避免同時載入兩份。首次遷移前先設定資料表名稱。

## 設定

使用 `config/files.php` 和 `config('files.…')`。

| 設定 | 用途 |
| --- | --- |
| `management` | 可選路由、前綴、中介軟體、guard、Gate 及名稱語言 |
| `models` / `tables` | 模型子類別及資料表名稱 |
| `uploads` | 允許的磁碟、上傳限制、副檔名及提供者預設值 |
| `sync.connection` / `sync.queue` | 佇列連線覆寫及佇列名稱 |
| `preferred_disks` | 優先提供已驗證副本的磁碟 |
| `reference_models` | 儲存管理引用檢查使用的宿主模型 |
| `load_migrations` | 自動載入套件遷移 |
| `user_table` | 建立者外鍵使用的可選使用者資料表 |

完整預設值請見設定檔。優先順序為 **套件預設值 → 舊設定 `pl.files` → `files`**。對應表合併，清單整體取代，包含空清單。舊值在服務提供者註冊時匯入；新的執行階段覆寫請使用 `files.*`。資料庫上傳設定可覆寫靜態預設值。

業務資料表外鍵由宿主遷移管理。`owner_tables` 僅供舊版套件建立之約束的回復相容性使用。

## 底層上傳

在已授權的控制器操作中：

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

`store()` 寫入檔案並回傳 `StoredFile`，不會建立資料庫記錄，也不會執行業務授權或驗證。磁碟、目錄與可見性應由伺服器端決定。依需求儲存回傳的中繼資料；資料庫寫入失敗時清理未使用的檔案。

需要協調上傳與檔案記錄管理時，可將已驗證輸入傳給 `Coding1688\Files\Management\FileService`，或啟用下方管理 API。

## 可選管理 API

預設停用。啟用時，將以下設定合併至 `config/files.php`：

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

在宿主服務提供者中定義 Gate。以下範例委派給應用程式必須實作的權限：

```php
use Illuminate\Support\Facades\Gate;

Gate::define('manage-files', fn ($user) => $user->can('manage-storage'));
```

所有內建路由都要求已驗證使用者並通過此 Gate，即使變更中介軟體清單也一樣。預設 `web` 群組保留 CSRF 保護。權杖 API 請設定宿主的 API 驗證中介軟體及 guard。變更路由設定後重建路由快取。

以下路徑相對於 `/files-management` 或自訂前綴：

| 方法 | 路徑 | 操作 |
| --- | --- | --- |
| GET | `item/list` | 分頁檔案清單 |
| GET | `item/options` | 上傳及儲存選項 |
| GET | `item/detail/{id}` | 檔案中繼資料、引用及副本 |
| POST | `item/upload` | 上傳檔案並建立中繼資料 |
| POST | `item/delete/{id}` | 刪除無引用檔案及其副本 |
| GET | `storage/list` | 不含憑證內容的儲存帳號 |
| GET | `storage/settings` | 目前有效上傳設定 |
| POST | `storage/save-settings` | 儲存上傳設定 |
| POST | `storage/create` | 建立儲存帳號 |
| POST | `storage/update/{id}` | 更新儲存帳號 |
| POST | `storage/delete/{id}` | 刪除未使用的儲存帳號 |
| GET | `storage/tasks` | 近期同步任務 |
| POST | `storage/sync` | 開始複寫 |
| POST | `storage/retry/{id}` | 重試失敗任務 |

傳送 `Accept: application/json`。上傳使用 multipart 的 `files[]`、`disk`、`type` 及 `visibility`。回應使用 `data`；分頁檔案回應另有 `total`、`last_page`。建立及上傳回傳 201，同步及重試回傳 202。驗證錯誤遵循宿主 Laravel 例外處理。

路由名稱以 `files.management.` 開頭。儲存資源僅回傳憑證是否存在的旗標，不回傳憑證內容。這是**全域管理 API**，不是依租戶隔離的檔案瀏覽器。

## 整合現有後台

保留自己的路由與控制器，停用套件內建管理路由，重用以下元件：

```text
Management/FileService
Management/FileStorageAccountService
Management/ManagementContext
Http/Requests/FileRequest
Http/Requests/FileStorageAccountRequest
Http/Resources/FileResource
Http/Resources/FileStorageAccountResource
```

服務接收已驗證陣列。直接呼叫服務屬於可信任的應用程式操作，呼叫端負責授權及租戶篩選。`FileService::store()` 支援已授權的業務上傳流程。

綁定 `ManagementContext` 子類別可提供操作者 ID、權限檢查及稽核持久化。預設上下文使用設定的驗證 guard，並發出包含操作、對象、變更及操作者 ID 的 `files.management.audit` 事件。稽核中繼資料不應包含憑證。選單、安全密碼檢查及前端介面由宿主實作。

## 引用及刪除

業務模型使用 `Concerns\HasFileReferences` 並實作 `fileReferenceFields()`；以 ID 儲存的欄位另需實作 `fileReferenceIdFields()`。僅設定 `reference_models` 不會安裝模型觀察者。業務欄位及其外鍵由宿主管理。

仍被引用的 File 記錄無法透過一般模型刪除操作移除。管理服務還會刪除原始檔案及副本。資料庫交易無法復原遠端物件刪除：某個磁碟失敗時，先前物件可能已經刪除。需要可靠復原的應用程式應實作刪除任務流程。

## 複寫

```bash
php artisan files:sync public archive --now
php artisan files:sync public archive
php artisan queue:work --queue=file-sync
```

先設定目標提供者或磁碟。僅複寫已登記檔案。連線預設使用宿主佇列連線，可透過 `FILES_SYNC_CONNECTION` 和 `FILES_SYNC_QUEUE` 覆寫，並相應調整 worker 的連線及佇列。同步驅動在目前程序內執行。

複寫會驗證來源及目的地校驗和、記錄進度，避免重複累計已完成檢查點。所有 worker 必須共用支援原子鎖定的快取。任務鎖於 600 秒後到期，儲存請求及 worker 逾時必須小於此期限。佇列重試間隔必須超過任務的 180 秒逾時。

## 模型及資料庫連線

覆寫模型必須繼承對應套件模型。引用更新要求擁有者、檔案、引用模型使用同一連線；複寫要求任務、檔案、副本模型使用同一連線；儲存管理參與模型也必須使用同一連線。跨連線操作會被拒絕。自訂連線的遷移需由宿主明確管理。

支援可選的 `uploader()` 關聯，但不強制要求。本套件不會匯入宿主管理員模型。

## 開發及升級

```bash
composer install
composer test
```

整合測試需要 `pdo_sqlite`，使用隔離的記憶體資料庫。CI 定義 PHP/Laravel 相容性工作，請查看目標版本的 CI 結果。

升級前檢查設定優先順序、模型及資料表覆寫、舊外鍵及佇列設定。在資料庫副本上測試遷移與回復。未遷移憑證就變更 `APP_KEY` 可能導致加密儲存憑證無法讀取。

## 授權條款

MIT。請見 [LICENSE](LICENSE)。

Copyright © 2026 [coding1688 · Moonsea](https://moonsea.dev).
