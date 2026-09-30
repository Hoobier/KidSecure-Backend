<?php
// app/Http/Controllers/TermSettingController.php
namespace App\Http\Controllers;

use App\Models\TermSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TermSettingController extends Controller
{
    public function show()
    {
        $termSetting = TermSetting::current();

        return response()->json([
            'data' => $this->formatResponse($termSetting),
        ]);
    }

    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'schoolYearLabel' => 'sometimes|string|max:20',
            'terms' => 'sometimes|array|size:3',
            'terms.*.termNumber' => 'required_with:terms|integer|between:1,3',
            'terms.*.startDate' => 'nullable|date',
            'terms.*.endDate' => 'nullable|date',
            'monthlySchoolDays'         => 'nullable|array',
            'monthlySchoolDays.*'       => 'nullable|integer|min:0|max:31',
            'tardyCutoff'               => ['nullable', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'schoolDayCalendar'         => 'nullable|array',
            'schoolDayCalendar.*'       => 'nullable|array',
            'schoolDayCalendar.*.*'     => 'nullable|date_format:Y-m-d',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Please check the dates entered and try again.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->has('terms')) {
            foreach ($request->input('terms') as $term) {
                if (!empty($term['startDate']) && !empty($term['endDate'])
                    && $term['endDate'] < $term['startDate']) {
                    return response()->json([
                        'message' => "Term {$term['termNumber']}'s end date can't be before its start date.",
                    ], 422);
                }
            }
        }

        if ($request->has('schoolDayCalendar')) {
            $validMonths = config('school.school_months', []);
            foreach ($request->input('schoolDayCalendar') as $monthName => $dates) {
                if (!in_array($monthName, $validMonths, true)) {
                    return response()->json([
                        'message' => "Unknown month in calendar: {$monthName}.",
                    ], 422);
                }
                $seen = [];
                foreach ((array) $dates as $date) {
                    $parsed = \Carbon\Carbon::parse($date);
                    if ($parsed->format('F') !== $monthName) {
                        return response()->json([
                            'message' => "{$date} is not in {$monthName}.",
                        ], 422);
                    }
                    if (isset($seen[$date])) {
                        return response()->json([
                            'message' => "Duplicate date in {$monthName}: {$date}.",
                        ], 422);
                    }
                    $seen[$date] = true;
                }
            }
        }

        $termSetting = TermSetting::current();
        $termSetting->fill($request->only(['schoolYearLabel', 'terms']));
        // If a calendar is provided, monthlySchoolDays becomes a derived count
        // from it (single source of truth). Otherwise, keep the old explicit
        // value if one was sent (transitional fallback).
        if ($request->has('schoolDayCalendar')) {
            $calendar = $request->input('schoolDayCalendar');
            $derived = [];
            foreach ($calendar as $monthName => $dates) {
                $derived[$monthName] = is_array($dates) ? count($dates) : 0;
            }
            $termSetting->monthlySchoolDays = $derived;
        } elseif ($request->has('monthlySchoolDays')) {
            $termSetting->monthlySchoolDays = $request->input('monthlySchoolDays');
        }
        if ($request->has('tardyCutoff')) {
            $termSetting->tardyCutoff = $request->input('tardyCutoff');
        }
        if ($request->has('schoolDayCalendar')) {
            $termSetting->schoolDayCalendar = $request->input('schoolDayCalendar');
        }
        $termSetting->save();

        return response()->json([
            'message' => 'School term settings updated.',
            'data' => $this->formatResponse($termSetting),
        ]);
    }

    private function formatResponse(TermSetting $termSetting): array
    {
        return [
            'schoolYearLabel' => $termSetting->schoolYearLabel,
            'terms' => $termSetting->terms,
            'activeTermNumber' => $termSetting->activeTermNumber(),
            'rolloverStatus' => $termSetting->rolloverStatus,
            'rolloverCompletedAt' => $termSetting->rolloverCompletedAt?->format('Y-m-d'),
            'needsRollover' => $termSetting->needsRollover(),
            'monthlySchoolDays' => $termSetting->monthlySchoolDays ?? new \stdClass(),
            'schoolDayCalendar' => $termSetting->schoolDayCalendar ?? new \stdClass(),
            'tardyCutoff' => $termSetting->tardyCutoff ?? '08:00',
        ];
    }
}