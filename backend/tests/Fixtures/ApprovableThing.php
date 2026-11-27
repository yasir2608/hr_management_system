<?php

namespace Tests\Fixtures;

use App\Approvals\Approvable;
use App\Models\ApprovalRequest;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A stand-in for leave requests and the like, to test the approval engine on its own.
 */
class ApprovableThing extends Model implements Approvable
{
    use BelongsToCompany, HasUlids;

    protected $table = 'approvable_things';

    protected $guarded = [];

    public static function createTable(): void
    {
        Schema::create('approvable_things', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id');
            $table->string('outcome')->nullable();
            $table->timestamps();
        });
    }

    public function onApprovalDecided(ApprovalRequest $request): void
    {
        $this->forceFill(['outcome' => $request->status])->save();
    }
}
