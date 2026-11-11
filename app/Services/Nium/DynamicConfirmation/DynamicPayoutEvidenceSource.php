<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use DateTimeImmutable;

/**
 * Server-only port. Origin's bound adapter denies missing or changed authority;
 * flags cannot manufacture evidence.
 * An implementation must reload authenticated evidence and lock its revocation /
 * compliance fences in the caller's transaction, including beneficiary/AML/ODD,
 * account, corridor and override holds. Coordinate hold writers on those fences.
 * It must verify the registered receiving-account binding, not simply hash the
 * current editable beneficiary and call it registered. No transport, jobs or
 * other side effects are permitted here (the transaction may be retried).
 * Never deserialize a client review, financial model or evidence receipt.
 * Execution also requires an immutable PayoutInstruction in the review, verified
 * corridor-specific purpose/source-of-funds applicability in versions[remittance_instruction],
 * and LOCAL_SINGLE_INTENT_PER_QUOTE (an internal uniqueness policy, not provider single-use).
 */
interface DynamicPayoutEvidenceSource
{
    /** Network acquisition only outside transactions, followed by locked revalidation. */
    public function refreshForExecution(\App\Models\NiumDynamicIntent $intent): void;

    public function revalidate(LockedPayoutRecords $records, DateTimeImmutable $at): DynamicPayoutEvidence;
}
