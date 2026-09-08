<?php
// app/Models/TermSetting.php
namespace App\Models;

use Carbon\Carbon;
use MongoDB\Laravel\Eloquent\Model;

class TermSetting extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'term_settings';

    protected $fillable = [
        'schoolYearLabel',
        'terms',
        'rolloverStatus',
        'rolloverCompletedAt',
    ];

    protected $casts = [
        'terms' => 'array',
        'rolloverCompletedAt' => 'date',
    ];

    /**
     * There should only ever be one TermSetting document. This fetches it,
     * creating a sensible default the very first time it's needed so the
     * rest of the app never has to handle a "no settings yet" case.
     *
     * Term start/end dates are stored as plain 'Y-m-d' strings inside the
     * `terms` array (not BSON dates) — this sidesteps the nested-date-cast
     * issues we've hit before with MongoDB array fields. Carbon::parse()
     * handles them fine wherever we need to compare against "today".
     */
    public static function current(): self
    {
        $setting = self::first();

        if (!$setting) {
            $today = now()->startOfDay()->format('Y-m-d');

            $setting = self::create([
                'schoolYearLabel' => now()->format('Y') . '-' . now()->addYear()->format('Y'),
                'terms' => [
                    ['termNumber' => 1, 'startDate' => $today, 'endDate' => null],
                    ['termNumber' => 2, 'startDate' => null, 'endDate' => null],
                    ['termNumber' => 3, 'startDate' => null, 'endDate' => null],
                ],
                'rolloverStatus' => 'not_started',
                'rolloverCompletedAt' => null,
            ]);
        }

        return $setting;
    }

    /**
     * The date the whole school year began — Term 1's start date. This is
     * what the stale-enrollment-cleanup command should key off, since guest
     * applications are for the upcoming school year, not a specific term.
     */
    public function schoolYearStartDate(): ?Carbon
    {
        $term1 = collect($this->terms)->firstWhere('termNumber', 1);

        return $term1 && $term1['startDate'] ? Carbon::parse($term1['startDate']) : null;
    }

    /**
     * Term 3's end date — the actual end of the school year. This is what
     * the dashboard checks to decide whether to show the rollover banner.
     */
    public function schoolYearEndDate(): ?Carbon
    {
        $term3 = collect($this->terms)->firstWhere('termNumber', 3);

        return $term3 && $term3['endDate'] ? Carbon::parse($term3['endDate']) : null;
    }

    /**
     * Which term "today" falls into, based on each term's start/end dates.
     * Returns null if today is outside all three ranges (e.g. summer break,
     * or a term's dates simply haven't been set yet).
     */
    public function activeTermNumber(): ?int
    {
        $today = now()->startOfDay();

        foreach ($this->terms as $term) {
            if (!$term['startDate'] || !$term['endDate']) {
                continue;
            }

            if ($today->betweenIncluded(Carbon::parse($term['startDate']), Carbon::parse($term['endDate']))) {
                return $term['termNumber'];
            }
        }

        return null;
    }

    /**
     * True when Term 3 has ended and nobody has run the rollover yet —
     * this is exactly the condition the dashboard banner checks for.
     */
    public function needsRollover(): bool
    {
        $endDate = $this->schoolYearEndDate();

        return $this->rolloverStatus === 'not_started'
            && $endDate !== null
            && now()->startOfDay()->greaterThan($endDate);
    }

    // add to app/Models/TermSetting.php

    public function nextSchoolYearLabel(): string
    {
        if (!preg_match('/^(\d{4})-(\d{4})$/', $this->schoolYearLabel, $matches)) {
            $year = (int) now()->format('Y');
            return "{$year}-" . ($year + 1);
        }

        return (((int) $matches[1]) + 1) . '-' . (((int) $matches[2]) + 1);
    }
}