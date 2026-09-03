<?php

namespace App\Http\Controllers;

use App\Services\DocumentUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EnrollmentDraftDocumentController extends Controller
{
    protected DocumentUploadService $uploadService;

    public function __construct(DocumentUploadService $uploadService)
    {
        $this->uploadService = $uploadService;
    }

    public function store(Request $request, string $draftId)
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:' . implode(',', DocumentUploadService::ALLOWED_TYPES),
            'file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
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
                $draftId,
                $request->input('type'),
                'enrollment-drafts'
            );

            return response()->json([
                'message' => 'Document uploaded successfully.',
                'document' => $documentData,
            ]);
        } catch (\Exception $e) {
            \Log::error('Draft document upload failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Something went wrong while uploading. Please try again.',
            ], 500);
        }
    }

    /**
     * Re-generates a fresh signed URL for a document already uploaded during this
     * enrollment session — used when the wizard is restored after a page refresh,
     * since the previous signed link may have expired.
     */
    public function show(Request $request, string $draftId, string $type)
    {
        $resourceType = $request->query('resource_type', 'image');
        $publicId = "kidsecure/enrollment-drafts/{$draftId}/{$type}";

        try {
            $signedUrl = $this->uploadService->getSignedUrl($publicId, $resourceType);

            return response()->json(['view_url' => $signedUrl]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Could not generate a link for this document.',
            ], 404);
        }
    }
}