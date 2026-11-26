<?php

namespace App\Listeners;

use App\Access\StarterRoles;
use App\Events\CompanyCreated;
use App\Models\Role;

/**
 * Every new company gets the starter roles, and its primary administrator becomes owner.
 */
class CreateStarterRoles
{
    public function handle(CompanyCreated $event): void
    {
        StarterRoles::createFor($event->company);
        StarterRoles::assign($event->primaryAdmin, Role::OWNER, $event->primaryAdmin->user_id);
    }
}
