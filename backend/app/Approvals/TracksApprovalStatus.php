<?php

namespace App\Approvals;

use App\Models\ApprovalRequest;

/**
 * For subjects that show interim states (e.g. "needs information") before the final decision.
 */
interface TracksApprovalStatus
{
    public function onApprovalStatusChanged(ApprovalRequest $request): void;
}
