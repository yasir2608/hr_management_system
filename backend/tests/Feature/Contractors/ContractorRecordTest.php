<?php

namespace Tests\Feature\Contractors;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class ContractorRecordTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
    }

    public function test_a_person_or_supplier_is_recorded_with_contacts_and_an_owner(): void
    {
        $id = $this->inCompany()->postJson('/api/contractors', [
            'kind' => 'supplier', 'name' => 'Brightside Studio', 'legal_name' => 'Brightside Studio LLP', 'email' => 'hello@brightside.example',
            'country_code' => 'GB', 'owner_user_id' => $this->member->id,
            'contacts' => [['name' => 'Jo Bright', 'role' => 'Director', 'email' => 'jo@brightside.example'], ['name' => 'Accounts', 'email' => 'ap@brightside.example']],
        ])->assertCreated()->json('id');

        $this->inCompany()->getJson("/api/contractors/{$id}")
            ->assertJsonPath('owner.name', $this->member->name)
            ->assertJsonPath('contacts.0.name', 'Jo Bright')
            ->assertJsonPath('contacts.0.is_primary', true);
        $this->assertDatabaseHas('activity_log', ['action' => 'contractor.created']);
    }

    public function test_company_defined_fields_are_validated_and_saved(): void
    {
        $this->inCompany()->putJson('/api/contractor-fields', ['fields' => [
            ['label' => 'Supplier number', 'type' => 'text', 'is_required' => true],
            ['label' => 'Insurance expiry', 'type' => 'date'],
            ['label' => 'Tier', 'type' => 'select', 'options' => ['gold', 'silver']],
        ]])->assertOk()->assertJsonPath('data.0.key', 'supplier_number');

        $this->inCompany()->postJson('/api/contractors', ['kind' => 'person', 'name' => 'Morgan King', 'custom_fields' => ['tier' => 'bronze', 'insurance_expiry' => 'soon']])
            ->assertJsonValidationErrors(['custom_fields.supplier_number', 'custom_fields.tier', 'custom_fields.insurance_expiry']);

        $this->inCompany()->postJson('/api/contractors', ['kind' => 'person', 'name' => 'Morgan King', 'custom_fields' => ['supplier_number' => 'S-77', 'tier' => 'gold', 'unknown' => 'dropped']])
            ->assertCreated()->assertJsonPath('custom_fields', ['supplier_number' => 'S-77', 'tier' => 'gold']);
    }

    public function test_the_owner_must_be_a_member_of_this_company(): void
    {
        $outsider = User::factory()->create();

        $this->inCompany()->postJson('/api/contractors', ['kind' => 'person', 'name' => 'X', 'owner_user_id' => $outsider->id])->assertJsonValidationErrors('owner_user_id');
    }

    public function test_the_list_can_be_searched_and_hides_inactive_records_by_default(): void
    {
        $this->inCompany()->postJson('/api/contractors', ['kind' => 'person', 'name' => 'Morgan King']);
        $this->inCompany()->postJson('/api/contractors', ['kind' => 'supplier', 'name' => 'Old Co', 'status' => 'inactive']);

        $this->inCompany()->getJson('/api/contractors')->assertJsonCount(1, 'data');
        $this->inCompany()->getJson('/api/contractors?status=inactive')->assertJsonPath('data.0.name', 'Old Co');
        $this->inCompany()->getJson('/api/contractors?search=morg')->assertJsonPath('data.0.name', 'Morgan King');
    }

    public function test_view_only_roles_cannot_change_contractors(): void
    {
        $payroll = $this->memberWithRole(Role::PAYROLL_ADMIN);

        $this->inCompany($payroll)->getJson('/api/contractors')->assertOk();
        $this->inCompany($payroll)->postJson('/api/contractors', ['kind' => 'person', 'name' => 'X'])->assertForbidden();
        $this->inCompany($this->memberWithRole(Role::EMPLOYEE))->getJson('/api/contractors')->assertForbidden();
    }
}
