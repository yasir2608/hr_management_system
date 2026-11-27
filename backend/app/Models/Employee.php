<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An employee record. Sections:
 * - personal: names, contact, date of birth, address, emergency contact
 * - employment: number, dates, job, department, team, manager, employment type, status
 * - work arrangement: location, onsite/remote/hybrid, country of employment
 * - pay: compensation history (sensitive)
 * - country details: tax identifiers per country (sensitive) and bank accounts (sensitive)
 * - documents and history
 *
 * The current_* columns mirror the latest row of each *_history table and are written
 * together with it (see App\Employees\EmployeeRecord).
 */
#[Fillable([
    'user_id', 'employee_number', 'first_name', 'last_name', 'preferred_name', 'work_email', 'personal_email', 'phone',
    'date_of_birth', 'gender', 'nationality', 'address_line1', 'address_line2', 'city', 'region', 'postal_code', 'address_country',
    'emergency_contact_name', 'emergency_contact_relationship', 'emergency_contact_phone',
    'start_date', 'end_date', 'current_job_title', 'current_employment_type', 'current_department_id', 'current_team_id',
    'current_manager_id', 'current_location_id', 'current_work_arrangement', 'work_country', 'current_status',
])]
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use BelongsToCompany, HasFactory, HasUlids, SoftDeletes;

    public const STATUS_INVITED = 'invited';

    public const STATUS_ONBOARDING = 'onboarding';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ON_LEAVE = 'on_leave';

    public const STATUS_ENDED = 'ended';

    public const STATUSES = [self::STATUS_INVITED, self::STATUS_ONBOARDING, self::STATUS_ACTIVE, self::STATUS_ON_LEAVE, self::STATUS_ENDED];

    public const EMPLOYMENT_TYPES = ['full_time', 'part_time', 'fixed_term', 'intern'];

    public const WORK_ARRANGEMENTS = ['onsite', 'remote', 'hybrid'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['date_of_birth' => 'date:Y-m-d', 'start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d'];
    }

    public function fullName(): string
    {
        return trim(($this->preferred_name ?: $this->first_name).' '.$this->last_name);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'current_manager_id');
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function directReports(): HasMany
    {
        return $this->hasMany(Employee::class, 'current_manager_id');
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'current_department_id');
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'current_location_id');
    }

    /**
     * @return HasMany<EmployeeJobHistory, $this>
     */
    public function jobHistory(): HasMany
    {
        return $this->hasMany(EmployeeJobHistory::class)->orderByDesc('valid_from');
    }

    /**
     * @return HasMany<EmployeeManagerHistory, $this>
     */
    public function managerHistory(): HasMany
    {
        return $this->hasMany(EmployeeManagerHistory::class)->orderByDesc('valid_from');
    }

    /**
     * @return HasMany<EmployeeLocationHistory, $this>
     */
    public function locationHistory(): HasMany
    {
        return $this->hasMany(EmployeeLocationHistory::class)->orderByDesc('valid_from');
    }

    /**
     * @return HasMany<EmployeeStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(EmployeeStatusHistory::class)->orderByDesc('valid_from');
    }

    /**
     * @return HasMany<EmployeeCompensation, $this>
     */
    public function compensationHistory(): HasMany
    {
        return $this->hasMany(EmployeeCompensation::class)->orderByDesc('valid_from');
    }

    /**
     * @return HasOne<EmployeeCompensation, $this>
     */
    public function currentCompensation(): HasOne
    {
        return $this->hasOne(EmployeeCompensation::class)->whereNull('valid_to');
    }

    /**
     * The payroll group the employee is paid in (set by payroll, not part of the HR record).
     *
     * @return BelongsTo<PayrollGroup, $this>
     */
    public function payrollGroup(): BelongsTo
    {
        return $this->belongsTo(PayrollGroup::class);
    }

    /**
     * @return HasMany<EmployeeTaxDetail, $this>
     */
    public function taxDetails(): HasMany
    {
        return $this->hasMany(EmployeeTaxDetail::class);
    }

    /**
     * @return HasMany<EmployeeBankAccount, $this>
     */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(EmployeeBankAccount::class);
    }

    /**
     * @return MorphMany<Document, $this>
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
