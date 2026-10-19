<?php

declare(strict_types=1);

// Copy to config.php, then replace every placeholder before making the site public.
return [
    'app' => [
        'name' => 'Tischreservierung',
        'timezone' => 'Europe/Berlin',
        'base_path' => '/reservation',
        'public_url' => 'https://example.org/reservation',
        'secret' => '', // At least 32 random bytes, encoded as hex.
        'admin_password_hash' => '', // Set with php app/reset-admin-password.php.
    ],
    'smtp' => [
        'host' => 'smtp.example.org',
        'port' => 587,
        'encryption' => 'starttls', // starttls or ssl
        'username' => '',
        'password' => '',
        'from_email' => 'reservations@example.org',
        'from_name' => 'Tischreservierung',
        'timeout' => 12,
    ],
    'defaults' => [
        'reservation_duration_minutes' => 120,
        'advance_days' => 14,
        'recipient_email' => '',
        'cancellation_enabled' => '1',
        'cancellation_cutoff_minutes' => '1440',
        'weekly_schedule' => [
            1 => ['enabled' => false, 'start' => '18:00', 'end' => '23:00', 'interval' => 30],
            2 => ['enabled' => true,  'start' => '18:00', 'end' => '23:00', 'interval' => 30],
            3 => ['enabled' => true,  'start' => '18:00', 'end' => '23:00', 'interval' => 30],
            4 => ['enabled' => true,  'start' => '18:00', 'end' => '23:00', 'interval' => 30],
            5 => ['enabled' => true,  'start' => '18:00', 'end' => '23:00', 'interval' => 30],
            6 => ['enabled' => true,  'start' => '18:00', 'end' => '23:00', 'interval' => 30],
            7 => ['enabled' => true,  'start' => '18:00', 'end' => '23:00', 'interval' => 30],
        ],
    ],
    // Example floor plan. Coordinates are percentages of the room plan.
    'tables' => [
        ['id' => 1, 'label' => '1', 'capacity' => 2, 'shape' => 'round', 'x' => 22, 'y' => 25],
        ['id' => 2, 'label' => '2', 'capacity' => 4, 'shape' => 'round', 'x' => 52, 'y' => 25],
        ['id' => 3, 'label' => '3', 'capacity' => 4, 'shape' => 'square', 'x' => 78, 'y' => 25],
        ['id' => 4, 'label' => '4', 'capacity' => 6, 'shape' => 'rect', 'x' => 35, 'y' => 65],
        ['id' => 5, 'label' => '5', 'capacity' => 2, 'shape' => 'round', 'x' => 75, 'y' => 65],
    ],
];
