Báo cáo Backend Local — 2026-11-16

Branch `integration/nium-dynamic-multicurrency-release`, HEAD `eff2b74`.
Chỉ sửa local; không chạy tests/builds/migrations, bootstrap ứng dụng, gọi Nium,
sửa Production, commit/push/deploy. Các file untracked có sẵn được giữ nguyên.

Kết quả: sửa guard schema trong phạm vi contract đã giữ lại. Chưa xác nhận READY
cho payout Production; thiếu evidence thực tế được liệt kê bên dưới. Không thêm
validator JSON Schema cục bộ với mapping remitter/beneficiary suy đoán: validator
instruction đã tồn tại, chính là non-settling Nium `/remittance/validate`.

Root cause và thay đổi

- `schema_observation.instruction_validated=false`, `eligible=false` và
  `requires_instruction_validation=true` là đúng: observation chưa có instruction.
  Không có consumer nào dùng các giá trị mặc định này làm blanket execution hold.
- `DynamicPayoutEligibility::schemaObservation()` trước đây từ chối mọi
  `then.required` chứa `payout.sourceAmount`/`payout.destinationAmount`, kể cả trong
  subset `if.properties/const`, `if.required` → `then.required` đã nhận diện.
  Guard này xảy ra trước khi instruction được gửi tới validator của Nium.
- Parser nay giữ nguyên conditional amount requirements trong subset đó. Nó chỉ
  quảng bá mode ứng viên có amount property, precision cần thiết và requirement
  tương ứng ở root hoặc `then.required`. Requirement amount đối diện ở root vẫn
  chặn request chỉ gửi một amount. Presence/precision của hai properties đơn thuần
  không còn quảng bá cả hai mode. Predicate không được giả định là thỏa mãn.
- Nium vẫn phải trả HTTP 200, `valid=true`, `issues=[]` cho đúng request body.
  Toàn bộ required/conditional constraints, kể cả dữ liệu registered beneficiary
  và remitter do Nium resolve, vẫn do provider validator kiểm tra. Không thay thế
  provider validation bằng summary của parser. `else`, `anyOf`, `$ref` và các
  semantics ngoài subset hiện có tiếp tục fail closed ở các guard tương ứng.
- Không hardcode eligible, thay field mapping, debit, fee, Quote/Consent,
  idempotency, reconciliation hoặc contract Frontend. Legacy SGD LOCAL/HK SWIFT
  không được sửa.

Trace execution và instruction binding

| Bước | Boundary và điều kiện thực tế |
| --- | --- |
| Options | `NiumDynamicPayoutController::options` → `NiumDynamicReviewService::availability`: registration evidence, customer corridors, payout schema, financial authorization. Chỉ cho reference quoting; executable modes vẫn rỗng. |
| Quote | `NiumDynamicPayoutQuoteService::create`: authenticated customer listing, exact amount request, USD principal/debit theo contract, immutable quote response/binding/expiry. Quote chưa tạo instruction hoặc consent. |
| Review | Controller tạo `PayoutInstruction`; `NiumDynamicReviewService::prepare/observe` lấy schema mới và gọi `DynamicPayoutInstructionValidation::validate`. Đây là nơi tạo request instruction đầy đủ với beneficiary ID, quote, selected amount, purpose/source of funds/comment và operation key. |
| Validation | `POST .../wallet/{wallet}/remittance/validate`, ngoài transaction. Kiểm tra method/origin/path/scope, đúng bytes và chỉ một dispatch callback; HTTP 200 + boolean valid + empty issues; observation có SHA-256 request/response, TTL 30 giây. |
| Cho phép Confirm | Review service seal/cache record và tạo `PayoutConfirmation`; controller gọi `validateReview` dưới owner locks trước khi trả READY/fingerprint. `OriginDynamicPayoutEvidenceSource::revalidate` kiểm tra quote, registration/current receiving account, schema bytes/hash/summary, profile, observation TTL và wallet snapshot. |
| Confirm | `DynamicTransferConfirmationService::confirm`: kiểm tra accepted fingerprint và terms; idempotency cùng reference, quote consumption permanent, hold/reservation/outbox atomic. Không gọi payout POST trong controller. |
| Dispatch | `DynamicPayoutDispatcher`: refresh evidence khi gần hết hạn; revalidate dưới lock; `DynamicPayoutRequest::json` tái tạo payload và so với `validated_remittance_sha256`. `beginWire` kiểm tra lại payload và ghi `attempts=1` trước wire. |
| Spend/recovery | `NiumDynamicPayoutTransport` dùng fixed remittance path và isolated transport. Unknown/error outcome chuyển ambiguous/reconciliation; stale claim không quay lại held. Không thêm POST retry. |

Request contract được kiểm tra trong code

- Nested `beneficiary.id`; `purposeCode`, `sourceOfFunds`, `customerComments`;
  `externalId=operationKey`.
- `payout.sourceCurrency=USD`, `payout.quoteId`, `payout.tradeOrderID=operationKey`
  và đúng một trong `sourceAmount`/`destinationAmount`. Source amount là principal,
  không phải total USD debit gồm fee. Không thêm `additionalFees` hoặc markup.
- LOCAL rail/destination được bind qua registered beneficiary, customer corridor,
  quote binding và current receiving-record fingerprint. Không tự thêm
  `payoutMethod`/`destinationCurrency` vào payout object: retained Payout contract
  không xác lập những fields đó cho request Dynamic này.
- `observe()` kiểm tra cả quoted USD principal và recipient amount theo scale
  schema. Spend không có fallback USD scale khi schema thiếu source field.
  Validator giữ giới hạn hai decimals của documented remittance contract;
  dispatcher còn kiểm tra scale đã bind trong confirmed payout contract.
  Không round hoặc chuyển float thành financial authority.

Bằng chứng dùng và giới hạn

| Evidence đã đọc | Phạm vi chứng minh |
| --- | --- |
| `docs/nium-dynamic-execution-contract-excerpts.md` | Retained official `https://docs.nium.com/oas/nium.yaml`, SHA-256 `aca822bffa75e73b76a946d3afc52850938b264d4d566ea3b85600717f6e76a7`; original lines 12321–12405 customer corridors, 14045–14115 dotted payout schema/conditional required, 13758–13827 validate SAME body as Transfer Money, 54687–54765 amount semantics/quoteId. Đây là documented contract, không phải authenticated response của tài khoản hiện tại. |
| `docs/nium-dynamic-end-to-end-handoff.md` | Existing architecture chủ ý dùng provider validator cho complete schema applicability và registered beneficiary/remitter. |
| `docs/nium-dynamic-backend-local-finalization-2026-11-13.md`, phần 5 | Recorded Spend GB/GBP/LOCAL draft-2020-12 observation và thiếu evidence về mode, full conditional semantics, legal entity/lookup policy. Các phần lịch sử khác của báo cáo được đối chiếu lại với HEAD, không coi là current source. |
| `DynamicPayoutEnvironment.php` và source tại `eff2b74` | Approved Spend origin, exact schema endpoint/dialect, remittance path compatibility; không cấp quyền toàn bộ payload/corridor. |
| Tests hiện có và tests mới | Synthetic contract regression, không phải Production acceptance. Fixture beneficiary HK USD SWIFT không phải payout schema Dynamic. Không tìm thấy raw authenticated Spend payout-schema response đầy đủ trong các artifact repository đã kiểm tra. |

Guard còn chặn và dữ liệu cần lấy

1. Nếu customer corridor response vẫn chỉ có bốn listing fields: guard
   `dynamic_customer_corridor_legal_entity_unverified`, tiếp theo là
   `dynamic_customer_corridor_lookup_policy_unverified`. Cần response đầy đủ hoặc
   Nium contract chính thức xác lập semantics/authorization tương đương cho Spend
   dialect đó; không suy ra LEGAL_ENTITY/ignoreLookup từ customer CLEAR.
2. Nếu schema chỉ có precision hoặc thiếu USD source scale: modes rỗng hoặc
   `dynamic_payout_{source,destination}_amount_contract_unverified`. Cần raw
   HTTP-200 body từ đúng client/customer/currency/payout/schema, đúng destination
   country/method, toàn bộ properties/required/allOf/refs/precision và dialect.
   Giữ nguyên validation content, hashes, timestamp/request provenance; redact
   secrets và identifiers nhất quán. Nếu schema thực tế có cấu trúc ngoài subset,
   cần chính cấu trúc đó cùng semantics từ Nium trước khi mở rộng parser.
3. Cần applicability evidence cho Spend `/remittance/validate` và acceptance
   response của đúng registered-beneficiary request, cho từng amount mode định
   dùng: exact request/response bytes/hash, HTTP status, scope, quote và thời điểm.
   Có thể dùng non-settling validation theo quy trình được phép; task này không
   gọi endpoint đó. Không dùng POST payout thật làm phép thử payload.
4. Sau khi evidence hợp lệ vẫn giữ quote expiry, consent fingerprint, beneficiary/
   USD wallet/customer/currency binding, financial authorization và deployment
   gates hiện có. Chưa kiểm tra cấu hình live hoặc trạng thái database Production.

Files sửa và regression

- `app/Services/Nium/DynamicConfirmation/DynamicPayoutEligibility.php`: guard và
  amount-mode evidence như trên.
- `tests/Unit/NiumDynamicPayoutSchemaTest.php`: conditional amount candidates,
  precision-only refusal; giữ malformed/unsupported schema, origin, tuple và
  unconditional opposite-amount coverage.
- `tests/Feature/NiumDynamicPayoutQuoteApiTest.php`: fixture có explicit synthetic
  amount requirements; Spend-shaped schema/validation/wallet mocks; cả hai modes
  đi qua Review → Confirm → refresh → một dispatch dù retry, exact validated-body
  hash; Nium rejection không tạo consent; precision-only schema không cấp mode.
  Các regression existing wrong beneficiary/wallet/currency, expiry, consent
  mismatch, retry/timeout/reconciliation vẫn giữ nguyên.
- File báo cáo này.

Đã chạy duy nhất static review, `git diff --check` và `php -l` cho ba PHP files
đã sửa: không lỗi syntax/whitespace. Không có kết quả PHPUnit PASS được tuyên bố.

Targeted command cho operator chạy sau, không được chạy trong task này. Feature
suites dùng disposable PostgreSQL và tự reset schema/chạy migrations qua test base;
chỉ dùng database test được guard chấp nhận, credentials test có sẵn trong môi trường.

```bash
APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=origin_wallet_auto02b_test DB_URL= \
php vendor/bin/phpunit --bootstrap tests/nium-transfer-offline-bootstrap.php \
  --fail-on-warning --fail-on-risky --fail-on-skipped \
  --filter 'NiumDynamicPayoutSchemaTest|NiumDynamicPayoutEligibilityTest|NiumDynamicCustomerCorridorsTest|NiumDynamicInstructionValidationTest|NiumDynamicPayoutQuoteApiTest|NiumDynamicPayoutExecutionTest|NiumDynamicPayoutTransportTest|NiumDynamicConfirmationPersistenceTest'
```
