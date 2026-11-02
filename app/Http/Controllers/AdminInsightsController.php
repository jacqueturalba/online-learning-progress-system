<?php

namespace App\Http\Controllers;

use App\Support\AdminInsightsData;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminInsightsController extends Controller
{
    public function __invoke(Request $request, AdminInsightsData $insights)
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $today = CarbonImmutable::today();
        $from = isset($filters['date_from'])
            ? CarbonImmutable::parse($filters['date_from'])
            : $today->subWeeks(12)->startOfWeek();
        $to = isset($filters['date_to']) ? CarbonImmutable::parse($filters['date_to']) : $today;

        if ($to->lt($from)) {
            throw ValidationException::withMessages(['date_to' => 'The end date must be on or after the start date.']);
        }
        if ($from->diffInDays($to) > 366) {
            throw ValidationException::withMessages(['date_to' => 'The date range cannot exceed 367 days.']);
        }

        return response()->json(['insights' => $insights->build($from, $to)]);
    }
}
