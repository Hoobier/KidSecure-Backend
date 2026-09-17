<?php
// config/school.php

return [
    /*
     * Ordered from lowest to highest. This single list drives both
     * "what grade comes next" and "is this the graduating grade".
     */
    'grade_levels' => [
        'Nursery',
        'Kindergarten',
        'Preparatory',
        'Grade 1',
        'Grade 2',
        'Grade 3',
        'Grade 4',
        'Grade 5',
        'Grade 6',
    ],

    /*
     * Subject display-name map. This is the source of truth for what a
     * subject code means to a human. Which subjects apply to which grade
     * lives in 'subjects_by_grade' below.
     */
    'subjects' => [
        // Elementary codes
        'CLVE'  => 'Christian Living / Values Education',
        'MATH'  => 'Mathematics',
        'SCI'   => 'Science',
        'FIL'   => 'Filipino',
        'MAPEH' => 'MAPEH',
        'EPP'   => 'Edukasyong Pantahanan at Praktikal',

        // Preschool codes
        'CL'    => 'Christian Living / Bible Studies',
        'COM'   => 'Communication Skills (English & Filipino)',
        'SEN'   => 'Sensory-Perceptual & Socio-Emotional Development',
    ],

    /*
     * Which subject codes are offered at each grade level.
     * Keyed by exact grade-level string from 'grade_levels' above.
     */
    'subjects_by_grade' => [
        'Nursery'      => ['CL', 'COM', 'MATH', 'SEN'],
        'Kindergarten' => ['CL', 'COM', 'MATH', 'SEN'],
        'Preparatory'  => ['CL', 'COM', 'MATH', 'SEN'],
        'Grade 1'      => ['CLVE', 'MATH', 'FIL', 'MAPEH', 'EPP'],
        'Grade 2'      => ['CLVE', 'MATH', 'FIL', 'MAPEH', 'EPP'],
        'Grade 3'      => ['CLVE', 'MATH', 'FIL', 'MAPEH', 'EPP'],
        'Grade 4'      => ['CLVE', 'MATH', 'SCI', 'FIL', 'MAPEH', 'EPP'],
        'Grade 5'      => ['CLVE', 'MATH', 'SCI', 'FIL', 'MAPEH', 'EPP'],
        'Grade 6'      => ['CLVE', 'MATH', 'SCI', 'FIL', 'MAPEH', 'EPP'],
    ],
];