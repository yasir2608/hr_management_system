<?php

namespace Tests\Feature\Onboarding;

use App\Models\OnboardingTemplate;
use App\Models\Role;
use App\Onboarding\DefaultTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class OnboardingTemplatesTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
        DefaultTemplate::createFor($this->company);
    }

    /**
     * @return array<string, mixed>
     */
    private function engineering(): array
    {
        return ['name' => 'Engineering onboarding', 'tasks' => [
            ['title' => 'Repository access', 'owner_type' => 'it', 'due_offset_days' => -1, 'is_required' => true],
            ['title' => 'Add your bank details', 'owner_type' => 'employee', 'due_offset_days' => 3, 'is_required' => true, 'employee_field_group' => 'bank'],
            ['title' => 'Pair with a buddy', 'owner_type' => 'manager', 'due_offset_days' => 5, 'is_required' => false],
        ]];
    }

    public function test_new_companies_get_a_default_checklist(): void
    {
        $response = $this->postJson('/api/auth/register-company', [
            'company' => ['name' => 'Fresh', 'country_code' => 'GB'],
            'admin' => ['name' => 'Fay', 'email' => 'fay@fresh.example', 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1'],
        ]);
        $companyId = $response->json('user.companies.0.id');

        $this->assertDatabaseHas('onboarding_templates', ['company_id' => $companyId, 'is_default' => true, 'name' => 'Standard onboarding']);
        $this->assertSame(count(DefaultTemplate::TASKS), DB::table('onboarding_template_tasks')->where('company_id', $companyId)->count());
    }

    public function test_hr_creates_a_company_specific_checklist_in_order(): void
    {
        $this->inCompany($this->memberWithRole(Role::HR_ADMIN))->postJson('/api/onboarding/templates', $this->engineering())
            ->assertCreated()
            ->assertJsonPath('tasks.0.title', 'Repository access')
            ->assertJsonPath('tasks.1.employee_field_group', 'bank')
            ->assertJsonPath('tasks.2.is_required', false);
    }

    public function test_a_checklist_can_be_edited_and_made_the_default(): void
    {
        $id = $this->inCompany()->postJson('/api/onboarding/templates', $this->engineering())->json('id');

        $this->inCompany()->putJson("/api/onboarding/templates/{$id}", [...$this->engineering(), 'tasks' => [['title' => 'Only task', 'owner_type' => 'hr', 'due_offset_days' => 0, 'is_required' => true]]])
            ->assertJsonCount(1, 'tasks');
        $this->inCompany()->postJson("/api/onboarding/templates/{$id}/default")->assertOk();

        $this->asCompany(fn () => $this->assertSame([$id], OnboardingTemplate::query()->where('is_default', true)->pluck('id')->all()));
    }

    public function test_the_default_cannot_be_removed_and_tasks_are_validated(): void
    {
        $default = $this->asCompany(fn () => OnboardingTemplate::query()->where('is_default', true)->firstOrFail());

        $this->inCompany()->deleteJson("/api/onboarding/templates/{$default->id}")->assertJsonPath('code', 'DEFAULT_TEMPLATE');
        $this->inCompany()->postJson('/api/onboarding/templates', ['name' => 'Empty', 'tasks' => []])->assertJsonValidationErrors('tasks');
        $this->inCompany()->postJson('/api/onboarding/templates', ['name' => 'Bad', 'tasks' => [['title' => 'x', 'owner_type' => 'cat', 'due_offset_days' => 0, 'is_required' => true]]])
            ->assertJsonValidationErrors('tasks.0.owner_type');
    }

    public function test_managers_cannot_manage_checklists(): void
    {
        $this->inCompany($this->memberWithRole(Role::MANAGER))->getJson('/api/onboarding/templates')->assertForbidden();
    }
}
