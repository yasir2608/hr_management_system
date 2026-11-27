<?php

namespace App\Support;

/**
 * Notification kinds grouped into categories that companies and people can control. In-product notifications are
 * always on; email and reminders can be switched per category.
 */
final class NotificationCategories
{
    /** key => [label, description, kind prefixes, reminders apply] */
    public const ALL = [
        'approvals' => ['Approvals', 'Leave and attendance requests to decide, questions to answer, and decisions on your own requests.', ['approval.'], true],
        'payroll' => ['Payroll', 'Payroll runs waiting for approval, and approved runs you prepared.', ['payroll.'], true],
        'payslips' => ['Payslips', 'New, corrected and adjustment payslips.', ['payslip.'], false],
        'invoices' => ['Contractor invoices', 'Contractor invoices waiting for review.', ['invoice.'], true],
        'onboarding' => ['Onboarding', 'Onboarding tasks assigned to new starters and their managers.', ['onboarding.'], true],
    ];

    public static function for(string $kind): ?string
    {
        foreach (self::ALL as $key => [, , $prefixes]) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($kind, $prefix)) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * @return list<array{key: string, label: string, description: string, reminders: bool}>
     */
    public static function describe(): array
    {
        return collect(self::ALL)->map(fn (array $category, string $key): array => [
            'key' => $key, 'label' => $category[0], 'description' => $category[1], 'reminders' => $category[3],
        ])->values()->all();
    }
}
