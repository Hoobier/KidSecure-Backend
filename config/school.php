<?php
// config/school.php

return [
    /*
     * Ordered from lowest to highest.
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
     * All subject codes the system knows about — both the ones teachers
     * grade (MU, AR, PE, H) and the ones the report card displays (MAPEH).
     */
    'subjects' => [
        'FIL'   => 'Filipino',
        'ENG'   => 'English',
        'MATH'  => 'Mathematics',
        'SCI'   => 'Science',
        'AP'    => 'Araling Panlipunan',
        'EPP'   => 'Edukasyong Pantahanan at Praktikal',
        'GMRC'  => 'Good Manners and Right Conduct',
        'MU'    => 'Music',
        'AR'    => 'Arts',
        'PE'    => 'Physical Education',
        'H'     => 'Health',
        'MAPEH' => 'MAPEH',
    ],

    /*
     * Codes teachers enter grades for, per grade level.
     * These are the codes stored in Student.reportCard as
     * reportCard[CODE][TERM] = { grade, status }.
     */
    'entry_subjects_by_grade' => [
        'Nursery'      => ['FIL', 'ENG', 'MATH', 'SCI', 'MU', 'AR', 'PE', 'H'],
        'Kindergarten' => ['FIL', 'ENG', 'MATH', 'SCI', 'MU', 'AR', 'PE', 'H'],
        'Preparatory'  => ['FIL', 'ENG', 'MATH', 'SCI', 'MU', 'AR', 'PE', 'H'],
        'Grade 1'      => ['FIL', 'ENG', 'MATH', 'SCI', 'AP', 'EPP', 'MU', 'AR', 'PE', 'H', 'GMRC'],
        'Grade 2'      => ['FIL', 'ENG', 'MATH', 'SCI', 'AP', 'EPP', 'MU', 'AR', 'PE', 'H', 'GMRC'],
        'Grade 3'      => ['FIL', 'ENG', 'MATH', 'SCI', 'AP', 'EPP', 'MU', 'AR', 'PE', 'H', 'GMRC'],
        'Grade 4'      => ['FIL', 'ENG', 'MATH', 'SCI', 'AP', 'EPP', 'MU', 'AR', 'PE', 'H', 'GMRC'],
        'Grade 5'      => ['FIL', 'ENG', 'MATH', 'SCI', 'AP', 'EPP', 'MU', 'AR', 'PE', 'H', 'GMRC'],
        'Grade 6'      => ['FIL', 'ENG', 'MATH', 'SCI', 'AP', 'EPP', 'MU', 'AR', 'PE', 'H', 'GMRC'],
    ],

    /*
     * Codes displayed on the report card, per grade level.
     * MAPEH replaces MU/AR/PE/H — its value is computed from those four.
     * Order here is the row order on the printed card.
     */
    'display_subjects_by_grade' => [
        'Nursery'      => ['FIL', 'ENG', 'MATH', 'SCI', 'MAPEH'],
        'Kindergarten' => ['FIL', 'ENG', 'MATH', 'SCI', 'MAPEH'],
        'Preparatory'  => ['FIL', 'ENG', 'MATH', 'SCI', 'MAPEH'],
        'Grade 1'      => ['FIL', 'ENG', 'MATH', 'SCI', 'AP', 'EPP', 'MAPEH', 'GMRC'],
        'Grade 2'      => ['FIL', 'ENG', 'MATH', 'SCI', 'AP', 'EPP', 'MAPEH', 'GMRC'],
        'Grade 3'      => ['FIL', 'ENG', 'MATH', 'SCI', 'AP', 'EPP', 'MAPEH', 'GMRC'],
        'Grade 4'      => ['FIL', 'ENG', 'MATH', 'SCI', 'AP', 'EPP', 'MAPEH', 'GMRC'],
        'Grade 5'      => ['FIL', 'ENG', 'MATH', 'SCI', 'AP', 'EPP', 'MAPEH', 'GMRC'],
        'Grade 6'      => ['FIL', 'ENG', 'MATH', 'SCI', 'AP', 'EPP', 'MAPEH', 'GMRC'],
    ],

    /*
     * Display codes that are computed from component entry codes.
     * Rounding is always ceiling (any decimal rounds up).
     */
    'computed_subjects' => [
        'MAPEH' => [
            'label'      => 'MAPEH',
            'components' => ['MU', 'AR', 'PE', 'H'],
            'rounding'   => 'ceil',
        ],
    ],

    /*
     * Descriptor table used for report card remarks.
     */
    'descriptors' => [
        ['min' => 90, 'max' => 100, 'label' => 'Advancing',    'letter' => 'A', 'remark' => 'Passed'],
        ['min' => 80, 'max' => 89,  'label' => 'Benchmarking', 'letter' => 'B', 'remark' => 'Passed'],
        ['min' => 75, 'max' => 79,  'label' => 'Connecting',   'letter' => 'C', 'remark' => 'Passed'],
        ['min' => 65, 'max' => 74,  'label' => 'Developing',   'letter' => 'D', 'remark' => 'Failed'],
        ['min' => 0,  'max' => 64,  'label' => 'Emerging',     'letter' => 'E', 'remark' => 'Failed'],
    ],
];