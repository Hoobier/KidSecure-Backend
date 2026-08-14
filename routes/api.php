<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\ParentController;

Route::post('/login', [AuthController::class, 'login']);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/students', [StudentController::class, 'index']);
    Route::post('/students', [StudentController::class, 'store']);
    Route::get('/students/{id}', [StudentController::class, 'show']);
    Route::post('/students/{id}/resend-credentials', [StudentController::class, 'resendCredentials']);
    Route::post('/students/{id}/deactivate', [StudentController::class, 'deactivate']);
    Route::get('/parents/search', [ParentController::class, 'search']);
    Route::patch('/students/{id}', [StudentController::class, 'update']);
    Route::get('/attendance-logs', [AttendanceController::class, 'index']);
    Route::get('/attendance-logs/filter-options', [AttendanceController::class, 'filterOptions']);
    Route::post('/students/{id}/reactivate', [StudentController::class, 'reactivate']);
    Route::get('/parents', [ParentController::class, 'index']);
    Route::post('/parents/{id}/resend-credentials', [ParentController::class, 'resendCredentials']);
    Route::post('/students/{id}/reassign-rfid', [StudentController::class, 'reassignRfid']);
    Route::post('/students/{id}/reassign-parent', [StudentController::class, 'reassignParent']);
});