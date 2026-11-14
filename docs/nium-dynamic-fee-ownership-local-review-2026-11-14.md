# Nium Dynamic Multi-Currency — fee ownership, Local review, 2026-11-14

Đã sửa Backend Local `api_origin_wallet` và Frontend Local `origin-wallet-platform`.
Không chạy tests/builds/migrations, gọi Nium, sửa Production data, bật flag,
commit/push/deploy. Các thay đổi có sẵn trong workspace được giữ lại.

## Kết luận và bằng chứng

Bằng chứng do người dùng cung cấp: **Nium Local Currency Pay Outs – Non-Tiered
Pricing** quy định Transaction Fee USD20 cho chín corridor LOCAL đã khai báo.
USD20 là **Nium transaction fee**, không phải Origin/platform fee hoặc doanh thu
Origin. FX margin cũng thuộc Nium: HK/SG/GB/DK/DE 0.13%; JP/TH 0.28%; KR 0.44%;
CH 0.30%. Giữ nguyên các tuple/currency hiện có trong bảng, kể cả DK/CH → EUR.

Đây là bằng chứng phân loại và mức phí theo hợp đồng. Nó không chứng minh mức phí
đã thu trong từng payout, thời điểm thu, tài khoản bị trừ, cách invoice, hay mapping
`REMIT_BANK_FEE` sang transaction fee trong hợp đồng. Không có thêm raw provider
settlement evidence trong công việc này. Provenance hiện có vẫn giữ
`document_sha256 = null` và `signature_independently_verified = false`.

## Các vị trí sai và logic trước/sau

| Vị trí | Trước | Sau |
| --- | --- | --- |
| `DocumentaryFeeSchedule` | Chín mức phí/margin đúng số nhưng thiếu ownership rõ ràng. | Metadata LOCAL xác định `fee_owner=NIUM`, `fee_type=TRANSACTION_FEE`, `fee_basis=CONTRACT_SCHEDULE`, `fx_margin_owner=NIUM`, `fee_collection=UNVERIFIED`, `origin_revenue=NOT_RECOGNIZED`. Không đổi các hàng phí hoặc SWIFT references. |
| `DynamicPayoutFinancialAuthorization::pricing()` | `origin_service_fee_usd=20`, pricing v3. | `contracted_nium_transaction_fee_usd=20`, metadata ownership, pricing `nium-contracted-local-usd-inclusive-v4`. Không tạo provider fee thực tế từ USD20. |
| `NiumDynamicPayoutQuoteService::project()` | API `origin_service_fee`. | API `contracted_nium_transaction_fee`. Các field principal, planned fee/debit và tỷ giá giữ nguyên. |
| `NiumDynamicReviewService` | Review v4 lưu/project/so sánh Origin fee. | Review v5 lưu/project/so sánh Nium contractual fee và toàn bộ pricing metadata; thay đổi owner/margin owner/revenue dù cùng số tiền vẫn bị từ chối. |
| `FinalizedUsdPayoutPreparation`, `PayoutConfirmation` | Preparation v4, consent chưa có fee classification. | Preparation v5 thêm classification vào binding/fingerprint bất biến của confirmation. Chỉ exact contracted LOCAL tuple có classification; ngoài scope trả `null`. Không đổi generic FinancialTerms hoặc công thức. |
| `DynamicReservationLedger` | Chỉ hold/release/debit của customer wallet; không thấy posting doanh thu. | Giữ nguyên posting và số tiền; raw metadata lưu classification từ consent và `origin_revenue_recognized=false`. Không tự tạo income, expense hoặc payable từ hợp đồng chưa có settlement evidence. |
| `DynamicPayoutAccounting`, `DynamicPayoutReconciler` | Correlate remittance và Fee_Debit; missing fee → pending; mismatch → exception và consume đúng consent. | Giữ nguyên đánh giá, correlation, posting và xử lý discrepancy. Receipt bổ sung classification hợp đồng, đánh dấu không ghi nhận Origin revenue; từng fee record vẫn giữ nguyên feeName/currency/amount và ghi provider nguồn là NIUM. Không đồng nhất mọi Fee_Debit với contractual transaction fee. Reconciler không cần sửa. |
| `DynamicPayoutCustomerStatus` | Chỉ trả included fee từ consent. | Trả thêm classification từ consent và `provider_fee_observation` riêng từ receipt: missing → UNVERIFIED/null; fee rows có bằng chứng settled → đúng amount/currency quan sát được, kể cả 0 hoặc khác USD20. |
| Frontend service/types, `dynamicPaymentTerms` | Field Origin fee và chỉ so sánh số tiền/pricing. | Dùng field Nium fee; kiểm tra owner/type/basis/margin owner/revenue, pricing version và tính nhất quán Quote/Review/Transfer. Không nhận ownership Origin cho consent mới. |
| `DynamicPaymentSteps` | “Included Origin fee” ở Quote, Review, summary. | “Included Nium transaction fee (contract)”; settlement fees hiển thị riêng từ provider observation, thiếu thì unverified. Giao dịch lịch sử thiếu classification ghi ownership unverified. Không tính FX/fee mới ở UI. |

Không tìm thấy code mặc định hạch toán USD20 thành Origin revenue trong Dynamic
ledger, accounting hoặc reconciliation đã rà soát. Các chỗ gọi sai là field API,
metadata pricing, UI và fixtures/assertions tương ứng. `NiumSgdLocalZeroFeePolicy`
có `origin_fee_amount=0` trong policy SGD cũ; đây không phải Dynamic USD20 và không sửa.

Các báo cáo cũ có diễn giải Origin fee: backend
`docs/nium-dynamic-backend-local-finalization-2026-11-13.md`,
`docs/nium-dynamic-final-integration-report.md`,
`docs/nium-dynamic-finalization-implementation-report.md`; frontend
`docs/dynamic-multi-currency-frontend-integration-review-2026-11-13.md`.
Phần phân loại USD20 là Origin fee trong các báo cáo này được thay thế bởi báo cáo
này; giữ nguyên nội dung cũ làm lịch sử audit, không dùng làm căn cứ fee ownership.

## Exact debit và blocker provider settlement

**Chưa đủ bằng chứng để phê duyệt cơ chế thu phí thực tế cho live use.** Không tự
thay đổi công thức để lấp khoảng trống bằng chứng. Phép tính hiện có được giữ như
allocation kế hoạch/customer consent, không xác nhận đó là provider wallet debit:

- SOURCE_AMOUNT: customer nhập USD1000 → quote principal USD980, contractual fee
  allocation USD20, planned customer debit USD1000.
- DESTINATION_AMOUNT: Nium quote USD principal P → planned debit P + USD20.
- Dùng đúng destination amount và net exchange rate trong response Nium; không
  nhân/trừ thêm 0.13%, 0.28%, 0.44% hoặc 0.30% vào quote.

Để xác nhận mô hình debit, cần đủ bằng chứng có scope client/customer/wallet,
agreement/fee-plan hiệu lực, corridor và currency:

1. Raw quote request/response và remittance request/response; xác định ý nghĩa
   sourceAmount/settlementAuthAmount/effectiveAuthAmount, tránh cộng phí hai lần.
2. Authenticated transaction/settlement records của cùng payout: authCode,
   originalAuthorizationCode, Fee_Debit, feeName, currency, amount, debit direction,
   settlement status và timestamp; các trang dữ liệu phải đầy đủ.
3. Tài liệu/xác nhận Nium ánh xạ transaction fee hợp đồng sang feeName thực tế và
   xác định thu cùng remittance, tách fee debit, invoice sau hay tài khoản khác.
4. Wallet balance/statement trước/sau và invoice nếu có; xác định không có phí
   thu thêm, thu hai lần, thu currency khác hoặc khấu trừ recipient amount.
5. Bằng chứng áp dụng đúng account/corridor, gồm USD→SGD và USD→GBP ở cả hai amount
   modes; xác nhận tỷ giá quote đã chứa margin Nium, cùng quy tắc precision/rounding.

Phương án đối soát giữ nguyên: chỉ cộng từng settled debit đã correlate; không cộng
`effectiveAuthAmount` thêm lần nữa. Missing Fee_Debit không suy thành phí 0, không
suy rằng remittance debit đã bao gồm fee; giữ pending/hold và thu thập chứng từ.
Fee/currency/principal/recipient amount/rate khác consent giữ discrepancy/exception
và yêu cầu review; không tăng customer debit, không dispatch lại, không tự book
chênh lệch dương thành doanh thu Origin. Nếu phí thu qua invoice/embedded/khác tài
khoản được chứng minh, cần mapping và policy riêng trước khi hỗ trợ; patch này
không suy diễn hoặc tự bật mô hình đó. Phần tiền chưa giải thích cần policy payable,
refund hoặc xử lý chênh lệch có bằng chứng, không được mặc định là lợi nhuận.

## Compatibility, legacy và regression

API đổi tên có chủ đích, không giữ alias mang tên Origin fee. Backend/Frontend
Local đã cập nhật đồng bộ. Pricing/preparation/review version mới thay fingerprint;
quote/review cũ cần quote/consent mới. Không sửa snapshot, ledger hoặc records đã
lưu; lịch sử thiếu classification vẫn là unknown khi đọc.

SGD LOCAL cũ (`NiumSgdLocalZeroFeePolicy`, SGD standard transfer/presentation) và
HK SWIFT không dùng classification/mapping LOCAL mới. Không sửa file workflow,
fee policy, flags hoặc routing của hai lane đó. Documentary SWIFT SHA/OUR và các
reference LOCAL/WIRE ngoài scope giữ nguyên.

Đã bổ sung/cập nhật regression cho chín hàng hợp đồng và ownership; không cộng FX
margin hai lần; snapshot/ledger giữ classification, không revenue posting; consent
cũ/ownership bị đổi bị từ chối; USD→SGD/GBP và hai modes qua Quote/Review/Confirm/
Dispatch/reconciliation. Có cases fee missing, explicit zero, USD10/21/30, fee EUR,
exact customer debit và idempotent posting; UI tách hợp đồng khỏi actual observation.

Chỉ kiểm tra tĩnh: PHP syntax, TypeScript AST parsing không emit và `git diff
--check`. Không chạy PHPUnit/Vitest, type checking toàn dự án hoặc builds; chưa
khẳng định regression PASS. Operator có thể chạy sau khi cho phép, trong môi
trường offline testing hiện có:

```bash
vendor/bin/phpunit --filter 'Nium(SignedPayoutFeeSchedule|FinalizedUsdPayoutPreparation|FinalizedUsdConfirmationIntegration|DynamicPayoutFinancialAuthorization|DynamicPayoutAccounting|DynamicPayoutCustomerStatus|DynamicPayoutQuoteApi|DynamicPayoutExecution)Test' --fail-on-risky
npx vitest run src/pages/account/dynamicPaymentTerms.test.ts src/pages/account/useDynamicPayment.test.tsx src/pages/account/AccountDynamicTransfer.test.tsx
```

Trước live use vẫn cần giải quyết blocker fee collection phía trên; tests tổng
hợp/synthetic không thay thế bằng chứng thu phí Nium thực tế.

## Danh sách file đã sửa trong lượt này

Các đường dẫn và dòng bắt đầu thay đổi được liệt kê bên dưới; không bao gồm những
thay đổi có sẵn trước khi rà soát fee ownership.

| Repo / file | Dòng bắt đầu thay đổi | Loại |
| --- | ---: | --- |
| `api_origin_wallet/app/Domain/Nium/DynamicMultiCurrency/Financial/DocumentaryFeeSchedule.php` | 9 | application code |
| `api_origin_wallet/app/Domain/Nium/DynamicMultiCurrency/Financial/FinalizedUsdPayoutPreparation.php` | 15 | application code |
| `api_origin_wallet/app/Domain/Nium/DynamicMultiCurrency/Financial/PayoutConfirmation.php` | 127 | application code |
| `api_origin_wallet/app/Services/Nium/DynamicConfirmation/DynamicPayoutAccounting.php` | 207 | application code |
| `api_origin_wallet/app/Services/Nium/DynamicConfirmation/DynamicPayoutCustomerStatus.php` | 52 | application code |
| `api_origin_wallet/app/Services/Nium/DynamicConfirmation/DynamicPayoutFinancialAuthorization.php` | 12 | application code |
| `api_origin_wallet/app/Services/Nium/DynamicConfirmation/DynamicReservationLedger.php` | 226 | application code |
| `api_origin_wallet/app/Services/Nium/NiumDynamicPayoutQuoteService.php` | 219 | application code |
| `api_origin_wallet/app/Services/Nium/NiumDynamicReviewService.php` | 101 | application code |
| `api_origin_wallet/tests/Feature/NiumDynamicPayoutExecutionTest.php` | 518 | regression/fixture |
| `api_origin_wallet/tests/Feature/NiumDynamicPayoutQuoteApiTest.php` | 287 | regression/fixture |
| `api_origin_wallet/tests/Feature/NiumFinalizedUsdConfirmationIntegrationTest.php` | 77 | regression/fixture |
| `api_origin_wallet/tests/Unit/NiumDynamicPayoutAccountingTest.php` | 7 | regression/fixture |
| `api_origin_wallet/tests/Unit/NiumDynamicPayoutCustomerStatusTest.php` | 7 | regression/fixture |
| `api_origin_wallet/tests/Unit/NiumDynamicPayoutFinancialAuthorizationTest.php` | 38 | regression/fixture |
| `api_origin_wallet/tests/Unit/NiumFinalizedUsdPayoutPreparationTest.php` | 117 | regression/fixture |
| `api_origin_wallet/tests/Unit/NiumSignedPayoutFeeScheduleTest.php` | 27 | regression/fixture |
| `origin-wallet-platform/src/pages/account/AccountDynamicTransfer.test.tsx` | 27 | regression/fixture |
| `origin-wallet-platform/src/pages/account/DynamicPaymentSteps.tsx` | 58 | application code |
| `origin-wallet-platform/src/pages/account/dynamicPaymentTerms.test.ts` | 6 | regression/fixture |
| `origin-wallet-platform/src/pages/account/dynamicPaymentTerms.ts` | 1 | application code |
| `origin-wallet-platform/src/pages/account/useDynamicPayment.test.tsx` | 15 | regression/fixture |
| `origin-wallet-platform/src/services/niumDynamicService.ts` | 6 | application code |
| `api_origin_wallet/docs/nium-dynamic-fee-ownership-local-review-2026-11-14.md` | 1 | báo cáo mới |
