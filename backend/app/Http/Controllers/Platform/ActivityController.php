<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read-only log of what M.Y HRMS staff have done. Nothing here can be edited or removed.
 */
class ActivityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $entries = $this->filtered($request)->with(['actor:id,name,email', 'company:id,name', 'targetUser:id,name,email'])->paginate(50);
        $entries->getCollection()->transform(fn (PlatformActivity $entry): array => self::present($entry));

        return response()->json([
            ...$entries->toArray(),
            'actions' => PlatformActivity::query()->distinct()->orderBy('action')->pluck('action'),
            'staff' => User::query()->whereNotNull('platform_role')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $entries = $this->filtered($request)->with(['actor:id,name,email', 'company:id,name', 'targetUser:id,name,email'])->limit(10000)->get();
        PlatformActivity::record('platform.activity_exported', "Platform activity exported ({$entries->count()} entries)");

        return response()->streamDownload(function () use ($entries): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['When (UTC)', 'Staff', 'Action', 'What', 'Company', 'User', 'Reason', 'IP address'], ',', '"', '');
            foreach ($entries as $entry) {
                fputcsv($out, [
                    $entry->created_at->toDateTimeString(), $entry->actor?->email, $entry->action, $entry->description, $entry->company?->name,
                    $entry->targetUser?->email, $entry->properties['reason'] ?? null, $entry->ip_address,
                ], ',', '"', '');
            }
            fclose($out);
        }, 'platform-activity.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(PlatformActivity $entry): array
    {
        return [
            ...$entry->only(['id', 'action', 'description', 'properties', 'ip_address', 'created_at']),
            'actor' => $entry->actor?->only(['id', 'name', 'email']),
            'company' => $entry->company?->only(['id', 'name']),
            'target_user' => $entry->targetUser?->only(['id', 'name', 'email']),
        ];
    }

    /**
     * @return Builder<PlatformActivity>
     */
    private function filtered(Request $request): Builder
    {
        $request->validate([
            'action' => ['nullable', 'string', 'max:80'],
            'actor_user_id' => ['nullable', 'ulid'],
            'company_id' => ['nullable', 'ulid'],
            'target_user_id' => ['nullable', 'ulid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return PlatformActivity::query()
            ->when($request->filled('action'), fn ($query) => $query->where('action', $request->string('action')))
            ->when($request->filled('actor_user_id'), fn ($query) => $query->where('actor_user_id', $request->string('actor_user_id')))
            ->when($request->filled('company_id'), fn ($query) => $query->where('company_id', $request->string('company_id')))
            ->when($request->filled('target_user_id'), fn ($query) => $query->where('target_user_id', $request->string('target_user_id')))
            ->when($request->filled('from'), fn ($query) => $query->where('created_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($query) => $query->where('created_at', '<=', $request->date('to')->endOfDay()))
            ->when($request->filled('search'), fn ($query) => $query->where('description', 'like', '%'.$request->string('search').'%'))
            ->latest('created_at')->latest('id');
    }
}
