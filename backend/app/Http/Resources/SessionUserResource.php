<?php

namespace App\Http\Resources;

use App\Models\CompanyUser;
use App\Models\SupportAccessGrant;
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
            'companies' => $memberships->toBase()->map(fn (CompanyUser $membership): array => [
                'id' => $membership->company->id,
                'name' => $membership->company->name,
                'country_code' => $membership->company->country_code,
                // A company can be listed but not entered; the picker explains why.
                'can_enter' => $membership->isActive() && $membership->company->isActive(),
                'membership_status' => $membership->status,
                'company_status' => $membership->company->status,
                'support_access_until' => null,
            ])->merge($this->supportCompanies($memberships->pluck('company_id')->all()))->all(),
        ];
    }

    /**
     * Companies an M.Y HRMS staff member can currently view through an active support grant.
     *
     * @param  list<string>  $memberCompanyIds
     * @return list<array<string, mixed>>
     */
    private function supportCompanies(array $memberCompanyIds): array
    {
        if ($this->platform_role === null) {
            return [];
        }

        return SupportAccessGrant::query()->active()->with('company')
            ->where('user_id', $this->id)
            ->whereNotIn('company_id', $memberCompanyIds)
            ->get()
            ->unique('company_id')
            ->map(fn (SupportAccessGrant $grant): array => [
                'id' => $grant->company->id,
                'name' => $grant->company->name,
                'country_code' => $grant->company->country_code,
                'can_enter' => true,
                'membership_status' => 'support',
                'company_status' => $grant->company->status,
                'support_access_until' => $grant->expires_at,
            ])
            ->values()
            ->all();
    }
}
