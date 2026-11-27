<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\PayrollRun;
use App\Models\PayrollRunEmployee;
use App\Payroll\RunIssues;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A run's warnings and blocking issues, and marking warnings as reviewed.
 */
class RunIssueController extends Controller
{
    public function __construct(private readonly RunIssues $issues) {}

    public function index(PayrollRun $run): JsonResponse
    {
        $lines = $run->lines()->orderBy('employee_name')->get();
        $previous = $this->issues->previousGross($run);

        return response()->json([
            'summary' => $this->issues->summary($run, $lines, $previous),
            'lines' => $lines->map(fn (PayrollRunEmployee $line): array => [
                'id' => $line->id, 'employee_id' => $line->employee_id, 'employee_name' => $line->employee_name, 'included' => $line->included,
                'issues' => $this->issues->forLine($line, $previous),
            ])->filter(fn (array $line): bool => $line['issues'] !== [])->values(),
        ]);
    }

    public function review(Request $request, PayrollRun $run): JsonResponse
    {
        [$line, $issue] = $this->find($request, $run);
        abort_if($issue['severity'] !== 'warning', 422, 'Blocking issues cannot be marked reviewed. Fix the cause and calculate again.');
        $note = $request->validate(['note' => ['required', 'string', 'min:3', 'max:500']], ['note.required' => 'Say why this is fine, e.g. “Agreed raise from March”.'])['note'];

        $line->forceFill(['reviewed_issues' => [...($line->reviewed_issues ?? []), $issue['key'] => [
            'by' => $request->user()->name, 'by_id' => $request->user()->id, 'at' => now()->toIso8601String(), 'note' => $note,
        ]]])->save();
        Activity::record('payroll.issue_reviewed', "Warning for {$line->employee_name} marked reviewed", $run, ['issue' => $issue['message'], 'note' => $note]);

        return $this->index($run);
    }

    public function unreview(Request $request, PayrollRun $run): JsonResponse
    {
        [$line, $issue] = $this->find($request, $run);
        $reviews = $line->reviewed_issues ?? [];
        unset($reviews[$issue['key']]);
        $line->forceFill(['reviewed_issues' => $reviews])->save();
        Activity::record('payroll.issue_review_removed', "Review of a warning for {$line->employee_name} removed", $run, ['issue' => $issue['message']]);

        return $this->index($run);
    }

    /**
     * @return array{0: PayrollRunEmployee, 1: array<string, mixed>}
     */
    private function find(Request $request, PayrollRun $run): array
    {
        abort_unless($run->isOpen(), 422, 'This run is no longer open for review.');
        $data = $request->validate(['line_id' => ['required', 'ulid'], 'key' => ['required', 'string', 'size:16']]);
        $line = $run->lines()->findOrFail($data['line_id']);
        $issue = collect($this->issues->forLine($line, $this->issues->previousGross($run)))->firstWhere('key', $data['key']);
        abort_if($issue === null, 404, 'That issue no longer appears on this line.');

        return [$line, $issue];
    }
}
