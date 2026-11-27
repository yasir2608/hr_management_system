<?php

namespace App\Attendance;

use App\Models\CompanyPreference;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The company's optional rule for where people can clock in from:
 *
 * - none: anywhere
 * - network: only from the listed office networks (IP addresses or ranges)
 * - location: only near the employee's work location (the browser shares its position)
 *
 * People are always told the company's reason when they are refused.
 */
class LocationRestriction
{
    public function describe(): array
    {
        $preferences = CompanyPreference::current();

        return [
            'type' => $preferences->attendance_restriction ?? 'none',
            'message' => $preferences->attendance_restriction_message,
        ];
    }

    public function check(Employee $employee, Request $request): void
    {
        $preferences = CompanyPreference::current();
        $explanation = $preferences->attendance_restriction_message ?: 'Your company limits where you can clock in from.';

        match ($preferences->attendance_restriction ?? 'none') {
            'network' => $this->checkNetwork($request, $preferences->attendance_allowed_networks ?? [], $explanation),
            'location' => $this->checkLocation($employee, $request, $explanation),
            default => null,
        };
    }

    /**
     * @param  list<string>  $networks
     */
    private function checkNetwork(Request $request, array $networks, string $explanation): void
    {
        if ($networks === [] || ! IpUtils::checkIp((string) $request->ip(), $networks)) {
            throw ValidationException::withMessages(['clock' => "{$explanation} You are not on an approved network."]);
        }
    }

    private function checkLocation(Employee $employee, Request $request, string $explanation): void
    {
        $location = $employee->location;

        if ($location === null || $location->latitude === null || $location->longitude === null) {
            // Nothing to compare with: do not block people because setup is incomplete.
            return;
        }

        $latitude = $request->input('latitude');
        $longitude = $request->input('longitude');

        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            throw ValidationException::withMessages(['clock' => "{$explanation} Allow your browser to share your location to clock in."]);
        }

        $distance = self::distanceInMetres((float) $location->latitude, (float) $location->longitude, (float) $latitude, (float) $longitude);
        $radius = $location->clock_radius_m ?: 200;

        if ($distance > $radius) {
            throw ValidationException::withMessages(['clock' => sprintf('%s You are about %s from %s.', $explanation, $distance >= 1000 ? round($distance / 1000, 1).' km' : (int) $distance.' m', $location->name)]);
        }
    }

    public static function distanceInMetres(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earth = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
