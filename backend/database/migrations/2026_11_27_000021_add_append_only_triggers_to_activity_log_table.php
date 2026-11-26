<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Requires the TRIGGER privilege. With binary logging enabled, MySQL may also
    // need log_bin_trust_function_creators = 1 (or SUPER) to create triggers.
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER `activity_log_block_update` BEFORE UPDATE ON `activity_log`
            FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activity_log is append-only: UPDATE is not allowed'
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER `activity_log_block_delete` BEFORE DELETE ON `activity_log`
            FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activity_log is append-only: DELETE is not allowed'
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `activity_log_block_update`');
        DB::unprepared('DROP TRIGGER IF EXISTS `activity_log_block_delete`');
    }
};
