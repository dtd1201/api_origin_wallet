<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\ApiToken;
use App\Models\NiumVirtualAccount;
use App\Models\NiumVirtualAccountDetail;
use App\Models\NiumVirtualAccountOperation as Operation;
use App\Models\User;
use App\Services\Nium\NiumVirtualAccountAssignmentContract;
use App\Services\Nium\NiumVirtualAccountDetailService;
use App\Services\Nium\NiumVirtualAccountRequestIdentity;
use App\Services\Nium\NiumVirtualAccountSafetyLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesNiumVirtualAccountLedgerFixtures;
use Tests\TestCase;
use Throwable;

class NiumVirtualAccountDetailServiceTest extends TestCase
{
    use CreatesNiumVirtualAccountLedgerFixtures;

    /** @var list<Request> */
    private array $completedHttpRequests = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.nium_details_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('nium_details_test');
        $this->artisan('migrate', ['--database' => 'nium_details_test', '--force' => true])->assertSuccessful();
        $this->configureLedgerTarget();
        config([
            'services.nium.payment_ids_endpoint' => '/api/v2/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/paymentIds',
            'services.nium.auth' => ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'synthetic-key'],
            'services.nium.webhook.static_header_name' => 'x-partner-key',
            'services.nium.webhook.static_header_value' => 'synthetic-partner-key',
        ]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        // Replacing a fake must not discard the no-POST audit for earlier phases.
        foreach ($this->completedHttpRequests as $request) {
            $this->assertSame('GET', $request->method());
        }
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
        DB::purge('nium_details_test');
        parent::tearDown();
    }

    #[DataProvider('versions')]
    public function test_documented_routing_pairs_and_provider_type_are_preserved_without_mapping(string $version): void
    {
        [$va, , $request] = $this->assigned($version);
        $assignment = $this->rowSnapshot($request);
        $parent = $this->rowSnapshot($va);
        $this->fakeHttp(fn () => Http::response($this->payload($va)));
        $detail = $this->sync($va);

        $this->assertSame('Synthetic Account Holder', $detail->account_name);
        $this->assertSame('JPM_SG', $detail->provider_bank_name);
        $this->assertSame('Synthetic Bank Display Name', $detail->bank_name);
        $this->assertSame('GLOBAL', $detail->provider_account_type);
        $this->assertSame('SELF_FUNDING_ACCOUNT', $detail->provider_account_category);
        $this->assertSame('SWIFT', $detail->routing_code_type);
        $this->assertSame('TESTSGSGXXX', $detail->routing_code_value);
        $this->assertSame('Intermediary Bank Code', $detail->routing_code_type_2);
        $this->assertSame('TESTGB2LXXX', $detail->routing_code_value_2);
        $this->assertSame('WIRES', $va->fresh()->account_type);
        $this->assertSame($parent, $this->rowSnapshot($va));
        $this->assertSame($assignment, $this->rowSnapshot($request));
        $snapshot = $request->identity()->snapshot();
        Http::assertSent(function (Request $outgoing) use ($snapshot, $va): bool {
            return $outgoing->method() === 'GET'
                && str_starts_with($outgoing->url(), 'https://nium.test/api/v2/client/'.$snapshot['client_id']
                    .'/customer/'.$snapshot['customer_id'].'/wallet/'.$snapshot['wallet_id'].'/paymentIds?')
                && $outgoing['uniquePaymentId'] === $va->provider_payment_id;
        });
        Http::assertSentCount(1);
        $log = ApiRequestLog::query()->where('operation', 'fetch_virtual_account_details')->firstOrFail();
        $this->assertNotNull($log->niumExchangeEvidence);
        $this->assertStringNotContainsString('TESTSGSGXXX', json_encode($log->response_body));
        $this->assertStringNotContainsString('TESTSGSGXXX', json_encode($log->niumExchangeEvidence->toArray()));
    }

    public static function versions(): array
    {
        return [['v1'], ['v2']];
    }

    public function test_routing_pair_two_keeps_its_actual_type_instead_of_an_intermediary_label(): void
    {
        [$va] = $this->assigned();
        $this->fakeHttp(fn () => Http::response($this->payload($va, [
            'routingCodeType2' => 'ABA (Wire)', 'routingCodeValue2' => '000000002',
        ])));
        $detail = $this->sync($va);
        $this->assertSame('ABA (Wire)', $detail->routing_code_type_2);
        $this->assertSame('000000002', $detail->routing_code_value_2);
        $this->assertArrayNotHasKey('intermediary_bank_code', $detail->toArray());
    }

    public function test_absent_secondary_routing_and_full_bank_name_are_not_invented(): void
    {
        [$va] = $this->assigned();
        $this->fakeHttp(fn () => Http::response($this->payload($va, [], [
            'routingCodeType2', 'routingCodeValue2', 'fullBankName',
        ])));
        $detail = $this->sync($va);
        $this->assertNull($detail->routing_code_type_2);
        $this->assertNull($detail->routing_code_value_2);
        $this->assertNull($detail->getRawOriginal('bank_name'));
        $this->assertSame('JPM_SG', $detail->bank_name);
        $this->assertSame('JPM_SG', $detail->toArray()['bank_name']);
        // A later response can add the full name without conflicting with a display fallback.
        $this->fakeHttp(fn () => Http::response($this->payload($va)));
        $enriched = $this->sync($va);
        $this->assertSame('Synthetic Bank Display Name', $enriched->bank_name);
        $this->assertSame('Intermediary Bank Code', $enriched->routing_code_type_2);
        $this->assertSame('TESTGB2LXXX', $enriched->routing_code_value_2);
    }

    public function test_first_insert_returns_persisted_nullable_columns_and_bank_display_fallback(): void
    {
        [$va] = $this->assigned();
        $this->fakeHttp(fn () => Http::response(['content' => [[
            'uniquePaymentId' => $va->provider_payment_id, 'bankName' => 'JPM_SG',
        ]]]));
        $detail = $this->sync($va);
        $this->assertNotNull($detail);
        // Inspect the returned object itself: reloading only in the test would mask
        // the original missing-column defect on the service's first return value.
        $this->assertSame($this->rowSnapshot($detail), $this->canonicalFields($detail->getAttributes()));
        $this->assertArrayHasKey('bank_name', $detail->getAttributes());
        $this->assertNull($detail->getRawOriginal('bank_name'));
        $serialized = $detail->toArray();
        $this->assertArrayHasKey('bank_name', $serialized);
        $this->assertSame('JPM_SG', $serialized['bank_name']);
        $this->assertNull($serialized['account_name']);
        $this->assertNull($serialized['routing_code_type_2']);
        $this->assertNull($serialized['routing_code_value_2']);
        $this->assertArrayNotHasKey('enrichment_version', $serialized);
    }

    #[DataProvider('changedTargets')]
    public function test_changed_frozen_target_is_rejected_before_get(string $field): void
    {
        [$va, $account] = $this->assigned();
        if ($field === 'client') {
            config(['services.nium.client_id' => 'changed-client']);
        } elseif ($field === 'origin') {
            config(['services.nium.base_url' => 'https://other-nium.test']);
        } elseif ($field === 'user') {
            $account->update(['user_id' => User::factory()->create()->id]);
        } else {
            $account->update([$field => (string) Str::uuid()]);
        }
        $this->fakeHttp();
        $this->rejects(fn () => $this->sync($va));
        Http::assertNothingSent();
        $this->assertSame(0, NiumVirtualAccountDetail::query()->count());
        $this->assertSame('assigned', $va->fresh()->status);
    }

    public static function changedTargets(): array
    {
        return [['client'], ['origin'], ['external_customer_id'], ['external_account_id'], ['user']];
    }

    #[DataProvider('contradictions')]
    public function test_contradictory_response_identity_is_rejected_without_losing_details(string $field, mixed $value): void
    {
        [$va] = $this->assigned();
        $detail = NiumVirtualAccountDetail::query()->create([
            'nium_virtual_account_id' => $va->id, 'account_name' => 'Already verified',
        ]);
        $stored = $this->rowSnapshot($detail);
        $this->fakeHttp(fn () => Http::response($this->payload($va, [$field => $value])));
        $this->rejects(fn () => $this->sync($va));
        $this->assertSame($stored, $this->rowSnapshot($detail));
        $this->assertSame('assigned', $va->fresh()->status);
    }

    public static function contradictions(): array
    {
        return [
            ['clientHashId', 'wrong-client'], ['customerHashId', 'wrong-customer'], ['walletHashId', 'wrong-wallet'],
            ['walletHashId', null], ['clientHashId', []], ['currencyCode', 'EUR'],
            ['accountCategory', 'COLLECTION_ACCOUNT'], ['bankName', 'DBS_HK'],
            ['virtualAccountNumber', 'DIFFERENT-PAYMENT-ID'], ['status', 'Inactive'],
        ];
    }

    public function test_envelope_target_contradiction_is_also_rejected(): void
    {
        [$va] = $this->assigned();
        $this->fakeHttp(fn () => Http::response($this->payload($va) + ['customerHashId' => 'wrong-envelope']));
        $this->rejects(fn () => $this->sync($va), 'target');
        $this->assertSame(0, NiumVirtualAccountDetail::query()->count());
    }

    public function test_terminal_proof_reader_rejects_a_different_resolution_fingerprint(): void
    {
        [, , $request] = $this->assigned();
        // Deliberately change only this in-memory object, never protected database history.
        $request->resolution_evidence_sha256 = str_repeat('0', 64);
        $this->rejects(fn () => app(NiumVirtualAccountSafetyLedger::class)->verifiedAssignmentResponse($request), 'original terminal');
        Http::assertNothingSent();
    }

    public function test_va_cannot_borrow_an_assigned_operations_proof_for_a_different_payment_id(): void
    {
        [$va] = $this->assigned();
        // Simulate an uncontrolled historical VA writer in this disposable fixture.
        $va->update(['provider_payment_id' => 'VA-UNRELATED']);
        $this->fakeHttp();
        $this->rejects(fn () => $this->sync($va), 'contradicts its assigned operation');
        Http::assertNothingSent();
        $this->assertSame(0, NiumVirtualAccountDetail::query()->count());
    }

    public function test_payment_id_mismatch_does_not_adopt_another_va_or_clear_stored_details(): void
    {
        [$va] = $this->assigned();
        $stored = NiumVirtualAccountDetail::query()->create(['nium_virtual_account_id' => $va->id, 'account_name' => 'Existing']);
        $before = $this->rowSnapshot($stored);
        $this->fakeHttp(fn () => Http::response($this->payload($va, ['uniquePaymentId' => 'VA-OTHER'])));
        $this->assertNull($this->sync($va));
        $this->assertSame($before, $this->rowSnapshot($stored));
        $this->assertSame('assigned', $va->fresh()->status);
    }

    public function test_requested_payment_id_can_be_on_a_later_documented_page(): void
    {
        [$va] = $this->assigned();
        $this->fakeHttp(function (Request $outgoing) use ($va) {
            $data = $this->payload($va);
            $data['totalPages'] = 2;
            $data['totalElements'] = 2;
            if ((int) $outgoing['page'] === 0) {
                $data['content'] = [['uniquePaymentId' => 'VA-DIFFERENT-PAGE']];
            }

            return Http::response($data);
        });
        $this->assertSame('TESTSGSGXXX', $this->sync($va)->routing_code_value);
        Http::assertSentCount(2);
    }

    public function test_duplicate_matching_identities_across_pages_require_review(): void
    {
        [$va] = $this->assigned();
        $this->fakeHttp(fn () => Http::response(array_replace($this->payload($va), ['totalPages' => 2, 'totalElements' => 2])));
        $this->rejects(fn () => $this->sync($va), 'Duplicate VA details identity');
        $this->assertSame(0, NiumVirtualAccountDetail::query()->count());
    }

    public function test_partial_response_keeps_complete_existing_pairs_and_nonempty_fields(): void
    {
        [$va] = $this->assigned();
        $this->fakeHttp(fn () => Http::response($this->payload($va)));
        $detail = $this->sync($va);
        $stored = $this->rowSnapshot($detail);
        $this->fakeHttp(fn () => Http::response(['content' => [[
            'uniquePaymentId' => $va->provider_payment_id, 'accountName' => null, 'fullBankName' => '',
            'bankName' => ' ', 'routingCodeType1' => '', 'routingCodeValue1' => null,
        ]]]));
        $this->assertSame($stored, $this->rowSnapshot($this->sync($va)));
    }

    public function test_partial_response_can_add_facts_without_overwriting_other_facts(): void
    {
        [$va] = $this->assigned();
        $this->fakeHttp(fn () => Http::response(['content' => [[
            'uniquePaymentId' => $va->provider_payment_id, 'accountName' => 'Synthetic Account Holder',
        ]]]));
        $first = $this->sync($va);
        $this->assertNull($first->routing_code_value);
        $this->fakeHttp(fn () => Http::response($this->payload($va)));
        $second = $this->sync($va);
        $this->assertSame($first->id, $second->id);
        $this->assertSame('Synthetic Account Holder', $second->account_name);
        $this->assertSame('TESTSGSGXXX', $second->routing_code_value);
    }

    #[DataProvider('unavailable')]
    public function test_unavailable_get_preserves_details_and_terminal_assignment(mixed $body, int $status, bool $timeout = false): void
    {
        [$va, , $request] = $this->assigned();
        $this->fakeHttp(fn () => Http::response($this->payload($va)));
        $detail = $this->sync($va);
        $stored = $this->rowSnapshot($detail);
        $terminal = $this->rowSnapshot($request);
        $this->fakeHttp(fn () => $timeout ? Http::failedConnection('synthetic timeout') : Http::response($body, $status));
        $this->rejects(fn () => $this->sync($va), '', $timeout ? ConnectionException::class : RuntimeException::class);
        $this->assertSame($timeout ? null : $status, ApiRequestLog::query()
            ->where('operation', 'fetch_virtual_account_details')->latest('id')->value('response_status'));
        $this->assertSame($stored, $this->rowSnapshot($detail));
        $this->assertSame($terminal, $this->rowSnapshot($request));
        $this->assertSame('assigned', $va->fresh()->status);
        // Recovery is another GET; no operation is reset or redispatched.
        $this->fakeHttp(fn () => Http::response($this->payload($va)));
        $this->assertSame($stored, $this->rowSnapshot($this->sync($va)));
    }

    public static function unavailable(): array
    {
        return [
            '404' => [[], 404], 'timeout' => [[], 200, true], 'redirect' => [[], 302],
            'malformed JSON' => ['not-json', 200], 'wrong content type' => [['content' => 'bad'], 200],
            'missing content' => [['message' => 'ok'], 200], 'scalar item' => [['content' => [true]], 200],
        ];
    }

    public function test_duplicate_get_is_idempotent_and_preserves_row_and_version(): void
    {
        [$va] = $this->assigned();
        $this->fakeHttp(fn () => Http::response($this->payload($va)));
        $detail = $this->sync($va);
        $stored = $this->rowSnapshot($detail);
        $this->assertSame($stored, $this->rowSnapshot($this->sync($va)));
        $this->assertSame(1, NiumVirtualAccountDetail::query()->count());
    }

    public function test_conflicting_sequential_response_cannot_overwrite_verified_routing(): void
    {
        [$va] = $this->assigned();
        $this->fakeHttp(fn () => Http::response($this->payload($va)));
        $detail = $this->sync($va);
        $stored = $this->rowSnapshot($detail);
        $this->fakeHttp(fn () => Http::response($this->payload($va, ['routingCodeValue1' => 'OLD-SWIFT'])));
        $this->rejects(fn () => $this->sync($va), 'Conflicting stored');
        $this->assertSame($stored, $this->rowSnapshot($detail));
    }

    public function test_incomplete_routing_pair_is_never_combined_with_the_other_response(): void
    {
        [$va] = $this->assigned();
        $this->fakeHttp(fn () => Http::response($this->payload($va)));
        $detail = $this->sync($va);
        $stored = $this->rowSnapshot($detail);
        $this->fakeHttp(fn () => Http::response($this->payload($va, [], ['routingCodeValue2'])));
        $this->rejects(fn () => $this->sync($va), 'Incomplete VA routing-code pair');
        $this->assertSame($stored, $this->rowSnapshot($detail));
    }

    public function test_response_finishing_after_a_newer_save_cannot_overwrite_it(): void
    {
        [$va] = $this->assigned();
        $calls = 0;
        $this->fakeHttp(function () use ($va, &$calls) {
            if (++$calls === 1) {
                $this->sync($va);

                return Http::response($this->payload($va, ['bankAddress' => 'Older in-flight address']));
            }

            return Http::response($this->payload($va, ['bankAddress' => 'Newer committed address']));
        });
        $this->rejects(fn () => $this->sync($va), 'Stale VA detail retrieval');
        $detail = $va->detail()->firstOrFail();
        $this->assertSame('Newer committed address', $detail->bank_address);
        $this->assertSame(1, $detail->enrichment_version);
        $this->assertSame(1, $va->detail()->count());
    }

    public function test_ownership_change_during_get_is_rechecked_before_save(): void
    {
        [$va, $account] = $this->assigned();
        $this->fakeHttp(function () use ($va, $account) {
            $account->update(['external_account_id' => (string) Str::uuid()]);

            return Http::response($this->payload($va));
        });
        $this->rejects(fn () => $this->sync($va));
        $this->assertSame(0, NiumVirtualAccountDetail::query()->count());
        $this->assertSame('assigned', $va->fresh()->status);
    }

    public function test_details_cannot_be_retrieved_inside_an_assignment_transaction(): void
    {
        [$va] = $this->assigned();
        $this->fakeHttp();
        $this->rejects(fn () => DB::transaction(fn () => $this->sync($va)), 'after assignment commits');
        Http::assertNothingSent();
    }

    public function test_enriching_an_old_assignment_does_not_release_a_new_unresolved_scope(): void
    {
        [$va, $account, $assigned] = $this->assigned();
        $next = app(NiumVirtualAccountSafetyLedger::class)->prepare($account, 'next-unresolved-assignment', $assigned->identity());
        $held = $this->rowSnapshot($next);
        $this->fakeHttp(fn () => Http::response($this->payload($va)));
        $this->assertNotNull($this->sync($va));
        $this->assertSame($held, $this->rowSnapshot($next));
        $this->assertSame(Operation::CLAIMED, $next->operation()->value('status'));
        $this->assertSame($next->operation_id, $next->assignmentScope()->value('active_operation_id'));
        $this->assertSame(Operation::ASSIGNED, $assigned->fresh()->operation_state);
    }

    public function test_pending_va_cannot_be_enriched_into_an_assignment(): void
    {
        $account = $this->ledgerAccount();
        $va = NiumVirtualAccount::query()->create([
            'user_provider_account_id' => $account->id, 'currency' => 'USD', 'status' => 'pending',
        ]);
        $this->fakeHttp();
        $this->rejects(fn () => $this->sync($va), 'already assigned');
        $this->assertNull($va->fresh()->provider_payment_id);
        $this->assertSame('pending', $va->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_detected_cross_account_collision_is_rejected_before_get(): void
    {
        [$va] = $this->assigned();
        $other = $this->ledgerAccount();
        NiumVirtualAccount::query()->create([
            'user_provider_account_id' => $other->id, 'provider_payment_id' => $va->provider_payment_id,
            'currency' => 'USD', 'status' => 'assigned',
        ]);
        $this->fakeHttp();
        $this->rejects(fn () => $this->sync($va), 'collision');
        Http::assertNothingSent();
    }

    public function test_legacy_non_jpm_account_and_existing_list_contract_remain_compatible(): void
    {
        $account = $this->ledgerAccount();
        $va = NiumVirtualAccount::query()->create([
            'user_provider_account_id' => $account->id, 'provider_payment_id' => 'VA-LEGACY-DETAILS',
            'currency' => 'SGD', 'account_category' => 'COLLECTION_ACCOUNT', 'account_type' => 'LOCAL', 'status' => 'assigned',
        ]);
        $this->fakeHttp(fn () => Http::response(['paymentIds' => [
            ['paymentId' => 'VA-NOT-REQUESTED', 'accountName' => 'Other'],
            ['paymentId' => $va->provider_payment_id, 'accountName' => 'Legacy holder',
                'bankName' => 'DBS_SG', 'fullBankName' => 'Synthetic legacy bank', 'currencyCode' => 'SGD',
                'accountType' => 'LOCAL', 'routingCodeType1' => 'BIC', 'routingCodeValue1' => 'TESTSGSG',
                'routingCodeType2' => 'Bank code', 'routingCodeValue2' => '123'],
        ]]));
        $detail = $this->sync($va);
        $this->assertSame('Legacy holder', $detail->account_name);
        $this->assertSame('Bank code', $detail->routing_code_type_2);
        $this->assertSame('LOCAL', $va->fresh()->account_type);
        $response = $this->withToken($this->token($account->user))->getJson(
            '/api/user/users/'.$account->user_id.'/provider-accounts/nium/virtual-accounts'
        );
        $response->assertOk()->assertJsonPath('data.0.provider_payment_id', $va->provider_payment_id)
            ->assertJsonPath('data.0.currency', 'SGD')
            ->assertJsonPath('data.0.detail.bank_name', 'Synthetic legacy bank')
            ->assertJsonPath('data.0.detail.routing_code_type', 'BIC')
            ->assertJsonPath('data.0.detail.routing_code_value', 'TESTSGSG')
            ->assertJsonPath('data.0.detail.routing_code_type_2', 'Bank code')
            ->assertJsonMissingPath('data.0.detail.enrichment_version');
        $this->assertStringNotContainsString('synthetic-key', $response->getContent());
        $this->assertStringNotContainsString('raw_response_body', $response->getContent());
    }

    #[DataProvider('unauthorized')]
    public function test_unauthorized_users_cannot_read_banking_details(bool $anonymous): void
    {
        [$va, $account] = $this->assigned();
        NiumVirtualAccountDetail::query()->create([
            'nium_virtual_account_id' => $va->id, 'routing_code_type' => 'SWIFT', 'routing_code_value' => 'PRIVATE-BANK-CODE',
        ]);
        if (! $anonymous) {
            $this->withToken($this->token(User::factory()->create(['kyc_status' => 'verified'])));
        }
        $response = $this->getJson('/api/user/users/'.$account->user_id.'/provider-accounts/nium/virtual-accounts');
        $response->assertStatus($anonymous ? 401 : 403);
        $this->assertStringNotContainsString('PRIVATE-BANK-CODE', $response->getContent());
        Http::assertNothingSent();
    }

    public static function unauthorized(): array
    {
        return [[true], [false]];
    }

    private function assigned(string $version = 'v1'): array
    {
        $account = $this->ledgerAccount();
        $identity = NiumVirtualAccountRequestIdentity::forAccount($account,
            NiumVirtualAccountAssignmentContract::payload($version, 'USD', 'SELF_FUNDING_ACCOUNT', 'WIRES', 'JPM_SG'),
            $version, $version === 'v1' ? 'WIRES' : null);
        $ledger = app(NiumVirtualAccountSafetyLedger::class);
        $request = $ledger->prepare($account, 'detail-test-'.Str::uuid(), $identity);
        $ledger->consumeDispatchAuthorization($request, 0);
        $log = $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-PHASE3C-SYNTHETIC']);
        $request = $ledger->resolveFromPostEvidence($request, 1, $log, attachVirtualAccount: true);
        $va = $request->operation()->firstOrFail()->virtualAccount()->firstOrFail();

        return [$va, $account, $request];
    }

    private function payload(NiumVirtualAccount $va, array $overrides = [], array $omit = []): array
    {
        $data = json_decode(file_get_contents(base_path('tests/Fixtures/nium/virtual-account-details-v2.json')), true, flags: JSON_THROW_ON_ERROR);
        $data['content'][0] = array_replace($data['content'][0], ['uniquePaymentId' => $va->provider_payment_id], $overrides);
        foreach ($omit as $key) {
            unset($data['content'][0][$key]);
        }

        return $data;
    }

    private function sync(NiumVirtualAccount $va): ?NiumVirtualAccountDetail
    {
        return app(NiumVirtualAccountDetailService::class)->sync($va);
    }

    private function fakeHttp(?callable $callback = null): void
    {
        foreach (Http::recorded() as [$request]) {
            $this->completedHttpRequests[] = $request;
        }
        // Factory::fake appends callbacks. Replace the factory between response
        // phases so an earlier catch-all cannot mask a partial/error/retry response.
        Http::swap(new HttpFactory(app('events')));
        Http::preventStrayRequests();
        Http::fake($callback);
    }

    private function rowSnapshot(Model $model): array
    {
        $persisted = $model->fresh();
        $this->assertNotNull($persisted, 'The verified database row must still exist.');

        return $this->canonicalFields($persisted->getAttributes());
    }

    private function canonicalFields(array $fields): array
    {
        // Keep every column, exact value, timestamp and version. Only key order
        // is incidental; nullable defaults are obtained from the database snapshot.
        ksort($fields, SORT_STRING);

        return $fields;
    }

    private function rejects(callable $action, string $message = '', string $type = RuntimeException::class): void
    {
        $caught = null;
        try {
            $action();
        } catch (Throwable $exception) {
            $caught = $exception;
        }
        $this->assertNotNull($caught, 'Unsafe or unavailable enrichment must fail without changing verified data.');
        $this->assertInstanceOf($type, $caught);
        if ($message !== '') {
            $this->assertStringContainsString($message, $caught->getMessage());
        }
    }

    private function token(User $user): string
    {
        $token = Str::random(80);
        ApiToken::query()->create([
            'user_id' => $user->id, 'name' => 'details-test', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay(),
        ]);

        return $token;
    }
}
