<?php

namespace App\Support;

use App\Models\ContractorInvoice;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;

/**
 * Notifications raised when records change state, wired up in the models' booted() methods. Wording stays generic:
 * no amounts, account numbers or identifiers.
 */
class EventNotifications
{
    public static function payrollRunSaved(PayrollRun $run): void
    {
        if (! $run->wasChanged('status') && ! $run->wasRecentlyCreated) {
            return;
        }

        $name = ($run->group?->name ?? config("countries.{$run->country_code}.name")).', '.$run->period_start->format('M Y');
        $link = "/payroll/runs/{$run->id}";

        if ($run->status !== PayrollRun::IN_REVIEW) {
            Notifier::resolve($run, ['payroll.run_review']);
        }

        match ($run->status) {
            PayrollRun::IN_REVIEW => Notifier::sendToPermission('payroll.approve', 'payroll.run_review', "Payroll waiting for approval: {$name}", [
                'body' => 'Review the totals and warnings, then approve the run.', 'link' => $link, 'subject' => $run, 'action_required' => true,
            ]),
            PayrollRun::APPROVED => Notifier::send([$run->created_by], 'payroll.run_approved', "Payroll approved: {$name}", [
                'body' => 'Payslips have been issued. You can now create the bank file.', 'link' => $link, 'permission' => 'payroll.view', 'subject' => $run,
            ]),
            default => null,
        };
    }

    public static function payslipCreated(Payslip $payslip): void
    {
        if ($payslip->status !== Payslip::ISSUED) {
            return;
        }

        $userId = Employee::query()->whereKey($payslip->employee_id)->value('user_id');
        $period = $payslip->period_start->format('F Y');
        $title = match ($payslip->kind) {
            PayrollRun::KIND_ADJUSTMENT => "An adjustment payslip for {$period} is ready",
            PayrollRun::KIND_REPLACEMENT => "Your corrected payslip for {$period} is ready",
            default => "Your payslip for {$period} is ready",
        };

        Notifier::send([$userId], 'payslip.issued', $title, [
            'link' => "/me/payslips/{$payslip->id}", 'permission' => 'self.payslips.view', 'subject' => $payslip, 'include_actor' => true,
        ]);
    }

    public static function invoiceSaved(ContractorInvoice $invoice): void
    {
        if (! $invoice->wasChanged('status') && ! $invoice->wasRecentlyCreated) {
            return;
        }

        if ($invoice->status !== 'pending_review') {
            Notifier::resolve($invoice, ['invoice.review']);

            return;
        }

        $contractor = $invoice->contractor()->value('name') ?? 'a contractor';
        Notifier::sendToPermission('contractors.invoices.approve', 'invoice.review', "Invoice to review: {$invoice->reference} from {$contractor}", [
            'body' => $invoice->due_date ? 'Due '.$invoice->due_date->toDateString().'.' : null,
            'link' => "/contractors/{$invoice->contractor_id}", 'subject' => $invoice, 'action_required' => true,
        ]);
    }
}
