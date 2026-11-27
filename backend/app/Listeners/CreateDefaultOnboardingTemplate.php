<?php

namespace App\Listeners;

use App\Events\CompanyCreated;
use App\Onboarding\DefaultTemplate;

class CreateDefaultOnboardingTemplate
{
    public function handle(CompanyCreated $event): void
    {
        DefaultTemplate::createFor($event->company);
    }
}
