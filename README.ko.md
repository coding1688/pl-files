# pl-files

[English](README.md) | [简体中文](README.zh_CN.md) | [繁體中文](README.zh_TW.md) | [日本語](README.ja.md) | [한국어](README.ko.md)

[Moonsea](https://moonsea.dev) · [GitHub](https://github.com/coding1688/pl-files)

## 버전 0.5.0 기준

세 Moonsea 패키지는 `v0.5.0`부터 다시 시작합니다. 기존 태그와 GitHub Releases는 삭제하고 커밋 기록은 유지합니다. Composer 버전 제약과 잠금 파일을 명시적으로 갱신하세요. 이는 새로운 버전 기준이며 삭제된 릴리스에서 자동으로 업그레이드되지 않습니다.

files 초기 마이그레이션이 nullable JSON 열 `preferred_disks`를 직접 생성하며 별도의 열 추가 마이그레이션은 제거되었습니다. 기존 DB에서는 초기 마이그레이션이 다시 실행되지 않습니다. 전환 전에 설정된 테이블을 확인하고, 열이 없으면 호스트 마이그레이션으로 추가하며 이미 있으면 데이터를 유지하세요. 필요한 데이터가 있는 DB에 `migrate:fresh`를 실행하지 마세요. 과거 마이그레이션 기록이 삭제된 파일을 참조할 수 있으므로 전환 전에 롤백 계획을 검토하세요.

## 기능

Laravel용 파일 업로드, 참조 추적, 설정 가능한 스토리지 계정 및 검증된 복제를 제공합니다.

- 정규화된 파일 메타데이터를 반환하는 저수준 스토리지 API.
- 파일, 참조, 복제본, 스토리지 계정, 업로드 설정, 동기화 작업의 Eloquent 모델.
- 파일 참조 추적과 참조 중인 파일 레코드의 삭제 방지.
- 체크섬 기반 복제 검증, 진행 상황 체크포인트 및 파일 제공 디스크 선택.
- 파일, 스토리지 계정, 업로드 설정, 동기화 작업용 선택적 JSON 관리 API.
- 영어, 중국어 간체, 중국어 번체, 일본어, 한국어 메시지.

Vue 컴포넌트나 관리자 테마는 포함하지 않습니다. 인증, 권한 부여, 테넌트 격리, 업무별 업로드 규칙은 호스트 애플리케이션이 담당합니다.

## 요구 사항

선언된 지원 범위는 PHP `^8.2`, Laravel 구성 요소 `^11 | ^12 | ^13`입니다. 선택한 Laravel 버전은 더 높은 PHP 버전을 요구할 수 있습니다. 이 문서는 현재 소스를 설명하므로 일치하는 릴리스를 선택하세요.

로컬 스토리지는 Laravel 파일시스템 디스크를 사용합니다. S3 호환 드라이버에는 `league/flysystem-aws-s3-v3`도 필요합니다. 데이터베이스의 스토리지 자격 증명은 Laravel 암호화를 사용하므로 호스트에서 `APP_KEY`를 설정하고 안전하게 보관해야 합니다.

## 설치

```bash
composer require coding1688/pl-files
php artisan vendor:publish --tag=files-config
php artisan migrate
```

Composer에 설정된 레지스트리에서 패키지를 찾을 수 없다면 설치 전에 소스 저장소를 추가하세요.

```bash
composer config repositories.pl-files vcs https://github.com/coding1688/pl-files.git
```

Laravel은 `FilesServiceProvider`를 자동 검색합니다. 로컬 파일을 공개하려면 `public` 디스크를 설정하고 `php artisan storage:link`를 실행합니다. 기본 로컬 설치에는 클라우드 계정이나 큐 워커가 필요하지 않습니다.

패키지 마이그레이션은 자동으로 로드됩니다. `--tag=files-migrations`로 게시하면 `files.load_migrations=false`로 설정하고 호스트에서 관리하세요. 두 복사본을 동시에 로드하지 마세요. 테이블 이름은 첫 마이그레이션 전에 설정합니다.

## 설정

`config/files.php`와 `config('files.…')`를 사용합니다.

| 설정 | 용도 |
| --- | --- |
| `management` | 선택적 라우트, 접두사, 미들웨어, guard, Gate 및 이름 언어 |
| `models` / `tables` | 모델 하위 클래스와 테이블 이름 |
| `uploads` | 허용 디스크, 업로드 제한, 확장자 및 제공자 기본값 |
| `sync.connection` / `sync.queue` | 큐 연결 재정의 및 큐 이름 |
| `preferred_disks` | 검증된 복제본을 제공할 우선 디스크 |
| `reference_models` | 스토리지 관리 참조 검사에 사용하는 호스트 모델 |
| `load_migrations` | 패키지 마이그레이션 자동 로드 |
| `user_table` | 생성자 외래 키에 사용할 선택적 사용자 테이블 |

전체 기본값은 설정 파일을 참고하세요. 우선순위는 **패키지 기본값 → 기존 `pl.files` → `files`**입니다. 맵은 병합하고 목록은 빈 목록을 포함하여 교체합니다. 기존 값은 서비스 프로바이더 등록 시 가져옵니다. 새 런타임 재정의는 `files.*`를 사용하세요. 데이터베이스 업로드 설정은 정적 기본값을 재정의할 수 있습니다.

업무 테이블의 외래 키는 호스트 마이그레이션이 관리합니다. `owner_tables`는 이전 패키지가 만든 제약 조건의 롤백 호환성만을 위해 유지됩니다.

## 저수준 업로드

권한이 확인된 컨트롤러 작업 안에서 사용합니다.

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

`store()`는 파일을 쓰고 `StoredFile`을 반환하지만 데이터베이스 레코드를 생성하거나 업무 권한 및 입력을 검증하지 않습니다. 디스크, 디렉터리, 공개 범위는 서버에서 선택하세요. 필요한 메타데이터를 저장하고 DB 쓰기가 실패하면 사용하지 않는 파일을 정리하세요.

업로드와 파일 레코드를 함께 관리하려면 검증된 입력을 `Coding1688\Files\Management\FileService`에 전달하거나 아래 관리 API를 활성화하세요.

## 선택적 관리 API

기본적으로 비활성화되어 있습니다. 다음 설정을 `config/files.php`에 병합하여 활성화합니다.

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

호스트 서비스 프로바이더에서 Gate를 정의하세요. 아래 예시는 애플리케이션에서 구현해야 하는 권한에 위임합니다.

```php
use Illuminate\Support\Facades\Gate;

Gate::define('manage-files', fn ($user) => $user->can('manage-storage'));
```

모든 기본 라우트는 미들웨어 목록을 변경해도 인증된 사용자와 이 Gate의 승인을 요구합니다. 기본 `web` 그룹은 CSRF 보호를 유지합니다. 토큰 API에는 호스트의 인증 API 미들웨어와 guard를 설정하세요. 라우트 설정 변경 후 라우트 캐시를 다시 생성하세요.

아래 경로는 `/files-management` 또는 설정한 접두사를 기준으로 합니다.

| 메서드 | 경로 | 작업 |
| --- | --- | --- |
| GET | `item/list` | 페이지별 파일 목록 |
| GET | `item/options` | 업로드 및 스토리지 옵션 |
| GET | `item/detail/{id}` | 메타데이터, 참조 및 복제본 |
| POST | `item/upload` | 파일 업로드 및 메타데이터 생성 |
| POST | `item/delete/{id}` | 참조 없는 파일과 복사본 삭제 |
| GET | `storage/list` | 자격 증명 값을 제외한 계정 목록 |
| GET | `storage/settings` | 유효한 업로드 설정 |
| POST | `storage/save-settings` | 업로드 설정 저장 |
| POST | `storage/create` | 스토리지 계정 생성 |
| POST | `storage/update/{id}` | 스토리지 계정 수정 |
| POST | `storage/delete/{id}` | 미사용 계정 삭제 |
| GET | `storage/tasks` | 최근 동기화 작업 |
| POST | `storage/sync` | 복제 시작 |
| POST | `storage/retry/{id}` | 실패 작업 재시도 |

`Accept: application/json`을 전송하세요. 업로드는 multipart의 `files[]`, `disk`, `type`, `visibility`를 사용합니다. 응답은 `data`를 사용하며 페이지별 파일 응답에는 `total`, `last_page`도 포함됩니다. 생성 및 업로드는 201, 동기화 및 재시도는 202를 반환합니다. 검증 및 인증 오류는 호스트의 Laravel 예외 처리를 따릅니다.

라우트 이름은 `files.management.`로 시작합니다. 스토리지 리소스는 자격 증명 존재 여부만 반환하고 실제 값은 반환하지 않습니다. 이는 **전체 관리 API**이며 테넌트별로 격리된 파일 탐색기가 아닙니다.

## 기존 관리자 애플리케이션 통합

자체 라우트와 컨트롤러를 유지하고 기본 관리 라우트를 비활성화한 상태에서 다음 구성 요소를 재사용하세요.

```text
Management/FileService
Management/FileStorageAccountService
Management/ManagementContext
Http/Requests/FileRequest
Http/Requests/FileStorageAccountRequest
Http/Resources/FileResource
Http/Resources/FileStorageAccountResource
```

서비스는 검증된 배열을 받습니다. 직접 서비스 호출은 신뢰할 수 있는 애플리케이션 작업으로 간주되며 호출자가 권한 확인과 테넌트 필터링을 담당합니다. `FileService::store()`는 이미 권한을 확인한 업무 업로드에 사용할 수 있습니다.

`ManagementContext` 하위 클래스를 바인딩하여 작업자 ID, 권한 검사, 감사 기록 저장을 제공할 수 있습니다. 기본 컨텍스트는 설정된 인증 guard를 사용하고 작업, 대상, 변경 내용, 작업자 ID가 담긴 `files.management.audit` 이벤트를 발생시킵니다. 감사 메타데이터에 자격 증명을 넣지 마세요. 메뉴, 보안 비밀번호 검사, 프런트엔드 UI는 호스트에서 구현합니다.

## 참조 및 삭제

업무 모델에 `Concerns\HasFileReferences`를 적용하고 `fileReferenceFields()`를 구현하세요. ID 기반 필드에는 `fileReferenceIdFields()`도 구현합니다. `reference_models` 설정만으로는 모델 옵저버가 설치되지 않습니다. 업무 열과 외래 키는 호스트에서 관리합니다.

참조 중인 File 레코드는 일반 모델 삭제로 제거할 수 없습니다. 관리 서비스는 원본과 복제 객체도 제거합니다. DB 트랜잭션으로 원격 객체 삭제를 취소할 수는 없습니다. 한 디스크에서 실패했을 때 다른 객체는 이미 삭제되었을 수 있습니다. 안정적인 복구가 필요하면 삭제 작업 워크플로를 구현하세요.

## 복제

```bash
php artisan files:sync public archive --now
php artisan files:sync public archive
php artisan queue:work --queue=file-sync
```

먼저 대상 제공자나 디스크를 설정하세요. 등록된 파일만 복제합니다. 기본 연결은 호스트 큐 연결이며 `FILES_SYNC_CONNECTION`, `FILES_SYNC_QUEUE`로 변경할 수 있습니다. 워커의 연결과 큐도 맞춰주세요. 동기 드라이버는 현재 프로세스에서 실행됩니다.

원본 및 대상 체크섬을 검증하고 진행 상황을 기록하며 완료된 체크포인트의 중복 집계를 방지합니다. 모든 워커는 원자적 잠금을 지원하는 캐시를 공유해야 합니다. 작업 잠금은 600초 후 만료되므로 스토리지 요청과 워커 제한 시간은 이보다 짧아야 합니다. 큐 재시도 간격은 작업 제한 시간인 180초보다 길어야 합니다.

## 모델과 데이터베이스 연결

재정의 모델은 해당 패키지 모델을 상속해야 합니다. 참조 갱신에는 소유자·파일·참조 모델이, 복제에는 작업·파일·복제본 모델이 같은 연결을 사용해야 합니다. 스토리지 관리에 참여하는 모델도 같은 연결이 필요합니다. 연결 간 작업은 거부됩니다. 사용자 정의 연결의 마이그레이션은 호스트에서 명시적으로 관리하세요.

선택적인 `uploader()` 관계를 지원하지만 필수는 아닙니다. 호스트 관리자 모델을 가져오지 않습니다.

## 개발 및 업그레이드

```bash
composer install
composer test
```

통합 테스트는 `pdo_sqlite`가 필요하며 격리된 메모리 데이터베이스를 사용합니다. CI는 PHP/Laravel 호환성 작업을 정의합니다. 사용할 릴리스의 결과를 확인하세요.

업그레이드 전에 설정 우선순위, 모델 및 테이블 재정의, 기존 외래 키, 큐 설정을 검토하세요. 데이터베이스 복사본에서 마이그레이션과 롤백을 테스트하세요. 자격 증명을 이전하지 않고 `APP_KEY`를 변경하면 암호화된 스토리지 자격 증명을 읽지 못할 수 있습니다.

## 라이선스

MIT. [LICENSE](LICENSE)를 참고하세요.

Copyright © 2026 [coding1688 · Moonsea](https://moonsea.dev).
