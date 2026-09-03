<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Services\DocumentUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DocumentController extends Controller
{
    protected DocumentUploadService $uploadService;

    public function __construct(DocumentUploadService $uploadService)
    {
        $this->uploadService = $uploadService;
    }

    public function store(Request $request, string $id)
    {
        $student = Student::find($id);

        if (!$student) {
            return response()->json([
                'message' => 'We couldn\'t find that student. Please refresh and try again.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'type' => 'required|in:' . implode(',', DocumentUploadService::ALLOWED_TYPES),
            'file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120', // 5MB
        ], [
            'type.required' => 'Please specify which document this is.',
            'type.in' => 'That is not a recognized document type.',
            'file.required' => 'Please choose a file to upload.',
            'file.mimes' => 'Only JPG, PNG, or PDF files are allowed.',
            'file.max' => 'This file is too large. Please upload a file smaller than 5MB.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $documentData = $this->uploadService->upload(
                $request->file('file'),
                $id,
                $request->input('type')
            );

            $student->upsertDocument($documentData);

            return response()->json([
                'message' => 'Document uploaded successfully.',
                'document' => $documentData,
            ]);
        } catch (\Exception $e) {
            \Log::error('Document upload failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Something went wrong while uploading. Please try again.',
            ], 500);
        }
    }
}