<?php

namespace App\Services;

use Cloudinary\Cloudinary;
use Illuminate\Http\UploadedFile;

class DocumentUploadService
{
    protected Cloudinary $cloudinary;

    // These are the only document types the system knows about.
    // Add new ones here later if the school needs another document type.
    public const ALLOWED_TYPES = [
        'birth_certificate',
        'id_photo',
        'form_138',
        'good_moral',
    ];

    public function __construct()
    {
        $this->cloudinary = new Cloudinary(env('CLOUDINARY_URL'));
    }

    /**
     * Uploads a file to Cloudinary under a private folder for this student,
     * and returns the data we'll save into the student's record.
     */
    public function upload(UploadedFile $file, string $studentId, string $documentType): array
    {
        $folder = "kidsecure/students/{$studentId}";

        $result = $this->cloudinary->uploadApi()->upload($file->getRealPath(), [
            'folder' => $folder,
            'public_id' => $documentType,
            'overwrite' => true,        // re-uploading the same type replaces the old file
            'resource_type' => 'auto',  // handles both images and PDFs automatically
            'type' => 'authenticated',  // NOT publicly accessible — needs a signed URL to view
        ]);

        return [
            'type' => $documentType,
            'status' => 'uploaded',
            'url' => $result['secure_url'],
            'public_id' => $result['public_id'],
            'resource_type' => $result['resource_type'],
            'original_filename' => $file->getClientOriginalName(),
            'uploaded_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Generates a temporary, signed link to actually view a private document.
     * Use this whenever the admin portal needs to show/download a document —
     * never store or reuse this link long-term, generate it fresh each time.
     */
    public function getSignedUrl(string $publicId, string $resourceType = 'image'): string
    {
        return $this->cloudinary->image($publicId)
            ->resourceType($resourceType)
            ->deliveryType('authenticated')
            ->signUrl(true)
            ->toUrl();
    }
}