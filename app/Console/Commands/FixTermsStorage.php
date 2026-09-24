<?php
// app/Console/Commands/FixTermsStorage.php
namespace App\Console\Commands;

use App\Models\TermSetting;
use Illuminate\Console\Command;

class FixTermsStorage extends Command
{
    protected $signature = 'settings:fix-terms-storage';
    protected $description = 'One-time: convert TermSetting.terms from JSON-string storage to a native BSON array.';

    public function handle(): int
    {
        $setting = TermSetting::first();

        if (!$setting) {
            $this->info('No TermSetting document found. Nothing to do.');
            return self::SUCCESS;
        }

        $raw = $setting->getRawOriginal('terms');

        if ($raw === null) {
            $this->info('terms is null. Nothing to do.');
            return self::SUCCESS;
        }

        if (is_array($raw)) {
            $this->info('terms is already stored as a native array. Nothing to do.');
            return self::SUCCESS;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                $this->error("Could not decode terms string: " . substr($raw, 0, 200));
                return self::FAILURE;
            }

            $setting->terms = $decoded;
            $setting->save();

            $this->info('Fixed: terms converted from JSON string to native array.');
            return self::SUCCESS;
        }

        $this->error('Unexpected type for terms: ' . gettype($raw));
        return self::FAILURE;
    }
}
