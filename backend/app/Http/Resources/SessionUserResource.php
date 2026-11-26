<?php

namespace App\Http\Resources;

use App\Models\CompanyUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class SessionUserResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $memberships = $this->companyMemberships()
            ->with('company')
            ->where('status', '!=', CompanyUser::STATUS_REMOVED)
            ->get()
            ->sortBy(fn (CompanyUser $membership) => $membership->company->name)
            ->values();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'platform_role' => $this->platform_role,
            'companies' => $memberships->map(fn (CompanyUser $membership): array => [
                'id' => $membership->company->id,
                'name' => $membership->company->name,
                'country_code' => $membership->company->country_code,
                // A company can be listed but not entered; the picker explains why.
                'can_enter' => $membership->isActive() && $membership->company->isActive(),
                'membership_status' => $membership->status,
                'company_status' => $membership->company->status,
            ])->all(),
        ];
    }
}
