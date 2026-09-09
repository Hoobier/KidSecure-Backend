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

        $termSetting = TermSetting::current();
        $termSetting->fill($request->only(['schoolYearLabel', 'terms']));
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
        ];
    }
}