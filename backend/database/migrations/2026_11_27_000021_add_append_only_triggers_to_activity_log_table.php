<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite is only used by the test suite; same guarantee, SQLite syntax.
            DB::unprepared("CREATE TRIGGER activity_log_block_update BEFORE UPDATE ON activity_log BEGIN SELECT RAISE(ABORT, 'activity_log is append-only: UPDATE is not allowed'); END");
            DB::unprepared("CREATE TRIGGER activity_log_block_delete BEFORE DELETE ON activity_log BEGIN SELECT RAISE(ABORT, 'activity_log is append-only: DELETE is not allowed'); END");

            return;
        }

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
