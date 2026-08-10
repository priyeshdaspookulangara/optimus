<?php
// Central Business Configuration

return [
    'db' => [
        'host' => '127.0.0.1',
        'name' => 'mlm_app',
        'user' => 'root',
        'pass' => '',
    ],

    // Packages: Tier values from $25 up to $1,000,000
    'packages' => [
        25, 50, 100, 250, 500, 1000, 2500, 5000, 10000, 25000, 50000, 100000, 250000, 500000, 1000000
    ],

    // Level Percentages: 12-generation distribution (Level 1: 5%, Levels 2-3: 2%, Levels 4-6: 1%, Levels 7-12: 0.50%)
    'level_percentages' => [
        1 => 5.0,  // Level 1: 5%
        2 => 2.0,  // Level 2: 2%
        3 => 2.0,  // Level 3: 2%
        4 => 1.0,  // Level 4: 1%
        5 => 1.0,  // Level 5: 1%
        6 => 1.0,  // Level 6: 1%
        7 => 0.5,  // Level 7: 0.5%
        8 => 0.5,  // Level 8: 0.5%
        9 => 0.5,  // Level 9: 0.5%
        10 => 0.5, // Level 10: 0.5%
        11 => 0.5, // Level 11: 0.5%
        12 => 0.5, // Level 12: 0.5%
    ],

    // Rank Definitions: 12 specific ranks
    'ranks' => [
        ['name' => 'Mentor',       'matching' => 500,     'daily_income' => 0.25,  'days' => 100],
        ['name' => 'Pioneer',      'matching' => 1000,    'daily_income' => 2.50,  'days' => 100],
        ['name' => 'Elite',        'matching' => 2500,    'daily_income' => 6.25,  'days' => 100],
        ['name' => 'Titan',        'matching' => 5000,    'daily_income' => 12.50, 'days' => 100],
        ['name' => 'Master',       'matching' => 10000,   'daily_income' => 25.00, 'days' => 100],
        ['name' => 'Grand Master', 'matching' => 25000,   'daily_income' => 62.50, 'days' => 100],
        ['name' => 'Icon',         'matching' => 50000,   'daily_income' => 125.00, 'days' => 100],
        ['name' => 'Legend',       'matching' => 100000,  'daily_income' => 250.00, 'days' => 100],
        ['name' => 'Director',     'matching' => 250000,  'daily_income' => 625.00, 'days' => 100],
        ['name' => 'Ambassador',   'matching' => 500000,  'daily_income' => 1250.00, 'days' => 100],
        ['name' => 'Chairman',     'matching' => 1000000, 'daily_income' => 4000.00, 'days' => 100],
        ['name' => 'President',    'matching' => 2500000, 'daily_income' => 10000.00, 'days' => 100],
    ],

    // ROI Settings
    'roi' => [
        'daily_rate' => 0.0050, // 0.50%
        'max_days' => 400,
        'cap_multiplier' => 2.0, // 200%
    ],

    // Total ID Cap
    'id_cap_multiplier' => 3.0, // 300% (Total earnings including bonuses)

    // Withdrawal Settings
    'withdrawal' => [
        'min_amount' => 25.00,
        'fee' => 10.00, // Flat "Gas fee"
    ],

    // Rank Income Settings
    'rank_income' => [
        'rate' => 0.0040, // 0.40% daily rank income (often used as reference)
        'duration' => 100,
    ]
];
