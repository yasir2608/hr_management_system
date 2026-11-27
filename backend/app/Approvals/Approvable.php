<?php

namespace App\Approvals;

use App\Models\ApprovalRequest;

/**
 * A record that goes through approval (leave request, attendance correction, invoice...).
 * Called once the request is finally approved, declined or cancelled.
 */
interface Approvable
{
    public function onApprovalDecided(ApprovalRequest $request): void;
}
