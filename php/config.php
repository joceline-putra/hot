<?php

declare(strict_types=1);

return [
    'branch_id' => 1,
    'branch_session' => 'BRANCH-1',
    'api_url' => 'http://localhost/git/hot/api/v1/sync/',
    'api_key' => 'ABC',
    'db_path' => 'D:\Program Files\eLock Intelligent Lock Management System\eLock.mdb',
    'db_password' => 'eLock0618',
    'interval_seconds' => 60,
    'state_path' => '.\sync_state.json',
    'max_rows_per_request' => 500,
    'tables' => [
        ['source' => 'bill_info', 'target' => '_bill_info', 'primary_key' => 'id'],
        ['source' => 'bill_rooms', 'target' => '_bill_rooms', 'primary_key' => 'id'],
        ['source' => 'room_info', 'target' => '_room_info', 'primary_key' => 'id'],
        ['source' => 'employees', 'target' => '_employees', 'primary_key' => 'id'],
        ['source' => 'room_type', 'target' => '_room_type', 'primary_key' => 'id'],
        ['source' => 'make_card_record', 'target' => '_make_card_record', 'primary_key' => 'id'],
        ['source' => 'card_state', 'target' => '_card_state', 'primary_key' => 'id'],
    ],
];
