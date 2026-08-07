<?php
// Central Business Configuration

return [
    'db' => [
        'host' => 'localhost',
        'name' => 'jeoczvkk_optimus',
        'user' => 'jeoczvkk_jeoczvkk',
        'pass' => 'pearl$Pearl$',
    ],

    // Packages: Tier values from $50 up to $1000000
    'packages' => [
        0, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000, 25000, 50000, 100000, 250000, 500000, 1000000
    ],

    // Level Percentages: 12-generation distribution
    'level_percentages' => [
        1 => 5, // Level 1: 5%
        2 => 2,  // Level 2: 2%
        3 => 2,  // Level 3: 2%
        4 => 1,  // Level 4: 1%
        5 => 1,  // Level 5: 1%
        6 => 1,  // Level 6: 1%
        7 => 0.50,  // Level 7: 0.50%
        8 => 0.50,  // Level 8: 0.50%
        9 => 0.50, // Level 9: 0.50%
        10 => 0.50, // Level 10: 0.50%
        11 => 0.50, // Level 11: 0.50%
        12 => 0.50, // Level 12: 0.50%
    ],

    // Rank Definitions: 12 specific ranks
    'ranks' => [
        ['name' => 'Mentor',       'matching' => 500,     'daily_income' => 2.50,  'days' => 100],
        ['name' => 'Pioneer',      'matching' => 1000,    'daily_income' => 5.00,  'days' => 100],
        ['name' => 'Elite',        'matching' => 2500,    'daily_income' => 12.50, 'days' => 100],
        ['name' => 'Titan',        'matching' => 5000,    'daily_income' => 25.00, 'days' => 100],
        ['name' => 'Master',       'matching' => 10000,   'daily_income' => 50.00, 'days' => 100],
        ['name' => 'Grand Master', 'matching' => 25000,   'daily_income' => 125.00, 'days' => 100],
        ['name' => 'Icon',         'matching' => 50000,   'daily_income' => 250.00, 'days' => 100],
        ['name' => 'Legend',       'matching' => 100000,  'daily_income' => 500.00, 'days' => 100],
        ['name' => 'Director',     'matching' => 250000,  'daily_income' => 1250.00, 'days' => 100],
        ['name' => 'Ambassador',   'matching' => 500000,  'daily_income' => 2500.00, 'days' => 100],
        ['name' => 'chairman',     'matching' => 1000000,  'daily_income' => 5000.00, 'days' => 100],
        ['name' => 'president',    'matching' => 2500000,  'daily_income' => 12500.00, 'days' => 100],
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
        'min_amount' => 5.00,
        'fee' => 5.00, // Flat "Gas fee"
    ],

    // Rank Income Settings
    'rank_income' => [
        'rate' => 0.50, // 0.50 daily rank income (often used as reference)
        'duration' => 100,
        'propagation_limit' => 2, // Hybrid propagation block-step limit
    ]
];
