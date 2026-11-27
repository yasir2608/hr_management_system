<?php

namespace App\Http\Controllers;

use App\Access\Access;
use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The signed-in person's own notifications in this company. A notification that needs a permission the person no
 * longer holds is not shown.
 */
class NotificationController extends Controller
{
    private const LIMIT = 50;

    public function __construct(private readonly Access $access) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['filter' => ['nullable', 'in:all,unread']]);
        $items = $this->visible($this->own()->when(($data['filter'] ?? 'all') === 'unread', fn (Builder $query) => $query->whereNull('read_at'))->latest('created_at')->limit(self::LIMIT * 2)->get())
            ->take(self::LIMIT);

        return response()->json([
            'data' => $items->map(fn (UserNotification $notification): array => $this->present($notification))->values(),
            'unread_count' => $this->unreadCount(),
        ]);
    }

    public function count(): JsonResponse
    {
        return response()->json(['unread_count' => $this->unreadCount()]);
    }

    public function read(UserNotification $notification): JsonResponse
    {
        abort_unless($notification->user_id === auth()->id(), 404);
        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return response()->json(['unread_count' => $this->unreadCount()]);
    }

    public function readAll(): JsonResponse
    {
        $this->own()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['unread_count' => 0]);
    }

    /**
     * @return Builder<UserNotification>
     */
    private function own(): Builder
    {
        return UserNotification::query()->where('user_id', auth()->id());
    }

    private function unreadCount(): int
    {
        return $this->visible($this->own()->whereNull('read_at')->get(['id', 'permission']))->count();
    }

    /**
     * @param  Collection<int, UserNotification>  $notifications
     * @return Collection<int, UserNotification>
     */
    private function visible(Collection $notifications): Collection
    {
        return $notifications->filter(fn (UserNotification $notification): bool => $notification->permission === null || $this->access->can($notification->permission))->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(UserNotification $notification): array
    {
        return [
            ...$notification->only(['id', 'kind', 'title', 'body', 'link', 'action_required']),
            'read' => $notification->read_at !== null,
            'resolved' => $notification->resolved_at !== null,
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
