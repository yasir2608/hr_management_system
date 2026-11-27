<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Read-only system checks for M.Y HRMS staff. Nothing here changes the system; migrations are listed, never run.
 */
class HealthController extends Controller
{
    public function show(Migrator $migrator): JsonResponse
    {
        $checks = [
            'database' => $this->check(function (): array {
                $started = microtime(true);
                DB::select('select 1');
                $connection = DB::connection();

                return ['detail' => $connection->getDriverName().' · '.$connection->getDatabaseName(), 'ms' => round((microtime(true) - $started) * 1000, 1)];
            }),
            'cache' => $this->check(function (): array {
                $key = 'health-check-'.Str::random(8);
                Cache::put($key, 'ok', 10);
                $ok = Cache::pull($key) === 'ok';

                return ['ok' => $ok, 'detail' => config('cache.default').($ok ? ' · read and write work' : ' · read back failed')];
            }),
            'queue' => $this->check(function (): array {
                $driver = config('queue.connections.'.config('queue.default').'.driver');
                $failed = DB::table('failed_jobs')->count();
                $waiting = $driver === 'database' ? DB::table('jobs')->count() : null;
                $oldest = $driver === 'database' ? DB::table('jobs')->min('created_at') : null;
                $stuck = $oldest !== null && (int) $oldest < now()->subMinutes(15)->getTimestamp();

                return [
                    'ok' => $failed === 0 && ! $stuck,
                    'detail' => $driver.' · '.($waiting === null ? '' : "{$waiting} waiting · ")."{$failed} failed".($stuck ? ' · oldest job waiting over 15 minutes (is a worker running?)' : ''),
                ];
            }),
            'storage' => $this->check(function (): array {
                $path = storage_path('app');
                $free = @disk_free_space($path);
                $total = @disk_total_space($path);
                $ok = $free !== false && $total !== false && $total > 0 && $free / $total > 0.1;

                return ['ok' => $ok, 'detail' => $free === false ? 'Could not read disk space' : self::bytes((int) $free).' free of '.self::bytes((int) $total), 'writable' => is_writable($path)];
            }),
        ];

        $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];
        $files = array_keys($migrator->getMigrationFiles([database_path('migrations')]));
        $pending = array_values(array_diff($files, $ran));

        return response()->json([
            'checks' => $checks,
            'migrations' => ['ran' => count($ran), 'pending' => $pending],
            'failed_jobs' => DB::table('failed_jobs')->latest('failed_at')->limit(10)->get(['id', 'queue', 'failed_at', 'exception'])
                ->map(fn ($job): array => ['id' => $job->id, 'queue' => $job->queue, 'failed_at' => $job->failed_at, 'error' => Str::limit(strtok((string) $job->exception, "\n"), 300)]),
            'app' => [
                'name' => config('app.name'), 'environment' => app()->environment(), 'debug' => (bool) config('app.debug'),
                'version' => config('app.version') ?? $this->gitVersion(), 'laravel' => Application::VERSION, 'php' => PHP_VERSION,
                'timezone' => config('app.timezone'), 'checked_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * @param  callable(): array<string, mixed>  $probe
     * @return array<string, mixed>
     */
    private function check(callable $probe): array
    {
        try {
            return ['ok' => true, ...$probe()];
        } catch (Throwable $exception) {
            return ['ok' => false, 'detail' => Str::limit($exception->getMessage(), 200)];
        }
    }

    private function gitVersion(): ?string
    {
        $head = base_path('../.git/HEAD');
        if (! is_readable($head)) {
            return null;
        }
        $ref = trim((string) file_get_contents($head));
        if (str_starts_with($ref, 'ref: ')) {
            $file = base_path('../.git/'.substr($ref, 5));

            return is_readable($file) ? substr(trim((string) file_get_contents($file)), 0, 7) : null;
        }

        return substr($ref, 0, 7);
    }

    private static function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;

        return round($bytes / (1024 ** $power), 1).' '.$units[$power];
    }
}
