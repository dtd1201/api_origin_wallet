<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransferListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transfer_no' => $this->transfer_no,
            'user_id' => $this->user_id,
            'provider_id' => $this->provider_id,
            'source_bank_account_id' => $this->source_bank_account_id,
            'beneficiary_id' => $this->beneficiary_id,
            'external_transfer_id' => $this->external_transfer_id,
            'external_payment_id' => $this->external_payment_id,
            'transfer_type' => $this->transfer_type,
            'source_currency' => $this->source_currency,
            'target_currency' => $this->target_currency,
            'source_amount' => $this->source_amount,
            'target_amount' => $this->target_amount,
            'fx_rate' => $this->fx_rate,
            'fee_amount' => $this->fee_amount,
            'fee_currency' => $this->fee_currency,
            'purpose_code' => $this->purpose_code,
            'reference_text' => $this->reference_text,
            'client_reference' => $this->client_reference,
            'status' => $this->status,
            'failure_code' => $this->failure_code,
            'failure_reason' => $this->failure_reason,
            'submitted_at' => $this->submitted_at,
            'provider_status_at' => $this->provider_status_at,
            'completed_at' => $this->completed_at,
            'compliance_review_required' => $this->compliance_review_required,
            'compliance_status' => $this->compliance_status,
            'compliance_reviewed_at' => $this->compliance_reviewed_at,
            'user' => $this->whenLoaded('user', fn () => (new UserListResource($this->user))->resolve($request)),
            'provider' => $this->whenLoaded('provider', fn () => (new ProviderSummaryResource($this->provider))->resolve($request)),
            'approvals' => $this->whenLoaded('approvals', fn () => $this->approvalPayloads($request)),
            'allowed_actions' => [
                'approve' => $request->user()?->hasPermission('transfers.approve') === true,
                'reject' => $request->user()?->hasPermission('transfers.reject') === true,
                'sync' => $request->user()?->hasPermission('transfers.manage') === true,
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    protected function approvalPayloads(Request $request)
    {
        return $this->approvals->map(fn ($approval) => [
            'id' => $approval->id,
            'transfer_id' => $approval->transfer_id,
            'approver_user_id' => $approval->approver_user_id,
            'action' => $approval->action,
            'note' => $approval->note,
            'created_at' => $approval->created_at,
            'approver' => $approval->relationLoaded('approver') && $approval->approver
                ? (new UserListResource($approval->approver))->resolve($request)
                : null,
        ])->values();
    }
}
