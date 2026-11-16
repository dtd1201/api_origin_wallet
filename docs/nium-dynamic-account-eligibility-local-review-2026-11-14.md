# Dynamic account eligibility — operator policy, 2026-11-14

Bản này thay kết luận trước đó sau quyết định vận hành mới của operator: account
Nium active/CLEAR, không substatus, RFI đã giải quyết, reconciled và verified IDs
không bị từ chối chỉ vì observation compliance/ODD nội bộ là NULL hoặc unknown.
Đây là chính sách Origin cho Dynamic, không phải xác nhận Nium cấp mọi corridor.
Không thay giá trị database thành clear và không tạo compliance event giả.

## Root cause và hành vi trước/sau

Projector account tính provider, compliance và ODD độc lập. Account có thể active
và reconciled khi compliance NULL, ODD unknown. Hai guard Dynamic trước đây yêu
cầu compliance clear/approved và ODD clear/not_required, vì vậy cả Quote và locked
Confirmation từ chối trường hợp account 6/8 do operator cung cấp. Không truy vấn
Production để xác minh hoặc thay đổi các tài khoản này.

`DynamicAccountEligibility::permitsAuxiliaryObservations()` là predicate dùng
chung mới, chỉ xét hai observation phụ trợ:

| Observation | Trước | Sau |
| --- | --- | --- |
| Compliance NULL/unknown | BLOCK | Không chặn riêng vì observation này |
| ODD NULL/unknown | BLOCK | Không chặn riêng vì observation này |
| Compliance clear/approved | Cho phép qua predicate | Giữ nguyên |
| ODD clear/not_required | Cho phép qua predicate | Giữ nguyên |
| action_required, rejected, blocked, suspended, hold, odd_due, pending, failed hoặc giá trị không được hỗ trợ | BLOCK | BLOCK |

Đây là allowlist chính xác, không bỏ toàn bộ kiểm tra compliance/ODD và không
suy diễn NULL/unknown thành clearance. Chỉ thêm NULL/unknown; completed,
odd_not_due và odd_ineligible vẫn không được thêm vào allowlist. AML/RFI evidence
độc lập luôn được kiểm tra tại locked admission/execution boundary.

Projector/webhook dùng chung không đổi; enum vocabulary và provenance vẫn được
lưu như trước. Chính sách này đánh giá trạng thái đã persist, không khôi phục
được giá trị gốc nếu quá trình projection trước đó đã chuyển nó thành unknown.

## Đồng bộ các lớp Dynamic

| Lớp | Đường kiểm tra |
| --- | --- |
| Transfer Options đang đăng ký route | NiumDynamicPayoutController::options → Review availability → Quote discovery → binding → predicate mới. Options vẫn chỉ cho phép acquisition, không tự cấp quyền execution. |
| Quote | Quote binding dùng predicate mới cùng các guard account/KYC/registration/ownership/wallet trước đó. |
| Review | Quote assertCurrent trước/sau provider observation; validateReview gọi locked assertLocalEligibility dùng cùng predicate. |
| Confirm | assertLocalEligibility dùng predicate mới; giữ AML screening/matches, RFI cases, evidence, consent, balance và reservation checks. |
| Automatic Dispatch | revalidateForExecution gọi cùng assertLocalEligibility trước claim và trong beginWire ngay trước gửi; provider evidence/reservation/request binding vẫn được kiểm tra lại. |
| Reconciliation | Không dùng allowlist compliance/ODD này. Giữ authenticated settlement matching, terminal refusal/return, discrepancy và reservation/ledger integrity. Không ngừng đối soát tiền đã gửi vì observation phụ trợ thiếu. |

Không sửa controller Transfer Options cũ chưa đăng ký route, frontend/API contract,
shared account/projector, HK SWIFT hoặc SGD LOCAL cũ. Không thêm Admin Approve.

## Bảo vệ giữ nguyên và các blocker khác cho account 6/8

- User/KYC profile hợp lệ; AML screening hiện hành hoàn tất, đúng KYC profile,
  decision clear và không có match chưa cleared.
- Provider/account active, provider clear và substatus rỗng; RFI account thuộc
  allowlist cũ và không có RFI case chưa được giải quyết có thẩm quyền.
- Reconciled, không security conflict, customer/wallet ID verified, timestamps
  hợp lệ và chính xác ownership/provider/wallet/external account binding.
- Beneficiary hợp lệ, registration request/response/country/currency/method,
  customer scope và SHA-256 hợp lệ. Listing không thay customer entitlement,
  legal entity, payout schema/amount mode hoặc instruction validation.
- Provider endpoint/environment contract, fresh observations, Quote/Review/Consent
  binding và expiry. Thiếu quote hoặc thay terms vẫn bị từ chối.
- Số dư USD, debit theo consent, contracted Nium transaction fee USD20, FX quote,
  fee ownership và không ghi nhận Origin revenue giữ nguyên.
- Wallet không có payout exception chưa giải quyết; reservation/ledger integrity,
  quote consumption, idempotency và no duplicate dispatch giữ nguyên.
- Feature/config gates hiện hành, provider refusal, financial reconciliation
  discrepancies/returns vẫn có thể chặn. Không thay đổi flag nào.

Vì vậy hai observation thiếu không còn là blocker riêng; các dữ kiện account
operator cung cấp chưa chứng minh tất cả các điều kiện payout còn lại đã đạt.

## File thay đổi và regression

Production code:

1. Thêm app/Services/Nium/DynamicConfirmation/DynamicAccountEligibility.php.
2. NiumDynamicPayoutQuoteService.php: dùng predicate chung thay hai allowlist cũ.
3. DynamicTransferConfirmationService.php: cùng thay đổi tại assertLocalEligibility,
   được dùng lại cho validateReview, Confirm và hai execution checkpoints.

Tests:

1. NiumDynamicPayoutQuoteApiTest.php: chuyển missing-data case khỏi nhóm từ chối;
   thêm bốn E2E cases account 6/8-like cho USD→SGD/USD→GBP, cả hai amount modes,
   từ Options đến reconciliation. Giữ duplicate confirmation/dispatch checks,
   exact debit, fee ownership và xác nhận database vẫn NULL/unknown.
   Nhóm regression trước đó nay kiểm tra explicit restrictions tại Options/Quote,
   Review, Confirm và Dispatch; thêm AML/RFI holds trước claim/ngay trước wire.
2. NiumDynamicConfirmationPersistenceTest.php: bỏ NULL/unknown khỏi negative cases;
   kiểm tra các account/binding/beneficiary/provider/RFI rejection với missing
   auxiliary observations, và AML/RFI holds vẫn rollback toàn bộ admission.
3. Thêm NiumDynamicAccountEligibilityTest.php: các tổ hợp missing observations,
   existing clear/approved/not_required, explicit restrictions và unsupported values.

Provider HTTP/settlement trong tests là synthetic. Chỉ kiểm tra tĩnh PHP syntax và
whitespace; không chạy tests/builds/migrations, bootstrap ứng dụng hoặc gọi Nium.
Không commit/push/deploy hoặc sửa Production. Chưa có runtime PASS cho bản sửa này.

## Lệnh operator

Từ backend root, dùng credentials PostgreSQL test đã cấu hình. Suite hiện có reset
schema của LOCAL disposable database origin_wallet_auto02b_test; chạy một runner:

```sh
APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=origin_wallet_auto02b_test DB_URL= ./vendor/bin/phpunit --bootstrap tests/nium-transfer-offline-bootstrap.php --fail-on-warning --fail-on-risky --fail-on-skipped --filter 'NiumDynamic(AccountEligibility|PayoutQuoteApi|ConfirmationPersistence|PayoutExecution)Test'
```
