<?php
// database/migrations/2026_09_05_000001_create_enrollment_applications_collection.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_applications', function (Blueprint $collection) {
            $collection->unique('referenceNumber');
            $collection->index('status');
            $collection->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_applications');
    }
};