<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompanyAccountRequest;
use App\Models\Location;
use App\Support\Activity;
use App\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LocationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Location::query()->orderByDesc('is_active')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $location = Location::query()->create($this->validated($request));
        Activity::record('location.created', "Location {$location->name} added", $location);

        return response()->json($location, 201);
    }

    public function update(Request $request, Location $location): JsonResponse
    {
        $location->fill($this->validated($request, $location))->save();
        Activity::record('location.updated', "Location {$location->name} updated", $location, Activity::changes($location));

        return response()->json($location);
    }

    /**
     * Deletes an unused location. Locations people have worked at are archived instead, to keep history.
     */
    public function destroy(Location $location): JsonResponse
    {
        $inUse = DB::table('employees')->where('current_location_id', $location->id)->exists()
            || DB::table('employee_location_history')->where('location_id', $location->id)->exists();

        if ($inUse) {
            return response()->json(['message' => 'This location is used in employee records. Archive it instead.', 'code' => 'IN_USE'], 409);
        }

        $location->delete();
        Activity::record('location.deleted', "Location {$location->name} deleted", $location);

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Location $location = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('locations')->where('company_id', app(CurrentCompany::class)->id())->ignore($location?->id)],
            'name' => ['required', 'string', 'max:255'],
            'country_code' => ['required', Rule::in(CompanyAccountRequest::COUNTRIES)],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'region' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'timezone' => ['nullable', 'timezone:all'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'clock_radius_m' => ['nullable', 'integer', 'between:50,10000'],
            'is_active' => ['boolean'],
        ], ['code.unique' => 'Another location already uses this code.'], ['country_code' => 'country']);
    }
}
