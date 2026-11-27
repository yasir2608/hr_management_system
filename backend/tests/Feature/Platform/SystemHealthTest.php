<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_see_database_cache_queue_and_storage_checks_and_migration_status(): void
    {
        $response = $this->actingAs(User::factory()->create(['platform_role' => 'support']))->getJson('/api/platform/health')
            ->assertOk()
            ->assertJsonPath('checks.database.ok', true)
            ->assertJsonPath('checks.cache.ok', true)
            ->assertJsonPath('checks.queue.ok', true)
            ->assertJsonPath('migrations.pending', [])
            ->assertJsonStructure(['checks' => ['storage' => ['ok', 'detail']], 'app' => ['environment', 'laravel', 'php']]);

        $this->assertGreaterThan(0, $response->json('migrations.ran'));
    }

    public function test_failed_jobs_mark_the_queue_as_unhealthy_and_are_listed(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) str()->uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}',
            'exception' => "RuntimeException: Mail server refused the connection\n#0 trace", 'failed_at' => now(),
        ]);

        $this->actingAs(User::factory()->create(['platform_role' => 'ops']))->getJson('/api/platform/health')
            ->assertJsonPath('checks.queue.ok', false)
            ->assertJsonPath('failed_jobs.0.error', 'RuntimeException: Mail server refused the connection');
    }

    public function test_customers_cannot_see_system_health(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/platform/health')->assertForbidden();
    }
}
