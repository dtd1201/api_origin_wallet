<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\User\BeneficiaryResource;
use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Integrations\ProviderBeneficiaryManager;
use App\Services\Nium\NiumBeneficiaryService;
use App\Support\PrimaryProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

class BeneficiaryController extends Controller
{
    public function index(User $user): JsonResponse
    {
        return response()->json(
            BeneficiaryResource::collection($user->beneficiaries()->latest('id')->get())->resolve()
        );
    }

    public function show(User $user, Beneficiary $beneficiary): JsonResponse
    {
        abort_unless($beneficiary->user_id === $user->id, 404);

        return response()->json((new BeneficiaryResource($beneficiary))->resolve());
    }

    public function requirements(Request $request, User $user, NiumBeneficiaryService $service): JsonResponse
    {
        $validated = $request->validate([
            'provider_id' => ['required', 'exists:integration_providers,id'],
            'beneficiary_type' => ['required', 'string', 'max:20'],
            'country_code' => ['required', 'string', 'size:2'],
            'currency' => ['required', 'string', 'size:3'],
            'payout_method' => ['required', 'string', 'max:50'],
        ]);
        $provider = PrimaryProvider::resolveForRequest((int) $validated['provider_id']);
        abort_unless($provider->code === 'nium', 422, 'Beneficiary requirements are available for Nium only.');
        $provider->assertSupportsCapability('beneficiary');
        $beneficiary = new Beneficiary([
            ...$validated,
            'raw_data' => ['nium' => ['payoutMethod' => $validated['payout_method']]],
        ]);
        $beneficiary->setRelation('user', $user);

        try {
            [, $schema] = $service->requirements($beneficiary);

            return response()->json([
                'dimensions' => $schema->dimensions,
                'routing_code_types' => $schema->routingCodeTypes,
                'fields' => $schema->fields,
            ]);
        } catch (\Throwable) {
            return response()->json(['message' => 'Beneficiary requirements are unavailable for these details. Check the country, currency and payment method, then retry.'], 502);
        }
    }

    public function store(Request $request, User $user, ProviderBeneficiaryManager $manager): JsonResponse
    {
        $validated = $request->validate([
            'provider_id' => ['sometimes', 'nullable', 'exists:integration_providers,id'],
            'external_beneficiary_id' => ['prohibited'],
            'beneficiary_type' => ['required', 'string', 'max:20'],
            'full_name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'country_code' => ['required', 'string', 'size:2'],
            'currency' => ['required', 'string', 'size:3'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_code' => ['nullable', 'string', 'max:100'],
            'branch_code' => ['nullable', 'string', 'max:100'],
            'account_number' => ['nullable', 'string', 'max:100'],
            'iban' => ['nullable', 'string', 'max:100'],
            'swift_bic' => ['nullable', 'string', 'max:50'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'raw_data' => ['nullable', 'array'],
            'raw_data.nium' => ['sometimes', 'array'],
            'raw_data.nium.request' => ['prohibited'],
            'raw_data.nium.verify_before_create' => ['sometimes', 'boolean'],
            'raw_data.nium.payoutMethod' => ['sometimes', 'nullable', 'string', 'max:50'],
            'raw_data.nium.bankAccountType' => ['sometimes', 'nullable', 'string', 'max:50'],
            'raw_data.nium.additionalFields' => ['sometimes', 'array'],
            'raw_data.nium.additionalFields.*' => ['nullable', 'string', 'max:1000'],
            'raw_data.nium.beneficiaryBankAccountType' => ['sometimes', 'nullable', 'string', 'max:50'],
            'raw_data.nium.beneficiaryContactCountryCode' => ['sometimes', 'nullable', 'string', 'max:10'],
            'raw_data.nium.remitterBeneficiaryRelationship' => ['sometimes', 'nullable', 'string', 'max:100'],
            'raw_data.nium.bankCodeType' => ['sometimes', 'nullable', 'string', 'max:50'],
            'raw_data.nium.branchCodeType' => ['sometimes', 'nullable', 'string', 'max:50'],
            'raw_data.nium.routingInfo' => ['sometimes', 'array', 'max:2'],
            'raw_data.nium.routingInfo.*' => ['required', 'array:type,value'],
            'raw_data.nium.routingInfo.*.type' => ['required', 'string', 'max:50'],
            'raw_data.nium.routingInfo.*.value' => ['required', 'string', 'max:100'],
            'raw_data.nium.account_verification' => ['sometimes', 'array'],
            'raw_data.nium.account_verification.request' => ['prohibited'],
            'raw_data.nium.account_verification.payoutMethod' => ['sometimes', 'nullable', 'string', 'max:50'],
            'raw_data.nium.account_verification.bankAccountType' => ['sometimes', 'nullable', 'string', 'max:50'],
            'raw_data.nium.account_verification.proxyType' => ['sometimes', 'nullable', 'string', 'max:50'],
            'raw_data.nium.account_verification.proxyValue' => ['sometimes', 'nullable', 'string', 'max:255'],
            'raw_data.nium.account_verification.routingInfo' => ['sometimes', 'array'],
        ]);

        try {
            $provider = PrimaryProvider::resolveForRequest(isset($validated['provider_id']) ? (int) $validated['provider_id'] : null);
            $validated['provider_id'] = $provider->id;

            $beneficiary = DB::transaction(function () use ($user, $provider, $validated, $manager): Beneficiary {
                $provider->assertSupportsCapability('beneficiary');
                $beneficiary = $user->beneficiaries()->create([
                    ...$validated,
                    'status' => 'pending',
                ]);

                return $manager->createBeneficiary($provider, $beneficiary->load('user'));
            });
        } catch (RuntimeException|InvalidArgumentException|ValidationException $exception) {
            if ($exception instanceof ValidationException) {
                throw $exception;
            }

            return $this->providerFailureResponse($exception, 'create', $user->id);
        }

        return response()->json((new BeneficiaryResource($beneficiary))->resolve(), 201);
    }

    public function update(
        Request $request,
        User $user,
        Beneficiary $beneficiary,
        ProviderBeneficiaryManager $manager,
    ): JsonResponse {
        abort_unless($beneficiary->user_id === $user->id, 404);

        $validated = $request->validate([
            'beneficiary_type' => ['sometimes', 'string', 'max:20'],
            'full_name' => ['sometimes', 'string', 'max:255'],
            'company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'country_code' => ['sometimes', 'string', 'size:2'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_code' => ['sometimes', 'nullable', 'string', 'max:100'],
            'branch_code' => ['sometimes', 'nullable', 'string', 'max:100'],
            'account_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'iban' => ['sometimes', 'nullable', 'string', 'max:100'],
            'swift_bic' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'state' => ['sometimes', 'nullable', 'string', 'max:100'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:30'],
            'raw_data' => ['sometimes', 'nullable', 'array'],
            'raw_data.nium' => ['sometimes', 'array'],
            'raw_data.nium.request' => ['prohibited'],
            'raw_data.nium.verify_before_create' => ['sometimes', 'boolean'],
            'raw_data.nium.payoutMethod' => ['sometimes', 'nullable', 'string', 'max:50'],
            'raw_data.nium.bankAccountType' => ['sometimes', 'nullable', 'string', 'max:50'],
            'raw_data.nium.additionalFields' => ['sometimes', 'array'],
            'raw_data.nium.additionalFields.*' => ['nullable', 'string', 'max:1000'],
            'raw_data.nium.beneficiaryBankAccountType' => ['sometimes', 'nullable', 'string', 'max:50'],
            'raw_data.nium.beneficiaryContactCountryCode' => ['sometimes', 'nullable', 'string', 'max:10'],
            'raw_data.nium.remitterBeneficiaryRelationship' => ['sometimes', 'nullable', 'string', 'max:100'],
            'raw_data.nium.bankCodeType' => ['sometimes', 'nullable', 'string', 'max:50'],
            'raw_data.nium.branchCodeType' => ['sometimes', 'nullable', 'string', 'max:50'],
            'raw_data.nium.routingInfo' => ['sometimes', 'array', 'max:2'],
            'raw_data.nium.routingInfo.*' => ['required', 'array:type,value'],
            'raw_data.nium.routingInfo.*.type' => ['required', 'string', 'max:50'],
            'raw_data.nium.routingInfo.*.value' => ['required', 'string', 'max:100'],
            'raw_data.nium.account_verification' => ['sometimes', 'array'],
            'raw_data.nium.account_verification.request' => ['prohibited'],
            'raw_data.nium.account_verification.payoutMethod' => ['sometimes', 'nullable', 'string', 'max:50'],
            'raw_data.nium.account_verification.bankAccountType' => ['sometimes', 'nullable', 'string', 'max:50'],
            'raw_data.nium.account_verification.proxyType' => ['sometimes', 'nullable', 'string', 'max:50'],
            'raw_data.nium.account_verification.proxyValue' => ['sometimes', 'nullable', 'string', 'max:255'],
            'raw_data.nium.account_verification.routingInfo' => ['sometimes', 'array'],
        ]);

        try {
            $beneficiary = DB::transaction(function () use ($beneficiary, $validated, $manager): Beneficiary {
                $provider = IntegrationProvider::query()->findOrFail($beneficiary->provider_id);
                if ($provider->code === 'nium' && is_array(data_get($validated, 'raw_data.nium.routingInfo'))) {
                    // A full typed routing replacement also clears obsolete legacy columns.
                    $validated += ['bank_code' => null, 'branch_code' => null, 'swift_bic' => null];
                }
                $beneficiary->update($validated);
                $provider->assertSupportsCapability('beneficiary');

                return $manager->updateBeneficiary($provider, $beneficiary->fresh()->load('user'));
            });
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return $this->providerFailureResponse($exception, 'update', $user->id, $beneficiary->id);
        }

        return response()->json((new BeneficiaryResource($beneficiary))->resolve());
    }

    public function destroy(User $user, Beneficiary $beneficiary, ProviderBeneficiaryManager $manager): JsonResponse
    {
        abort_unless($beneficiary->user_id === $user->id, 404);

        try {
            DB::transaction(function () use ($beneficiary, $manager): void {
                $provider = IntegrationProvider::query()->findOrFail($beneficiary->provider_id);
                $provider->assertSupportsCapability('beneficiary');
                $manager->deleteBeneficiary($provider, $beneficiary->load('user'));
                $beneficiary->delete();
            });
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return $this->providerFailureResponse($exception, 'delete', $user->id, $beneficiary->id);
        }

        return response()->json(status: 204);
    }

    private function providerFailureResponse(
        RuntimeException|InvalidArgumentException $exception,
        string $operation,
        int $userId,
        ?int $beneficiaryId = null,
    ): JsonResponse {
        Log::warning('Customer beneficiary provider operation failed.', [
            'operation' => $operation,
            'user_id' => $userId,
            'beneficiary_id' => $beneficiaryId,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);

        return response()->json([
            'message' => "Unable to {$operation} beneficiary with the selected provider. Review the details and try again.",
        ], 422);
    }
}
