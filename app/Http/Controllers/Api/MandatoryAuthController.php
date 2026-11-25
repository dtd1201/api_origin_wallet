<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Services\Auth\ApiAuthService;
use App\Services\Auth\TotpEnrollmentService;
use App\Services\Auth\TotpRecoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MandatoryAuthController extends Controller
{
    private const CAPABILITY = ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/D'];
    private const TOTP = ['required', 'string', 'regex:/\A[0-9]{6}\z/D'];

    public function __construct(private readonly ApiAuthService $auth, private readonly TotpEnrollmentService $enrollment,
        private readonly TotpRecoveryService $recovery) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'], 'client_binding' => self::CAPABILITY]);

        return response()->json($this->auth->startLogin($data['email'], $data['password'], $this->audience($request), $data['client_binding']), 202);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $this->bound($request, ['totp_code' => self::TOTP, 'email' => ['prohibited'], 'verification_code' => ['prohibited']]);

        return response()->json($this->auth->completeLogin($data['challenge_token'], $data['totp_code'], $this->audience($request), $this->binding($request)));
    }

    public function retired(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Email-code login is no longer available.'], 410);
    }

    public function identityRequest(Request $request): JsonResponse
    {
        $data = $this->bound($request);

        return response()->json($this->enrollment->identityRequest($data['challenge_token'], $this->audience($request), $this->binding($request)), 202);
    }

    public function identityVerify(Request $request): JsonResponse
    {
        $data = $this->bound($request, ['identity_proof' => ['nullable', 'string', 'max:64', 'required_without:grant_token'],
            'grant_token' => ['nullable', 'string', 'regex:/\A[0-9a-f]{64}\z/D', 'required_without:identity_proof', 'prohibits:identity_proof']]);

        return response()->json($this->enrollment->identityVerify($data['challenge_token'], $this->audience($request),
            $this->binding($request), $data['identity_proof'] ?? null, $data['grant_token'] ?? null), 202);
    }

    public function setup(Request $request): JsonResponse
    {
        $data = $this->bound($request);

        return response()->json($this->enrollment->setup($data['challenge_token'], $this->audience($request), $this->binding($request)));
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $this->bound($request, ['totp_code' => self::TOTP]);

        return response()->json($this->enrollment->confirm($data['challenge_token'], $this->audience($request), $this->binding($request), $data['totp_code']));
    }

    public function cancel(Request $request): JsonResponse
    {
        $data = $this->bound($request);

        return response()->json($this->enrollment->cancel($data['challenge_token'], $this->audience($request), $this->binding($request)));
    }

    public function recoveryStart(Request $request): JsonResponse
    {
        $data = $this->bound($request);

        return response()->json($this->recovery->start($data['challenge_token'], $this->audience($request), $this->binding($request)), 202);
    }

    public function recoveryVerify(Request $request): JsonResponse
    {
        $data = $this->bound($request, ['recovery_code' => ['nullable', 'string', 'max:80', 'required_without:grant_token'],
            'grant_token' => ['nullable', 'string', 'regex:/\A[0-9a-f]{64}\z/D', 'required_without:recovery_code', 'prohibits:recovery_code']]);

        return response()->json($this->recovery->verify($data['challenge_token'], $this->audience($request), $this->binding($request),
            $data['recovery_code'] ?? null, $data['grant_token'] ?? null), 202);
    }

    public function recoveryRequest(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);

        return response()->json($this->recovery->intake($data['email']), 202);
    }

    public function replacementSetup(Request $request): JsonResponse
    {
        $data = $this->bound($request);

        return response()->json($this->enrollment->setup($data['challenge_token'], $this->audience($request), $this->binding($request), true));
    }

    public function replacementConfirm(Request $request): JsonResponse
    {
        $data = $this->bound($request, ['totp_code' => self::TOTP]);

        return response()->json($this->enrollment->confirm($data['challenge_token'], $this->audience($request), $this->binding($request), $data['totp_code'], true));
    }

    public function security(Request $request): JsonResponse
    {
        return response()->json($this->recovery->status($request->user()));
    }

    public function stepUp(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:1024'], 'client_binding' => self::CAPABILITY,
            'intent' => ['required', 'in:replace_authenticator,regenerate_recovery_codes']]);

        return response()->json($this->recovery->stepUp($this->apiToken($request), $data['password'], $data['client_binding'], $data['intent']), 202);
    }

    public function stepUpVerify(Request $request): JsonResponse
    {
        $data = $this->bound($request, ['totp_code' => self::TOTP]);

        return response()->json($this->recovery->stepUpVerify($this->apiToken($request), $data['challenge_token'], $this->binding($request), $data['totp_code']), 202);
    }

    public function regenerate(Request $request): JsonResponse
    {
        $data = $this->bound($request);

        return response()->json($this->recovery->regenerate($this->apiToken($request), $data['challenge_token'], $this->binding($request)));
    }

    private function bound(Request $request, array $rules = []): array
    {
        $this->binding($request);

        return $request->validate(['challenge_token' => self::CAPABILITY, ...$rules]);
    }

    private function binding(Request $request): string
    {
        return Validator::make(['client_binding' => $request->header('X-Auth-Client-Binding')],
            ['client_binding' => self::CAPABILITY])->validate()['client_binding'];
    }

    private function audience(Request $request): string
    {
        // Determined solely by registered endpoint, never a body/query claim.
        return $request->is('api/admin/*') ? 'admin' : 'user';
    }

    private function apiToken(Request $request): ApiToken
    {
        $token = $request->attributes->get('apiToken');
        abort_unless($token instanceof ApiToken, 401, 'Unauthenticated.');

        return $token;
    }
}
