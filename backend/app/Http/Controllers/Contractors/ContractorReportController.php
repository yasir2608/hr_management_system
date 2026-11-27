<?php

namespace App\Http\Controllers\Contractors;

use App\Http\Controllers\Controller;
use App\Models\Contractor;
use App\Models\ContractorInvoice;
use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Contractor lists for download. Every file states that contractors are not paid through payroll.
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

        Activity::record('contractor.list_exported', "Contractor list exported ({$contractors->count()} contractors)");

        return $this->csv('contractors.csv', ['Name', 'Type', 'Status', 'Email', 'Country', 'Owner', 'Active contracts', 'Paid through payroll'], $contractors->map(fn (Contractor $contractor): array => [
            $contractor->name, $contractor->kind === 'supplier' ? 'Supplier' : 'Individual', ucfirst($contractor->status), $contractor->email,
            $contractor->country_code, $contractor->owner?->name, $contractor->active_contracts, 'No — paid outside payroll',
        ]));
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

        Activity::record('contractor.invoices_exported', "Contractor invoice list exported ({$invoices->count()} invoices)");

        return $this->csv('contractor-invoices.csv', [
            'Contractor', 'Reference', 'Contract', 'Invoice date', 'Due date', 'Currency', 'Amount', 'Status', 'Paid on', 'Payment method', 'Payment reference', 'Paid through payroll',
        ], $invoices->map(fn (ContractorInvoice $invoice): array => [
            $invoice->contractor->name, $invoice->reference, $invoice->contract?->title, $invoice->issue_date->toDateString(), $invoice->due_date?->toDateString(),
            $invoice->currency, $invoice->amount, ucfirst(str_replace('_', ' ', $invoice->displayStatus())), $invoice->paid_on?->toDateString(),
            $invoice->payment_method === null ? null : ucfirst(str_replace('_', ' ', $invoice->payment_method)), $invoice->payment_reference, 'No — paid outside payroll',
        ]));
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int, mixed>>  $rows
     */
    private function csv(string $name, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [self::NOTICE], ',', '"', '');
            fputcsv($out, [], ',', '"', '');
            fputcsv($out, $headers, ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($out, $row, ',', '"', '');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
