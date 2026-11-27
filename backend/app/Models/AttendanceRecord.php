<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id', 'work_date', 'clock_in_at', 'clock_in_timezone', 'clock_in_utc_offset', 'clock_out_at', 'clock_out_timezone',
    'clock_out_utc_offset', 'source', 'note', 'clock_in_ip', 'clock_out_ip',
])]
class AttendanceRecord extends Model
{
    use BelongsToCompany, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['clock_in_at' => 'immutable_datetime', 'clock_out_at' => 'immutable_datetime', 'work_date' => 'date:Y-m-d', 'clock_in_utc_offset' => 'integer', 'clock_out_utc_offset' => 'integer'];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Clock-in as local time where it was recorded (stored zone, falling back to the offset).
     */
    public function localClockIn(): ?CarbonImmutable
    {
        return self::toLocal($this->clock_in_at, $this->clock_in_timezone, $this->clock_in_utc_offset);
    }

    public function localClockOut(): ?CarbonImmutable
    {
        return self::toLocal($this->clock_out_at, $this->clock_out_timezone ?? $this->clock_in_timezone, $this->clock_out_utc_offset);
    }

    private static function toLocal(?CarbonImmutable $moment, ?string $timezone, ?int $offset): ?CarbonImmutable
    {
        if ($moment === null) {
            return null;
        }

        if ($offset !== null) {
            // The offset is what was true at the time, even if the zone's rules changed since.
            return $moment->utcOffset($offset);
        }

        return $timezone ? $moment->setTimezone($timezone) : $moment;
    }

    public function isOpen(): bool
    {
        return $this->clock_out_at === null;
    }

    /**
     * Worked minutes so far (until now for an open shift).
     */
    public function minutes(): int
    {
        return (int) $this->clock_in_at->diffInMinutes($this->clock_out_at ?? now());
    }
}
