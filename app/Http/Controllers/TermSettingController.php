<?php

namespace App\Http\Controllers;

use App\Models\TermSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TermSettingController extends Controller
{
    public function show()
    {
        $term = TermSetting::current();

        return response()->json([
            'data' => [
                'currentTermStartDate' => $term->currentTermStartDate?->format('Y-m-d'),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'currentTermStartDate' => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Please provide a valid date.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $term = TermSetting::current();
        $term->currentTermStartDate = $request->input('currentTermStartDate');
        $term->save();

        return response()->json([
            'message' => 'Term start date updated.',
            'data' => [
                'currentTermStartDate' => $term->currentTermStartDate->format('Y-m-d'),
            ],
        ]);
    }
}