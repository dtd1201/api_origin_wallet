# STEP 460 — sửa test lifecycle và fixture cross-wallet

Ngày: 2026-11-12, Asia/Bangkok. Trạng thái: đã sửa source, chờ operator kiểm thử.

Chỉ đọc source/log có sẵn và sửa test/tài liệu. Không chạy PHP, PHPUnit, Artisan,
migration, build, deploy, Git hoặc gọi provider. Kết quả STEP 455–460 do operator
cung cấp không phải kết quả của bản sửa này. Không tuyên bố PASS.

## Root cause riêng cho ba lỗi

### Error 1 — submit thành công nhưng teardown bị từ chối

Trước sửa, `NiumTransferServiceTest.php:76` gọi
`$this->app->detectEnvironment(fn (): string => 'staging')` và không khôi phục.
Mục đích hợp lệ là kiểm tra staging-only transfer diagnostics trong
`app/Services/Integrations/ProviderHttpClient.php:609`.

`detectEnvironment()` gán trực tiếp application binding `env`
(`vendor/laravel/framework/src/Illuminate/Foundation/Application.php:786`).
`runningUnitTests()` cũng phụ thuộc chính binding này bằng so sánh với `testing`
(cùng file, dòng 832). Khi teardown chạy, environment vẫn là `staging`:
`environment('testing')` và `runningUnitTests()` đều false. Điều kiện đầu tiên tại
`tests/Support/Auto02bPostgresTestReset.php:21` đã đủ kích hoạt exception dòng 27;
các nhánh kiểm tra connection phía sau bị short-circuit. Guard từ chối đúng.

Sửa tại `tests/Feature/NiumTransferServiceTest.php:197`: chỉ đặt staging trong
lời gọi submit bằng helper dòng 75; `finally` khôi phục environment gốc trước mọi
teardown. Assertion diagnostics, payload, trạng thái và logging được giữ nguyên.
Sau submit, test yêu cầu environment trở lại testing và gọi đầy đủ `assertSafe()`.

### Error 2 — provider error đã được test xử lý, environment vẫn bị rò rỉ

Trước sửa, `NiumTransferServiceTest.php:1329` cũng đặt staging cho toàn bộ test.
Test bắt RuntimeException của provider và kiểm tra operational data nhưng không
khôi phục environment. Callback reset sau đó gặp cùng điều kiện false tại
`Auto02bPostgresTestReset.php:21`, không phải lỗi đọc dữ liệu provider hay bằng
chứng transaction bị bỏ mở.

Sửa tại `tests/Feature/NiumTransferServiceTest.php:1411`: submit nằm trong cùng
helper `try/finally`. Exception gốc vẫn được truyền ra cho assertion cũ; environment
được khôi phục cả khi provider trả lỗi. Test kiểm tra lại testing và `assertSafe()`
ở dòng 1417–1418. Các assertion sanitization và bảo toàn dữ liệu fixture không đổi.

### Failure 3 — fixture thiếu Balance của wallet hiện tại

`makeSubmittableTransfer()` tạo provider account và Balance gắn `wallet-test`
(`tests/Feature/NiumTransferServiceTest.php:2355` và dòng 2366). Lock được tạo qua
service thật với HTTP fake, lưu `provider_context.wallet = wallet-test`
(`app/Services/Nium/NiumPayoutFxLockService.php:47`, dòng 90 và dòng 160).

Test cũ chỉ đổi provider account sang `changed-wallet`; không tạo Balance cho ví
này. Trình tự source thực tế trong `NiumTransferService::prepareSubmission()`:

1. Xác thực eligibility và đọc identifiers của provider account dưới row lock.
2. Tìm/khóa Balance đúng owner, provider, **wallet hiện tại** và USD
   (`app/Services/Nium/NiumTransferService.php:232`, dòng 234–236).
3. Sau đó mới gọi `ensureAuthoritativeQuote()` tại dòng 241, dẫn tới validation
   context trong `NiumPayoutFxLockContract.php:125` và exception binding ở dòng 133.

Ở bước 2, `firstOrFail()` không tìm thấy Balance của `changed-wallet`.
`NiumSubmissionPreflightException` giữ nguyên message của exception gốc
(`app/Services/Nium/NiumSubmissionPreflightException.php:11`). Vì thế assertion
binding cũ thất bại trước khi validator FX lock được chạy.

Đây là fixture chưa đạt tiền điều kiện của nhánh cần kiểm thử. Việc application
từ chối sớm khi thiếu funding wallet là fail-closed; không có bằng chứng cần đổi
thứ tự validation production để sửa lỗi này.

Sửa tại `tests/Feature/NiumTransferServiceTest.php:2130`: tạo Balance USD riêng cho
`changed-wallet`, cùng owner/provider, giữ nguyên Balance và quote của ví gốc.
Trước đổi wallet, test xác nhận quote đọc lại từ PostgreSQL hợp lệ với context gốc.
Sau đổi, vẫn bắt buộc đúng thông báo `binding does not match`; không thay bằng
assertion chấp nhận exception bất kỳ.

## Transaction, configuration, connection và teardown

- Alias `OwnerFirstPostgresDatabase as RefreshDatabase` chỉ đổi tên import.
  Trait thực tế dùng `DatabaseMigrations`, không dùng transaction bao toàn bộ test.
  `OwnerFirstPostgresDatabase.php:17` kiểm tra an toàn trước setup; dòng 21–22 đăng
  ký reset lúc teardown. `Tests\TestCase::artisan()` vẫn intercept `migrate:fresh`
  bằng cùng guarded reset (`tests/TestCase.php:15`).
- Laravel chạy callback trước khi flush application
  (`InteractsWithTestCaseLifecycle.php:122`); callbacks được gọi theo thứ tự đăng
  ký (dòng 293, 303). Thêm callback restore sau callback reset sẽ quá muộn. Bản sửa
  khôi phục tại scope submit, không thay thứ tự hoặc bỏ qua callback teardown.
- Owner-first roots dùng transaction có commit/rollback của Laravel; HTTP remittance
  nằm ngoài transaction. Hai test không đổi default connection, DB host/database,
  không purge/disconnect hoặc tự mở transaction. Không xác minh runtime transaction
  depth của lần chạy cũ từ message gộp; source đã xác định environment là điều kiện
  chắc chắn gây từ chối. Regression mới kiểm tra depth 0 và PDO ngoài transaction.
- `phpunit.xml:30` mặc định SQLite, nên operator phải chỉ định PostgreSQL trong
  lệnh bên dưới. Không sửa XML, `.env` hoặc config để làm test chạy qua.
- Guard giữ nguyên: testing/unit-test mode, pgsql, host loopback, database cấu hình
  chính xác `origin_wallet_auto02b_test`, Laravel/PDO ngoài transaction, **write PDO**
  có database thực đúng tên và schema `public`. Không mở rộng allowlist, không bắt
  rồi bỏ qua lỗi reset, không tự rollback để che transaction leak.

## File thay đổi và regression

1. `tests/Feature/NiumTransferServiceTest.php`:
   - Helper `withStagingEnvironment()` khôi phục environment gốc bằng `finally`.
   - Hai test diagnostics chỉ dùng staging trong submit; thêm assertion testing và
     gọi nguyên guard sau khi submit trả về hoặc provider error được bắt.
   - Test mới `test_staging_diagnostics_scope_restores_testing_after_exception`
     xác nhận exception gốc được truyền ra, environment/unit-test mode được khôi
     phục, connection object giữ nguyên, transaction depth bằng 0, PDO ngoài
     transaction và tất cả safety predicates hiện hành vẫn được áp dụng.
   - Test cross-wallet có Balance của ví mới và valid-control cho quote gốc; giữ
     nguyên assertion binding, draft, chưa có operation key, một quote/một lock GET,
     không remittance POST. Thêm assertion cả hai Balance và quote không bị thay
     đổi, ledger không phát sinh entry.
2. `tests/Support/nium-step460-test-fixture-repair.md`: báo cáo này.

Không sửa bất kỳ file `app/`, migration, config, reset support hay frontend nào.
Không đổi financial terms, FX/provider contract, phí, approval hoặc automatic
dispatch. HK SWIFT và SGD LOCAL production giữ nguyên; test vẫn kiểm tra staging
diagnostics qua production service với HTTP fake. Các thay đổi local có sẵn được
giữ nguyên bằng chỉnh sửa có phạm vi và đối chiếu source snapshot, không dùng Git.

## Một lệnh operator kiểm chứng

Chạy từ `/home/dtd1201/projects/api_origin_wallet`, dùng credentials test đã cấp
cho operator. Lệnh chọn đúng ba case có lỗi và regression mới. Test lifecycle sẽ
reset public schema của disposable database; chỉ chạy một runner trên DB này.
Bootstrap giữ chặn stray HTTP. Lệnh dưới đây **chưa được thực thi**:

```bash
APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=origin_wallet_auto02b_test DB_URL= \
php vendor/bin/phpunit --bootstrap tests/nium-transfer-offline-bootstrap.php \
  --fail-on-warning --fail-on-risky --fail-on-skipped \
  --filter 'NiumTransferServiceTest::test_(submit_transfer_creates_nium_remittance_and_updates_transfer|provider_error_appends_operational_data_without_replacing_nium_fixture_data|bound_legacy_lock_cannot_be_submitted_from_another_wallet|staging_diagnostics_scope_restores_testing_after_exception)$' \
  tests/Feature/NiumTransferServiceTest.php
```

## Rủi ro và giới hạn

Chờ operator chạy lại; chưa có kết quả sau sửa hoặc kiểm tra cú pháp PHP. Lệnh
focused không thay thế việc chạy lại toàn bộ STEP 460 hay legacy regression khi
sign-off release. Không phát hiện blocker source khác trong phạm vi ba lỗi này;
điều đó không xác nhận production readiness hoặc đóng các blocker provider/financial
evidence của nhiệm vụ Dynamic trước đó. Nếu runtime còn transaction/connection
mismatch, guard vẫn phải từ chối và operator cần giữ nguyên stack trace để điều tra.
