<?php

namespace App\Services\Chatbot;

use App\Models\User;
use Illuminate\Support\Str;

class ChatbotService
{
    public function reply(User $user, string $message, ?string $conversationId = null): array
    {
        $normalizedMessage = Str::lower(trim($message));

        $conversationId ??= 'conv_' . Str::lower((string) Str::uuid());

        $profileCompleted = $user->profile()->exists();
        $hasProviderAccount = $user->providerAccounts()->exists();
        $hasBeneficiaries = $user->beneficiaries()->exists();
        $hasBalances = $user->balances()->exists();

        $response = match (true) {
            $this->containsAny($normalizedMessage, ['hello', 'hi', 'xin chao', 'chao']) => [
                'reply' => "Hi {$this->displayName($user)}. I can help you navigate balances, beneficiaries, transfers, profile completion, and onboarding status.",
                'suggestions' => [
                    'How do I complete my profile?',
                    'How do I add a beneficiary?',
                    'How do I create a transfer?',
                ],
                'actions' => [
                    $this->navigateAction('Open Home', '/account'),
                ],
            ],
            $this->containsAny($normalizedMessage, ['profile', 'kyc', 'onboarding']) => $this->profileResponse($profileCompleted, $hasProviderAccount),
            $this->containsAny($normalizedMessage, ['beneficiary', 'recipient']) => $this->beneficiaryResponse($hasBeneficiaries),
            $this->containsAny($normalizedMessage, ['transfer', 'send money', 'payment']) => $this->transferResponse($profileCompleted, $hasBeneficiaries),
            $this->containsAny($normalizedMessage, ['balance', 'money', 'fund']) => $this->balanceResponse($hasBalances),
            $this->containsAny($normalizedMessage, ['bank account', 'virtual account', 'account details', 'receive money']) => [
                'reply' => 'Open Bank Accounts or Virtual Accounts to view receiving account details. If no account is shown yet, finish profile submission and wait for provider onboarding.',
                'suggestions' => [
                    'Show my balances',
                    'How do I complete my profile?',
                ],
                'actions' => [
                    $this->navigateAction('Open Bank Accounts', '/bank-accounts'),
                    $this->navigateAction('Open Virtual Accounts', '/virtual-accounts'),
                ],
            ],
            $this->containsAny($normalizedMessage, ['quote', 'fx', 'exchange']) => [
                'reply' => 'To exchange currency, create an FX quote first, review the returned rate and expiry, then use that quote when creating a transfer.',
                'suggestions' => [
                    'How do I create a transfer?',
                    'Show my balances',
                ],
                'actions' => [
                    $this->navigateAction('Open Exchange', '/exchange'),
                ],
            ],
            default => [
                'reply' => 'I can help with profile completion, balances, beneficiaries, FX quotes, transfers, and onboarding status. Try asking about one of those topics.',
                'suggestions' => [
                    'How do I complete my profile?',
                    'How do I add a beneficiary?',
                    'How do I create a transfer?',
                ],
                'actions' => [],
            ],
        };

        return [
            'conversation_id' => $conversationId,
            'reply' => $response['reply'],
            'suggestions' => $response['suggestions'],
            'actions' => $response['actions'],
            'meta' => [
                'profile_completed' => $profileCompleted,
                'has_provider_account' => $hasProviderAccount,
                'has_beneficiaries' => $hasBeneficiaries,
                'has_balances' => $hasBalances,
            ],
        ];
    }

    private function profileResponse(bool $profileCompleted, bool $hasProviderAccount): array
    {
        if (! $profileCompleted) {
            return [
                'reply' => 'Your profile is not complete yet. Go to profile settings, fill in personal or business details, and select a provider code if onboarding requires one.',
                'suggestions' => [
                    'What provider should I choose?',
                    'What fields are required in profile?',
                ],
                'actions' => [
                    $this->navigateAction('Open Profile', '/profile'),
                ],
            ];
        }

        if (! $hasProviderAccount) {
            return [
                'reply' => 'Your profile is complete, but no provider account is linked yet. The next step is usually provider onboarding or account linking.',
                'suggestions' => [
                    'How do I link a provider account?',
                    'How do I check onboarding status?',
                ],
                'actions' => [
                    $this->navigateAction('Open Integrations', '/integrations'),
                ],
            ];
        }

        return [
            'reply' => 'Your profile is complete and a provider account record already exists. You can continue with balances, beneficiaries, and transfers.',
            'suggestions' => [
                'Show my balances',
                'How do I add a beneficiary?',
            ],
            'actions' => [
                $this->navigateAction('Open Home', '/account'),
            ],
        ];
    }

    private function beneficiaryResponse(bool $hasBeneficiaries): array
    {
        return [
            'reply' => $hasBeneficiaries
                ? 'You already have at least one beneficiary. Open the beneficiaries screen to review or update them before sending money.'
                : 'To add a beneficiary, open the beneficiaries screen and provide recipient banking details such as full name, country, currency, bank name, and account number.',
            'suggestions' => [
                'How do I create a transfer?',
                'What beneficiary fields are required?',
            ],
            'actions' => [
                $this->navigateAction('Open Beneficiaries', '/beneficiaries'),
                $this->navigateAction('Add Beneficiary', '/beneficiaries/new'),
            ],
        ];
    }

    private function transferResponse(bool $profileCompleted, bool $hasBeneficiaries): array
    {
        if (! $profileCompleted) {
            return [
                'reply' => 'You need to complete your profile before transfer-related endpoints become available.',
                'suggestions' => [
                    'How do I complete my profile?',
                ],
                'actions' => [
                    $this->navigateAction('Open Profile', '/profile'),
                ],
            ];
        }

        if (! $hasBeneficiaries) {
            return [
                'reply' => 'Before creating a transfer, add at least one beneficiary.',
                'suggestions' => [
                    'How do I add a beneficiary?',
                ],
                'actions' => [
                    $this->navigateAction('Add Beneficiary', '/beneficiaries/new'),
                ],
            ];
        }

        return [
            'reply' => 'To create a transfer, choose provider, source bank account, beneficiary, and optionally an FX quote. New transfers are typically created in draft status before submission.',
            'suggestions' => [
                'How do I create an FX quote?',
                'How do I submit a draft transfer?',
            ],
            'actions' => [
                $this->navigateAction('Create Transfer', '/transfers/new'),
                $this->navigateAction('Open Transfers', '/transfers'),
            ],
        ];
    }

    private function balanceResponse(bool $hasBalances): array
    {
        return [
            'reply' => $hasBalances
                ? 'Open balances to review available, ledger, and reserved balances by currency.'
                : 'No synced balances are available yet. If onboarding is complete, run provider sync or wait until account data is available.',
            'suggestions' => [
                'How do I sync balances?',
                'How do I view bank accounts?',
            ],
            'actions' => [
                $this->navigateAction('Open Balances', '/balances'),
            ],
        ];
    }

    private function containsAny(string $message, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function navigateAction(string $label, string $target): array
    {
        return [
            'type' => 'navigate',
            'label' => $label,
            'target' => $target,
        ];
    }

    private function displayName(User $user): string
    {
        return $user->full_name !== null && $user->full_name !== ''
            ? $user->full_name
            : 'there';
    }
}
