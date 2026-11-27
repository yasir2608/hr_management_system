<?php

namespace App\Http\Controllers\Payroll;

use App\Access\SensitiveData;
use App\Http\Controllers\Controller;
use App\Models\BankExport;
use App\Models\BankFormat;
use App\Models\EmployeeBankAccount;
use App\Models\PayrollRun;
use App\Payroll\BankFormats\BankFileWriter;
use App\Payroll\BankFormats\PrepareBankFile;
use App\Payroll\Calculation\Money;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bank files from approved payroll runs: a masked preview with totals and exclusions, then a confirmed export that
 * is kept and can be downloaded again. Every creation and download is recorded. No money is sent.
 */
class BankExportController extends Controller
{
    public const NOTICE = 'Creating a bank file does not send money. Upload the file to your bank and approve the payment there.';

    public function __construct(private readonly PrepareBankFile $prepare, private readonly BankFileWriter $writer) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['run' => ['nullable', 'ulid'], 'payment_status' => ['nullable', Rule::in(BankExport::PAYMENT_STATUSES)]]);
        $exports = BankExport::query()->with(['run.group:id,name', 'creator:id,name', 'paymentRecorder:id,name'])
            ->when($data['run'] ?? null, fn ($query, string $run) => $query->where('payroll_run_id', $run))
            ->when($data['payment_status'] ?? null, fn ($query, string $status) => $query->where('payment_status', $status))
            ->latest()->limit(300)->get();

        return response()->json(['data' => $exports->map(fn (BankExport $export): array => $this->present($export)), 'notice' => self::NOTICE]);
    }

    public function preview(Request $request, PayrollRun $run): JsonResponse
    {
        $this->ensureApproved($run);
        $data = $request->validate(['bank_format_id' => ['nullable', 'ulid']]);
        $formats = BankFormat::query()->where('country_code', $run->country_code)->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get();

        $base = [
            'run' => $this->runSummary($run),
            'formats' => $formats->map(fn (BankFormat $format): array => ['id' => $format->id, 'name' => $format->name, 'template_name' => $format->definition()['name'], 'is_default' => $format->is_default])->values(),
            'notice' => self::NOTICE,
        ];
        if ($formats->isEmpty()) {
            return response()->json([...$base, 'format_id' => null]);
        }

        $format = isset($data['bank_format_id']) ? $formats->firstWhere('id', $data['bank_format_id']) : $formats->first();
        abort_if($format === null, 422, 'Choose an active bank format for '.config("countries.{$run->country_code}.name").'.');
        $prepared = $this->prepare->prepare($run, $format);

        return response()->json([
            ...$base,
            'format_id' => $format->id,
            'payments' => array_map(fn (array $payment): array => [
                'employee_id' => $payment['employee_id'], 'name' => $payment['name'], 'employee_number' => $payment['employee_number'],
                'bank_name' => $payment['account']->bank_name, 'account' => $this->masked($format, $payment['account']),
                'currency' => $run->currency, 'amount' => Money::format($payment['amount_cents']),
            ], $prepared['payments']),
            'excluded' => $prepared['excluded'],
            'warnings' => $prepared['warnings'],
            'payment_count' => $prepared['payment_count'],
            'total' => Money::format($prepared['total_cents']),
            'currency' => $run->currency,
        ]);
    }

    public function store(Request $request, PayrollRun $run): JsonResponse
    {
        $this->ensureApproved($run);
        $data = $request->validate([
            'bank_format_id' => ['required', 'ulid'],
            'confirmed' => ['accepted'],
            'payment_count' => ['required', 'integer', 'min:0'],
            'total' => ['required', 'numeric'],
        ], ['confirmed.accepted' => 'Confirm that you have checked the payments and that this file does not send money.']);

        $format = BankFormat::query()->where('country_code', $run->country_code)->where('is_active', true)->find($data['bank_format_id']);
        abort_if($format === null, 422, 'Choose an active bank format for '.config("countries.{$run->country_code}.name").'.');

        $export = DB::transaction(function () use ($run, $format, $data, $request): BankExport {
            $prepared = $this->prepare->prepare($run, $format);
            abort_if($prepared['payment_count'] === 0, 422, 'There are no payments to put in a bank file.');
            // The file must match what the user checked: bank details may have changed since the preview.
            abort_if(
                $prepared['payment_count'] !== (int) $data['payment_count'] || $prepared['total_cents'] !== Money::cents($data['total']),
                409,
                'The payments changed since the preview. Check the preview again before creating the file.',
            );

            $file = $this->writer->write($format, $run, $prepared['payments']);

            return BankExport::query()->create([
                'payroll_run_id' => $run->id, 'bank_format_id' => $format->id, 'format_name' => $format->name, 'template' => $format->template,
                'file_name' => $file['name'], 'mime' => $file['mime'], 'currency' => $run->currency,
                'payment_count' => $prepared['payment_count'], 'total_cents' => $prepared['total_cents'],
                'excluded' => $prepared['excluded'], 'content' => $file['content'], 'created_by' => $request->user()->id,
            ]);
        });

        Activity::record('payroll.bank_file_created', "Bank file created for the payroll run of {$run->period_start->toDateString()} to {$run->period_end->toDateString()} ({$export->payment_count} payments, ".Money::display($export->total_cents, $export->currency).')', $export, [
            'payroll_run_id' => $run->id, 'bank_format_id' => $format->id, 'format' => $format->name,
            'payment_count' => $export->payment_count, 'total' => Money::format($export->total_cents), 'excluded_count' => count($export->excluded ?? []),
        ]);

        return response()->json($this->present($export->load(['run.group:id,name', 'creator:id,name'])), 201);
    }

    public function download(BankExport $export): Response
    {
        $export->forceFill(['download_count' => $export->download_count + 1, 'last_downloaded_at' => now()])->save();
        $run = $export->run;
        Activity::record('payroll.bank_file_downloaded', "Bank file “{$export->file_name}” downloaded for the payroll run of {$run->period_start->toDateString()} to {$run->period_end->toDateString()}", $export, [
            'payroll_run_id' => $export->payroll_run_id, 'created_by' => $export->created_by, 'download_number' => $export->download_count,
        ]);

        return response($export->content, 200, [
            'Content-Type' => $export->mime,
            'Content-Disposition' => 'attachment; filename="'.$export->file_name.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Records what happened at the bank after the file was uploaded: paid (with the date and an optional bank
     * reference), failed (with what went wrong), or back to not recorded to undo a mistake. A record only.
     */
    public function recordPayment(Request $request, BankExport $export): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(BankExport::PAYMENT_STATUSES)],
            'paid_on' => ['required_if:status,'.BankExport::PAID, 'nullable', 'date', 'after_or_equal:'.$export->created_at->toDateString(), 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['required_if:status,'.BankExport::FAILED, 'nullable', 'string', 'max:500'],
        ], [
            'paid_on.required_if' => 'Enter the date the bank made the payments.',
            'paid_on.after_or_equal' => 'The payment date cannot be before the file was created.',
            'paid_on.before_or_equal' => 'The payment date cannot be in the future.',
            'note.required_if' => 'Say what went wrong, for example the bank’s rejection reason.',
        ]);

        $before = ['status' => $export->payment_status, 'paid_on' => $export->paid_on?->toDateString(), 'reference' => $export->payment_reference];
        $recorded = $data['status'] !== BankExport::NOT_RECORDED;
        $export->forceFill([
            'payment_status' => $data['status'],
            'paid_on' => $data['status'] === BankExport::PAID ? $data['paid_on'] : null,
            'payment_reference' => $recorded ? ($data['reference'] ?? null) : null,
            'payment_note' => $recorded ? ($data['note'] ?? null) : null,
            'payment_recorded_by' => $recorded ? $request->user()->id : null,
            'payment_recorded_at' => $recorded ? now() : null,
        ])->save();

        $run = $export->run;
        $period = "{$run->period_start->toDateString()} to {$run->period_end->toDateString()}";
        $description = match ($data['status']) {
            BankExport::PAID => "Bank file “{$export->file_name}” recorded as paid on {$export->paid_on->toDateString()} (payroll run {$period})",
            BankExport::FAILED => "Bank file “{$export->file_name}” recorded as failed (payroll run {$period})",
            default => "Payment record cleared for bank file “{$export->file_name}” (payroll run {$period})",
        };
        Activity::record('payroll.bank_payment_recorded', $description, $export, [
            'payroll_run_id' => $export->payroll_run_id,
            'before' => $before,
            'after' => ['status' => $export->payment_status, 'paid_on' => $export->paid_on?->toDateString(), 'reference' => $export->payment_reference],
            'note' => $export->payment_note,
        ]);

        return response()->json($this->present($export->load(['run.group:id,name', 'creator:id,name', 'paymentRecorder:id,name'])));
    }

    private function ensureApproved(PayrollRun $run): void
    {
        abort_unless($run->status === PayrollRun::APPROVED, 422, 'Bank files can only be created from an approved payroll run.');
    }

    private function masked(BankFormat $format, EmployeeBankAccount $account): string
    {
        $value = $format->template === 'pk_ibft_csv' && filled($account->bank_code) ? $account->bank_code : $account->account_number;

        return SensitiveData::mask((string) $value) ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    private function runSummary(PayrollRun $run): array
    {
        return [
            'id' => $run->id, 'kind' => $run->kind, 'status' => $run->status, 'country_code' => $run->country_code, 'currency' => $run->currency,
            'group' => $run->group?->name, 'period_start' => $run->period_start->toDateString(), 'period_end' => $run->period_end->toDateString(),
            'pay_date' => $run->pay_date->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(BankExport $export): array
    {
        return [
            ...$export->only(['id', 'format_name', 'file_name', 'currency', 'payment_count', 'download_count']),
            'total' => Money::format($export->total_cents),
            'excluded' => $export->excluded ?? [],
            'run' => $this->runSummary($export->run),
            'created_by' => $export->creator?->name,
            'created_at' => $export->created_at?->toIso8601String(),
            'last_downloaded_at' => $export->last_downloaded_at?->toIso8601String(),
            'payment' => [
                'status' => $export->payment_status,
                'paid_on' => $export->paid_on?->toDateString(),
                'reference' => $export->payment_reference,
                'note' => $export->payment_note,
                'recorded_by' => $export->paymentRecorder?->name,
                'recorded_at' => $export->payment_recorded_at?->toIso8601String(),
            ],
        ];
    }
}
