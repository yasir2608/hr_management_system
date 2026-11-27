<?php

namespace App\Onboarding;

use App\Models\Company;
use App\Models\OnboardingTemplate;
use App\Tenancy\CurrentCompany;

/**
 * The checklist every company starts with. Companies can change it or add their own.
 */
class DefaultTemplate
{
    /** [title, owner, days after start, required, employee field group] */
    public const TASKS = [
        ['Prepare the employment contract', 'hr', -7, true, null],
        ['Check right to work', 'hr', -3, true, null],
        ['Set up laptop and accounts', 'it', -1, false, null],
        ['Add your personal details', 'employee', 0, true, 'personal'],
        ['Add an emergency contact', 'employee', 0, true, 'emergency'],
        ['Add your bank details for pay', 'employee', 3, true, 'bank'],
        ['Add your tax details', 'employee', 3, true, 'tax'],
        ['Confirm pay and payroll group', 'payroll', 5, false, null],
        ['Welcome meeting and introductions', 'manager', 0, false, null],
        ['Agree first-month goals', 'manager', 7, false, null],
    ];

    public static function createFor(Company $company): OnboardingTemplate
    {
        return app(CurrentCompany::class)->runAs($company, function (): OnboardingTemplate {
            $existing = OnboardingTemplate::query()->where('is_default', true)->first();

            if ($existing !== null) {
                return $existing;
            }

            $template = OnboardingTemplate::query()->create([
                'name' => 'Standard onboarding',
                'description' => 'The default checklist for new starters.',
                'is_default' => true,
            ]);

            foreach (self::TASKS as $position => [$title, $owner, $offset, $required, $group]) {
                $template->tasks()->create([
                    'title' => $title,
                    'owner_type' => $owner,
                    'due_offset_days' => $offset,
                    'is_required' => $required,
                    'employee_field_group' => $group,
                    'position' => $position,
                ]);
            }

            return $template;
        });
    }
}
