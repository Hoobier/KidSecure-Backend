<?php
// config/school.php

return [
    /**
     * Ordered from lowest to highest. This single list drives both
     * "what grade comes next" and "is this the graduating grade" — if
     * RCAC ever adds a grade level, only this array changes.
     */
    'grade_levels' => [
        'Kindergarten',
        'Grade 1',
        'Grade 2',
        'Grade 3',
        'Grade 4',
        'Grade 5',
        'Grade 6',
    ],
];