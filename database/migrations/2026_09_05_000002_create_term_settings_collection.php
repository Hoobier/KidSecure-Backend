<?php
// database/migrations/2026_09_05_000002_create_term_settings_collection.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('term_settings', function (Blueprint $collection) {
            // No indexes needed — this collection will only ever hold one document.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('term_settings');
    }
};