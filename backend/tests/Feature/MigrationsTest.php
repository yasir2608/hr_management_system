<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_full_schema_builds_on_the_test_database(): void
    {
        foreach (['users', 'companies', 'company_users', 'roles', 'employees', 'invitations', 'role_assignments', 'activity_log'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table [{$table}].");
        }
    }

    public function test_activity_log_is_append_only(): void
    {
        DB::table('activity_log')->insert([
            'id' => '01JTESTACTIVITY0000000000A',
            'action' => 'test.created',
            'description' => 'Test entry',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('activity_log')->update(['description' => 'Changed']);
    }
}
