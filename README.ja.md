# pl-files

[English](README.md) | [简体中文](README.zh_CN.md) | [繁體中文](README.zh_TW.md) | [日本語](README.ja.md) | [한국어](README.ko.md)

[Moonsea](https://moonsea.dev) · [GitHub](https://github.com/coding1688/pl-files)

## バージョン 0.5.0 の基準

3 つの Moonsea パッケージは `v0.5.0` から再開します。以前のタグと GitHub Releases は削除し、コミット履歴は保持しています。Composer のバージョン制約とロックファイルを明示的に更新してください。これは新しいバージョン基準であり、削除したリリースからの自動更新ではありません。

files の初期マイグレーションが nullable な JSON カラム `preferred_disks` を作成するようになり、個別のカラム追加マイグレーションは削除しました。既存 DB では初期マイグレーションを再実行しません。移行前に設定対象テーブルを確認し、カラムがなければホスト側のマイグレーションで追加し、既にあればデータを保持してください。必要なデータがある DB で `migrate:fresh` を実行しないでください。履歴に削除済みファイルへの参照が残る場合があるため、切り替え前にロールバック計画を確認してください。

## 機能

Laravel 向けのファイルアップロード、参照追跡、設定可能なストレージアカウント、検証付きレプリケーションを提供します。

- 正規化されたファイルメタデータを返す低レベルストレージ API。
- ファイル、参照、レプリカ、ストレージアカウント、アップロード設定、同期タスクの Eloquent モデル。
- ファイル参照の追跡と、参照中レコードの削除防止。
- チェックサムによるレプリカ検証、進捗チェックポイント、配信用ディスクの選択。
- ファイル、ストレージアカウント、アップロード設定、同期タスク用の任意の JSON 管理 API。
- 英語、簡体字中国語、繁体字中国語、日本語、韓国語のメッセージ。

Vue コンポーネントや管理画面テーマは含みません。認証、認可、テナント分離、業務固有のアップロード規則はホストアプリケーションが担当します。

## 要件

宣言上の対応範囲は PHP `^8.2`、Laravel コンポーネント `^11 | ^12 | ^13` です。Laravel のバージョンによっては、より新しい PHP が必要です。この文書は現在のソースを説明しています。対応するリリースを選んでください。

ローカルストレージは Laravel のファイルシステムディスクを使用します。S3 互換ドライバーには `league/flysystem-aws-s3-v3` も必要です。データベース内のストレージ認証情報は Laravel で暗号化するため、ホストで `APP_KEY` を設定し、安全に管理してください。

## インストール

```bash
composer require coding1688/pl-files
php artisan vendor:publish --tag=files-config
php artisan migrate
```

Composer の設定済みレジストリで見つからない場合は、先にソースリポジトリを追加してください。

```bash
composer config repositories.pl-files vcs https://github.com/coding1688/pl-files.git
```

Laravel は `FilesServiceProvider` を自動検出します。ローカルファイルを公開する場合は `public` ディスクを設定し、`php artisan storage:link` を実行します。基本的なローカル構成にはクラウドアカウントやキューワーカーは不要です。

マイグレーションは自動読み込みされます。`--tag=files-migrations` で公開する場合は `files.load_migrations=false` にし、ホスト側で管理してください。二重に読み込まないでください。テーブル名は初回マイグレーション前に設定します。

## 設定

`config/files.php` と `config('files.…')` を使用します。

| 設定 | 用途 |
| --- | --- |
| `management` | 任意のルート、プレフィックス、ミドルウェア、guard、Gate、名前の言語 |
| `models` / `tables` | モデルのサブクラスとテーブル名 |
| `uploads` | 許可ディスク、アップロード制限、拡張子、プロバイダー既定値 |
| `sync.connection` / `sync.queue` | キュー接続の上書きとキュー名 |
| `preferred_disks` | 検証済みレプリカの配信優先ディスク |
| `reference_models` | ストレージ管理の参照確認に使うホストモデル |
| `load_migrations` | パッケージマイグレーションの自動読み込み |
| `user_table` | 作成者外部キー用の任意のユーザーテーブル |

全デフォルト値は設定ファイルを参照してください。優先順位は **パッケージ既定値 → 旧 `pl.files` → `files`** です。マップはマージし、リストは空リストも含めて置換します。旧設定はプロバイダー登録時に取り込みます。実行時の新しい上書きには `files.*` を使用します。データベースのアップロード設定は静的な既定値を上書きできます。

業務テーブルの外部キーはホストのマイグレーションで管理します。`owner_tables` は、旧バージョンが作成した制約のロールバック互換性のためだけに残っています。

## 低レベルアップロード

認可済みのコントローラー処理内で使用します。

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

`store()` はファイルを書き込み `StoredFile` を返します。データベースレコードの作成や業務処理の認可・検証は行いません。ディスク、ディレクトリ、公開範囲はサーバー側で決定してください。必要に応じてメタデータを保存し、DB 書き込み失敗時には不要なファイルを削除します。

アップロードとレコード管理を連携させるには、検証済み入力を `Coding1688\Files\Management\FileService` に渡すか、次の管理 API を有効にします。

## 任意の管理 API

既定では無効です。有効にするには、以下を `config/files.php` にマージします。

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

ホストのサービスプロバイダーで Gate を定義します。次の例では、アプリケーション側で実装する権限に委譲しています。

```php
use Illuminate\Support\Facades\Gate;

Gate::define('manage-files', fn ($user) => $user->can('manage-storage'));
```

すべての同梱ルートは、ミドルウェア一覧を変更しても、認証済みユーザーとこの Gate の許可を要求します。既定の `web` グループは CSRF 保護を維持します。トークン API ではホストの認証 API ミドルウェアと guard を設定してください。ルート設定を変更したらルートキャッシュを再作成します。

以下は `/files-management` または設定したプレフィックスからの相対パスです。

| メソッド | パス | 操作 |
| --- | --- | --- |
| GET | `item/list` | ファイルのページ一覧 |
| GET | `item/options` | アップロードとストレージの選択肢 |
| GET | `item/detail/{id}` | メタデータ、参照、レプリカ |
| POST | `item/upload` | アップロードとメタデータ作成 |
| POST | `item/delete/{id}` | 未参照ファイルとコピーの削除 |
| GET | `storage/list` | 認証情報の値を含まないアカウント一覧 |
| GET | `storage/settings` | 有効なアップロード設定 |
| POST | `storage/save-settings` | アップロード設定の保存 |
| POST | `storage/create` | ストレージアカウント作成 |
| POST | `storage/update/{id}` | ストレージアカウント更新 |
| POST | `storage/delete/{id}` | 未使用アカウント削除 |
| GET | `storage/tasks` | 最近の同期タスク |
| POST | `storage/sync` | レプリケーション開始 |
| POST | `storage/retry/{id}` | 失敗タスクの再試行 |

`Accept: application/json` を送信します。アップロードは multipart の `files[]`、`disk`、`type`、`visibility` を使用します。レスポンスは `data` を使用し、ファイルのページネーションには `total`、`last_page` も含みます。作成・アップロードは 201、同期・再試行は 202 を返します。検証・認証エラーはホストの Laravel 例外処理に従います。

ルート名は `files.management.` で始まります。ストレージリソースは認証情報の有無のみを返し、値自体は返しません。これは**全体管理用 API** であり、テナント別のファイルブラウザーではありません。

## 既存の管理画面への統合

ホストのルートとコントローラーを維持し、同梱管理ルートを無効のままにして、以下を再利用できます。

```text
Management/FileService
Management/FileStorageAccountService
Management/ManagementContext
Http/Requests/FileRequest
Http/Requests/FileStorageAccountRequest
Http/Resources/FileResource
Http/Resources/FileStorageAccountResource
```

サービスは検証済み配列を受け取ります。直接呼び出しは信頼されたアプリケーション操作であり、認可とテナント絞り込みは呼び出し側の責任です。`FileService::store()` は認可済みの業務アップロードに利用できます。

`ManagementContext` のサブクラスをバインドすると、実行者 ID、権限チェック、監査ログの永続化を提供できます。既定コンテキストは設定された認証 guard を使い、操作、対象、変更内容、実行者 ID を含む `files.management.audit` を発行します。監査メタデータに認証情報を含めないでください。メニュー、安全確認用パスワード、フロントエンド UI はホストで実装します。

## 参照と削除

業務モデルに `Concerns\HasFileReferences` を適用して `fileReferenceFields()` を実装します。ID を保持するフィールドには `fileReferenceIdFields()` も実装します。`reference_models` の設定だけではモデルオブザーバーは登録されません。業務カラムと外部キーはホストが管理します。

参照中の File レコードは通常のモデル削除では削除できません。管理サービスは元ファイルとレプリカも削除します。DB トランザクションではリモートオブジェクトの削除を取り消せません。あるディスクで失敗した時点で、他のオブジェクトは削除済みの場合があります。確実な復旧が必要な場合は削除ジョブのワークフローを実装してください。

## レプリケーション

```bash
php artisan files:sync public archive --now
php artisan files:sync public archive
php artisan queue:work --queue=file-sync
```

先に宛先プロバイダーまたはディスクを設定します。登録済みファイルだけが対象です。接続はホストのキュー接続が既定で、`FILES_SYNC_CONNECTION` と `FILES_SYNC_QUEUE` で変更できます。ワーカーの接続とキューも合わせてください。同期ドライバーでは同一プロセスで実行します。

ソースと宛先のチェックサムを検証し、進捗を記録して、完了済みチェックポイントの重複集計を防ぎます。すべてのワーカーでアトミックロック対応キャッシュを共有してください。タスクロックは 600 秒で失効するため、ストレージ要求とワーカーのタイムアウトをその範囲内に設定します。キューの再試行間隔はジョブの 180 秒タイムアウトより長くしてください。

## モデルとデータベース接続

カスタムモデルは対応するパッケージモデルを継承してください。参照更新では所有者・ファイル・参照モデル、レプリケーションではタスク・ファイル・レプリカモデルを同じ接続に置きます。ストレージ管理に参加するモデルも同じ接続が必要です。接続をまたぐ操作は拒否します。カスタム接続のマイグレーションはホストが明示的に管理してください。

任意の `uploader()` リレーションに対応しますが、必須ではありません。ホストの管理者モデルはインポートしません。

## 開発とアップグレード

```bash
composer install
composer test
```

統合テストには `pdo_sqlite` が必要で、分離されたインメモリ DB を使用します。CI には PHP/Laravel の互換性ジョブがあります。使用するリリースの結果を確認してください。

更新前に設定の優先順位、モデルとテーブルの上書き、旧外部キー、キュー設定を確認します。DB のコピーでマイグレーションとロールバックをテストしてください。認証情報を移行せずに `APP_KEY` を変更すると、暗号化済み認証情報を読めなくなることがあります。

## ライセンス

MIT。[LICENSE](LICENSE) を参照してください。

Copyright © 2026 [coding1688 · Moonsea](https://moonsea.dev).
