<?php

namespace Tests\Feature\Activity;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Support\Activity;
use App\Support\ActivityChain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class ActivityHistoryLogTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
    }

    private function record(string $action, array $properties = []): ActivityLog
    {
        return $this->asCompany(fn () => Activity::record($action, "Test {$action}", null, $properties));
    }

    public function test_entries_are_chained_and_the_chain_verifies(): void
    {
        $first = $this->record('one');
        $second = $this->record('two');
        $this->record('three');

        $this->assertSame($first->hash, $second->previous_hash);
        $this->assertSame(['intact' => true, 'checked' => 3, 'broken_entry_id' => null], ActivityChain::verify($this->company->id));
    }

    public function test_a_tampered_entry_is_detected(): void
    {
        $this->record('one');
        $second = $this->record('two', ['note' => 'original']);
        $this->record('three');

        // Simulate a direct database edit that got past the triggers.
        DB::unprepared('DROP TRIGGER activity_log_block_update');
        DB::table('activity_log')->where('id', $second->id)->update(['description' => 'Rewritten']);

        $this->assertSame(['intact' => false, 'checked' => 2, 'broken_entry_id' => $second->id], ActivityChain::verify($this->company->id));
    }

    public function test_the_application_refuses_to_change_or_delete_entries(): void
    {
        $entry = $this->record('one');

        $this->expectException(LogicException::class);
        $entry->forceFill(['description' => 'x'])->save();
    }

    public function test_sensitive_values_are_masked_at_any_depth(): void
    {
        $entry = $this->record('pay.changed', [
            'changes' => ['amount' => ['old' => 50000, 'new' => 56000], 'job_title' => ['old' => 'A', 'new' => 'B']],
            'bank' => ['account_number' => '12345678'],
            'ni_number' => 'AB123456C',
        ]);

        $stored = json_encode(ActivityLog::query()->findOrFail($entry->id)->properties);

        foreach (['50000', '56000', '12345678', 'AB123456C'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
        }
        $this->assertStringContainsString('"new":"B"', $stored);
    }

    public function test_chains_are_kept_per_company(): void
    {
        $this->record('one');
        $other = Company::factory()->create();
        $foreign = $this->asCompany(fn () => Activity::record('x', 'Other company'), $other);

        $this->assertNull($foreign->previous_hash);
        $this->assertTrue(ActivityChain::verify($other->id)['intact']);
    }

    public function test_the_integrity_check_is_available_to_people_who_can_view_history(): void
    {
        $this->record('one');

        $this->inCompany()->getJson('/api/activity/integrity')->assertOk()->assertJsonPath('intact', true);
        $this->inCompany($this->memberWithRole('employee'))->getJson('/api/activity/integrity')->assertForbidden();
    }
}
