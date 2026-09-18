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
     * Subject display-name map. The source of truth for what a subject code
     * means to a human. Which subjects apply to which grade lives in
     * 'subjects_by_grade' below. MAPEH stays here even though it's no longer
     * a graded subject — archived report cards from before the split still
     * use it, and the display name is needed to render those.
     */
    'subjects' => [
        // Elementary codes
        'CLVE'  => 'Christian Living / Values Education',
        'MATH'  => 'Mathematics',
        'SCI'   => 'Science',
        'FIL'   => 'Filipino',
        'MAPEH' => 'MAPEH',
        'MA'    => 'Music & Arts',
        'PE'    => 'Physical Education',
        'H'     => 'Health',
        'EPP'   => 'Edukasyong Pantahanan at Praktikal',

        // Preschool codes
        'CL'    => 'Christian Living / Bible Studies',
        'COM'   => 'Communication Skills (English & Filipino)',
        'SEN'   => 'Sensory-Perceptual & Socio-Emotional Development',
    ],

    /*
     * Which subject codes are offered at each grade level, in display order.
     * MA/PE/H are the three MAPEH components — graded independently but
     * grouped visually under a "MAPEH" header (see 'subject_groups').
     */
    'subjects_by_grade' => [
        'Nursery'      => ['CL', 'COM', 'MATH', 'SEN'],
        'Kindergarten' => ['CL', 'COM', 'MATH', 'SEN'],
        'Preparatory'  => ['CL', 'COM', 'MATH', 'SEN'],
        'Grade 1'      => ['CLVE', 'MATH', 'FIL', 'MA', 'PE', 'H', 'EPP'],
        'Grade 2'      => ['CLVE', 'MATH', 'FIL', 'MA', 'PE', 'H', 'EPP'],
        'Grade 3'      => ['CLVE', 'MATH', 'FIL', 'MA', 'PE', 'H', 'EPP'],
        'Grade 4'      => ['CLVE', 'MATH', 'SCI', 'FIL', 'MA', 'PE', 'H', 'EPP'],
        'Grade 5'      => ['CLVE', 'MATH', 'SCI', 'FIL', 'MA', 'PE', 'H', 'EPP'],
        'Grade 6'      => ['CLVE', 'MATH', 'SCI', 'FIL', 'MA', 'PE', 'H', 'EPP'],
    ],

    /*
     * Display-grouping hints. Codes listed in a group render under a shared
     * header (with the group's label) instead of as flat rows. Groups are
     * display-only — every code remains an independent subject with its own
     * grade and status.
     */
    'subject_groups' => [
        'MAPEH' => [
            'label' => 'MAPEH',
            'codes' => ['MA', 'PE', 'H'],
        ],
    ],
];