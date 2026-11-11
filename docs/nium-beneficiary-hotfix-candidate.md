# Isolated NIUM beneficiary hotfix candidate

Prepared 2026-10-30 (Asia/Bangkok). This candidate contains only beneficiary method discovery, registered Edit protection, accurate Review fields, structured errors and their regression tests. It contains no uncommitted Phase 2 FX/Remittance, payout presentation or bidirectional-pricing work.

## Candidate locations and bases

| Repository | Isolated worktree | Branch | Unchanged starting HEAD |
| --- | --- | --- | --- |
| Backend | `/home/dtd1201/projects/api_origin_wallet_beneficiary_hotfix` | `fix/nium-sgd-beneficiary-workflow` | `5a737b5e8a4fda1d514326b3b686f2b92b1193d1` |
| Frontend | `/home/dtd1201/projects/origin-wallet-platform_beneficiary_hotfix` | `fix/nium-sgd-beneficiary-workflow` | `fc4a07435c7dd0040493e02c06edc97e1b92d105` |

Both candidates are intentionally uncommitted and unstaged. Original `main` working trees retain their original file contents, tracked/untracked status, HEADs and staging entries. Worktree registration and the two branch references are the only intended shared Git metadata additions.

## Production evidence and scope

The Technical Lead supplied new Production evidence: SG/SGD/LOCAL validation-schema GET HTTP 200, 11 regex patterns, zero unsupported patterns, `REQUIREMENTS_OK=YES`, and 24 normalized fields. This supersedes the earlier investigation's uncertainty about schema compatibility. It does not require a regex change. The reported response itself was not supplied as a sanitized test fixture; local SG flow tests remain explicitly synthetic rather than claiming to reproduce that response.

The regex adapter, JSON Schema normalizer and preflight validator are byte-identical to committed HEAD. The SGD payout gate is also byte-identical to HEAD, including the unconditional throw in `assertSubmissionAllowed()`. No submission capability, FX flag or financial contract is opened by creating a beneficiary.

## Exact backend candidate files

| File | Included behavior |
| --- | --- |
| `app/Http/Controllers/Api/User/BeneficiaryController.php` | Read-only payout-method discovery endpoint; structured 422/502 errors; locks and checks registered corridor before updating. |
| `app/Http/Resources/User/BeneficiaryResource.php` | **Only** `is_registered`; no payout-admission import or projection. |
| `app/Services/Nium/NiumBeneficiaryService.php` | Read-only method discovery using existing account eligibility and corridor services. |
| `app/Services/Nium/NiumSupportedCorridorService.php` | Discover supported LOCAL/SWIFT bank methods with existing exact matcher/cache; distinguish unmatched corridor using a `RuntimeException` subclass. Existing matching rules stay intact. |
| `app/Services/Nium/NiumUnsupportedBeneficiaryCorridor.php` | New exception needed by discovery and structured errors. |
| `routes/user.php` | GET `/api/user/users/{user}/beneficiaries/payout-methods`, before the dynamic beneficiary route. |
| `tests/Feature/NiumBeneficiaryLifecycleTest.php` | Two successful-edit fixtures explicitly establish the saved payout method. |
| `tests/Feature/NiumSgdBeneficiaryWorkflowTest.php` | New discovery, LOCAL/SWIFT routing, mock creation, field validation, immutable Edit, ownership, error and CORS regressions. |
| `docs/nium-beneficiary-hotfix-candidate.md` | This review report and exact user-executed staging/commit commands. |

Registered edits reject country, currency, legal type, provider or effective payout-method changes. If the registered method cannot be established from the saved method or matching successful provider-request evidence, editing is blocked; client raw metadata cannot establish it. Provider changes are also rejected for an unregistered edit. No registered update is silently converted into replacement creation.

## Exact frontend candidate files

| File | Included behavior |
| --- | --- |
| `src/pages/account/AccountBeneficiaries.tsx` | Discover methods before requesting requirements; prefer verified LOCAL for new SG/SGD Individual; preserve HK/USD/SWIFT; protect registered edits; review final submitted country/currency/method/account/routing; separate error messages. |
| `src/pages/account/beneficiaryPayoutMethods.ts` | New discovery-response binding and structured error-message helpers. |
| `src/pages/account/AccountBeneficiaries.sgd.test.tsx` | New SG create/review, independent SWIFT routing, supported defaults, Edit protection, unavailable/mismatched metadata and retry regressions. |
| `src/pages/account/AccountBeneficiaries.corridor.test.tsx` | Existing wizard tests use authoritative discovery and await its additional read-only stage. |
| `src/pages/account/AccountBeneficiaries.updateFailure.test.tsx` | Discovery mock for existing update-failure regression. |
| `src/services/moneyMovementService.ts` | **Only** `is_registered`, `NiumBeneficiaryPayoutMethods` and its GET function; no payout-admission or payout-amount types. |

For SG/SGD/LOCAL, the payout method is LOCAL and the bank routing type remains independently SWIFT. Unsupported or unavailable metadata prevents review/saving. HK/USD keeps its supported SWIFT default and existing payload behavior. Registered edits retain their saved corridor. A network fetch failure is distinguished from an API schema/corridor error without claiming that status zero alone diagnoses CORS.

## Hunk separation and dependencies

Two mixed files were reconstructed from their respective committed HEADs:

1. Backend `BeneficiaryResource.php`: copied only the one-line `is_registered` addition. Excluded the `NiumSgdLocalPayoutGate` import and `payout_admission` projection.
2. Frontend `moneyMovementService.ts`: copied only registration and method-discovery additions. Excluded `Beneficiary.payout_admission`, `Transfer.payout_amounts` and `NiumPayoutAmounts`.

All other included source/test files were copied intact from the existing beneficiary implementation. No runtime behavior was redesigned during isolation.

Required runtime dependencies already exist at the two base commits: `NiumBeneficiaryPayoutMethodResolver`, `NiumProviderAccountStateService`, `NiumBeneficiaryRequirementsService`, `NiumBeneficiaryPreflightValidator`, `NiumBeneficiarySchemaPattern`, `PrimaryProvider`, authenticated user routes, the requirements API, `requestApi`, React Query and the beneficiary requirements helpers. No package, lockfile, migration, config or environment change is needed. The frontend requires the backend's new discovery route and registration flag; an eventual reviewed release must make the backend available before switching to this frontend. Existing update entitlement restrictions remain unchanged.

## Original work excluded and preserved

The following original modifications/untracked files were not copied into either candidate. All remain untouched in the original trees:

Backend:

- `app/Http/Resources/User/TransferResource.php`
- `app/Services/Nium/NiumPayoutFxLockService.php`
- `app/Services/Nium/NiumSgdLocalPayoutGate.php` (uncommitted admission projection excluded; committed hard hold retained)
- `app/Services/Nium/NiumTransferService.php`
- `app/Services/Nium/NiumPayoutFxLockContract.php`
- `app/Services/Nium/NiumSgdLocalPayoutAmounts.php`
- `tests/Feature/NiumPayoutFxSafetyTest.php`
- `tests/Feature/NiumQuoteServiceTest.php`
- `tests/Feature/NiumTransferServiceTest.php`
- `tests/Feature/NiumPayoutFxLockContractTest.php`
- `tests/Feature/NiumSgdLocalPayoutPresentationTest.php`
- `tests/Fixtures/nium/sg-sgd-local-phase2-evidence.json`
- `docs/nium-payout-bidirectional-pricing-inspection.md`
- `docs/nium-sg-sgd-local-phase2.md`
- `docs/nium-sg-sgd-local-beneficiary-workflow.md` (historical report left unchanged; this candidate report uses the newer supplied Production evidence)

Frontend:

- `src/pages/account/AccountTransfers.tsx`
- `src/pages/account/AccountTransfers.payeeList.test.tsx`
- `src/pages/account/transferForm.ts`
- `src/pages/account/transferForm.test.ts`
- `src/pages/account/SgdLocalAmountBreakdown.tsx`
- `src/pages/account/SgdLocalAmountBreakdown.test.tsx`

The excluded hunks in the two shared files also remain in the original trees. No file, hunk or untracked investigation was removed, reset, stashed or staged there.

## Validation performed in isolated candidates

Dependencies were copied into each candidate, with no dependency installation or network calls. PHP validation asserts that application/test classes resolve inside the candidate, not the original checkout. No `.env` was copied. Backend tests use SQLite in memory, mocked provider responses and a stray-HTTP guard.

| Check | Result |
| --- | --- |
| Backend relevant regression suite | **323 tests, 2099 assertions: 322 pass, one pre-existing failure.** All beneficiary and SGD submission-hold tests pass. |
| Pre-existing backend failure | `CustomerTransferSecurityTest::test_transfer_list_and_detail_use_customer_safe_nested_resources`, line 167, expects nested beneficiary `raw_data` absent. Reproduced separately from an archive of committed backend HEAD: 1 test, 13 assertions, same failure. The candidate only adds `is_registered` to that resource. |
| Frontend full suite | **22 files, 255 tests passed.** These counts exclude the unrelated Phase 2 payout additions. |
| PHP syntax | All eight candidate PHP source/route/test files passed `php -l`. |
| Pint | All eight candidate PHP files passed targeted `--test`. |
| ESLint | Pass: no errors, 22 existing warnings. |
| TypeScript | Six pre-existing diagnostics: KYC union access at `AccountKyc.tsx:1369/1371`; ES2020 `replaceAll` at `AccountRfi.tsx:40/47`; unchanged request-payload index signatures at `moneyMovementService.ts:221/248`. An archive of frontend committed HEAD produces identical diagnostics after line-number normalization. |
| Production build/prerender | Passed with existing Browserslist freshness and bundle-size warnings. |
| Isolation | Candidate path allowlists, empty staging indexes, unchanged payout/regex files and original tree hashes/status/HEAD/index entries verified. `git diff --check` passes in both candidates. |

Backend files exercised: `NiumSgdBeneficiaryWorkflowTest`, `NiumBeneficiaryServiceTest`, `NiumBeneficiaryLifecycleTest`, `NiumBeneficiaryProductionSchemaTest`, `NiumBeneficiaryRequirementsServiceTest`, `ReconcileNiumBeneficiaryPayoutMethodsTest`, `NiumSgdLocalPayoutGateTest`, `NiumPayoutFxSafetyTest`, `NiumTransferServiceTest`, `NiumPayoutFxLockServiceTest`, `NiumTransferKycProfileStatusTest`, `ReconcileNiumTransfersCommandTest` and `CustomerTransferSecurityTest`.

Commands used: `php vendor/bin/phpunit --bootstrap /tmp/nium-beneficiary-isolated-offline-bootstrap.php` followed by the thirteen test-file paths above; targeted `php vendor/bin/pint --test`; `npm run test`; `npm run lint`; `npx --no-install tsc --noEmit -p tsconfig.app.json`; `npm run build`. Logs are `/tmp/nium-beneficiary-isolated-{backend,frontend,pint,lint,types,build}.log`; baseline logs are `/tmp/nium-beneficiary-isolated-baseline-{backend,types}.log`.

## Remaining deployment blockers and limits

The isolated candidate is ready for Technical Lead review; nothing is staged or committed. Release acceptance must explicitly address the reproduced pre-existing backend test failure and six TypeScript diagnostics, since the complete validation is not entirely green. They were not fixed as part of beneficiary isolation. The supplied successful Production normalization means the previously suspected regex incompatibility is not a current blocker and no speculative adapter change is included.

The sanitized SG response is not in this candidate's fixtures, so local SG tests demonstrate workflow/routing contracts rather than reproduce all 24 Production fields. No real beneficiary creation/update or browser Production verification was performed under this task's restrictions. Existing CORS behavior has local error/preflight coverage; no global CORS change is proposed. The new backend discovery endpoint is a deployment dependency for the frontend. SGD real-money submission remains independently blocked by the unchanged gate and requires its separate contract review.

## User-executed staging and commit commands after review

These commands were **not** executed. Run each block only in its stated isolated worktree after reviewing the candidate. The explicit file lists exclude all unrelated work.

Backend:

```bash
cd /home/dtd1201/projects/api_origin_wallet_beneficiary_hotfix
git status --short
git add -- \
  app/Http/Controllers/Api/User/BeneficiaryController.php \
  app/Http/Resources/User/BeneficiaryResource.php \
  app/Services/Nium/NiumBeneficiaryService.php \
  app/Services/Nium/NiumSupportedCorridorService.php \
  app/Services/Nium/NiumUnsupportedBeneficiaryCorridor.php \
  routes/user.php \
  tests/Feature/NiumBeneficiaryLifecycleTest.php \
  tests/Feature/NiumSgdBeneficiaryWorkflowTest.php \
  docs/nium-beneficiary-hotfix-candidate.md
git diff --cached --check
git diff --cached --stat
git diff --cached
git commit -m "fix(nium): correct SGD Local beneficiary workflow"
```

Frontend:

```bash
cd /home/dtd1201/projects/origin-wallet-platform_beneficiary_hotfix
git status --short
git add -- \
  src/pages/account/AccountBeneficiaries.tsx \
  src/pages/account/beneficiaryPayoutMethods.ts \
  src/pages/account/AccountBeneficiaries.sgd.test.tsx \
  src/pages/account/AccountBeneficiaries.corridor.test.tsx \
  src/pages/account/AccountBeneficiaries.updateFailure.test.tsx \
  src/services/moneyMovementService.ts
git diff --cached --check
git diff --cached --stat
git diff --cached
git commit -m "fix(ui): discover supported NIUM beneficiary payout methods"
```

No live NIUM calls, provider/beneficiary writes, FX locks, money movement, Production/database/config changes, staging, commits, pushes or deployment were performed. Mocked beneficiary writes and database creation occurred only inside isolated tests.
