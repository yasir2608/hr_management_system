<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\SendReminders;
use App\Tenancy\CurrentCompany;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Sends reminders for actions still waiting, company by company. Scheduled daily (routes/console.php).
 */
#[Signature('notifications:remind')]
#[Description('Remind people about approvals and tasks still waiting for them')]
class SendNotificationReminders extends Command
{
    public function handle(CurrentCompany $currentCompany, SendReminders $reminders): int
    {
        $totals = ['reminded' => 0, 'resolved' => 0];

        Company::query()->orderBy('id')->each(function (Company $company) use ($currentCompany, $reminders, &$totals): void {
            $result = $currentCompany->runAs($company, fn (): array => $reminders->run());
            $totals['reminded'] += $result['reminded'];
            $totals['resolved'] += $result['resolved'];
        });

        $this->info("Reminders sent: {$totals['reminded']}. Already done, so no reminder: {$totals['resolved']}.");

        return self::SUCCESS;
    }
}
