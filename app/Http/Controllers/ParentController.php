<?php

namespace App\Http\Controllers;

use App\Models\ParentAccount;
use Illuminate\Http\Request;

class ParentController extends Controller
{
    public function search(Request $request)
    {
        $query = trim($request->query('q', ''));

        if (strlen($query) < 2) {
            return response()->json(['parents' => []]);
        }

        $parents = ParentAccount::where(function ($q) use ($query) {
                $q->where('firstName', 'like', "%{$query}%")
                  ->orWhere('lastName', 'like', "%{$query}%")
                  ->orWhere('email', 'like', "%{$query}%");
            })
            ->limit(10)
            ->get();

        $results = $parents->map(function ($parent) {
            return [
                'id' => (string) $parent->_id,
                'firstName' => $parent->firstName,
                'lastName' => $parent->lastName,
                'email' => $parent->email,
                'phone' => $parent->phone,
                'studentCount' => count($parent->studentIds ?? []),
            ];
        });

        return response()->json(['parents' => $results]);
    }
}