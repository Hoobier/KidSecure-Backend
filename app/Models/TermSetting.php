<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class TermSetting extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'term_settings';

    protected $fillable = [
        'currentTermStartDate',
    ];

    protected $casts = [
        'currentTermStartDate' => 'date',
    ];

    /**
     * There should only ever be one TermSetting document. This fetches it,
     * creating a sensible default (today) the very first time it's needed
     * so the rest of the app never has to handle a "no settings yet" case.
     */
    public static function current(): self
    {
        $setting = self::first();

        if (!$setting) {
            $setting = self::create([
                'currentTermStartDate' => now()->startOfDay(),
            ]);
        }

        return $setting;
    }
}