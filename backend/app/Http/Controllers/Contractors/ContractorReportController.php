<?php

namespace App\Http\Controllers\Contractors;

use App\Http\Controllers\Controller;
use App\Models\Contractor;
use App\Models\ContractorInvoice;
use App\Support\CsvExport;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Contractor lists for download, with the date and filters used. Every file states that contractors are not paid
 * through payroll.
 */
class ContractorReportController extends Controller
{
    public const NOTICE = 'Contractors are not employees and are not paid through payroll. Their invoices are paid outside payroll.';

    public function contractors(Request $request): StreamedResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(['active', 'inactive'])]]);

        $contractors = Contractor::query()->with('owner:id,name')
            ->withCount(['contracts as active_contracts' => fn ($query) => $query->where('status', 'active')])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderBy('name')->get();

        return (new CsvExport('Contractor list'))
            ->filters(['Status' => $request->filled('status') ? ucfirst($request->string('status')->toString()) : null])
            ->notice(self::NOTICE)
            ->table(['Name', 'Type', 'Status', 'Email', 'Country', 'Owner', 'Active contracts', 'Paid through payroll'], $contractors->map(fn (Contractor $contractor): array => [
                $contractor->name, $contractor->kind === 'supplier' ? 'Supplier' : 'Individual', ucfirst($contractor->status), $contractor->email,
                $contractor->country_code, $contractor->owner?->name, $contractor->active_contracts, 'No — paid outside payroll',
            ])->all())
            ->logged('contractor.list_exported', "Contractor list exported ({$contractors->count()} contractors)", ['rows' => $contractors->count()])
            ->download('contractors.csv');
    }

    public function invoices(Request $request): StreamedResponse
    {
        $request->validate(['status' => ['nullable', Rule::in([...ContractorInvoice::STATUSES, 'overdue', 'outstanding'])]]);
        $status = $request->string('status')->toString();

        $invoices = ContractorInvoice::query()->with(['contractor:id,name', 'contract:id,title'])
            ->when($status === 'overdue', fn ($query) => $query->overdue())
            ->when($status === 'outstanding', fn ($query) => $query->whereIn('status', ContractorInvoice::OUTSTANDING))
            ->when(in_array($status, ContractorInvoice::STATUSES, true), fn ($query) => $query->where('status', $status))
            ->orderBy('issue_date')->get();

        return (new CsvExport('Contractor invoices'))
            ->filters(['Status' => $status === '' ? null : ucfirst(str_replace('_', ' ', $status))])
            ->notice(self::NOTICE)
            ->table([
                'Contractor', 'Reference', 'Contract', 'Invoice date', 'Due date', 'Currency', 'Amount', 'Status', 'Paid on', 'Payment method', 'Payment reference', 'Paid through payroll',
            ], $invoices->map(fn (ContractorInvoice $invoice): array => [
                $invoice->contractor->name, $invoice->reference, $invoice->contract?->title, $invoice->issue_date->toDateString(), $invoice->due_date?->toDateString(),
                $invoice->currency, $invoice->amount, ucfirst(str_replace('_', ' ', $invoice->displayStatus())), $invoice->paid_on?->toDateString(),
                $invoice->payment_method === null ? null : ucfirst(str_replace('_', ' ', $invoice->payment_method)), $invoice->payment_reference, 'No — paid outside payroll',
            ])->all())
            ->logged('contractor.invoices_exported', "Contractor invoice list exported ({$invoices->count()} invoices)", ['rows' => $invoices->count()])
            ->download('contractor-invoices.csv');
    }
}
