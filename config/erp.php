<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Demo user password (local / DemoUserSeeder only)
    |--------------------------------------------------------------------------
    |
    | Set DEMO_USER_PASSWORD in .env for local demo user seeding. Never commit
    | a real password. Empty means DemoUserSeeder refuses to run.
    |
    */

    'demo_user_password' => env('DEMO_USER_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Draft PO proposal time-to-live (minutes)
    |--------------------------------------------------------------------------
    |
    | Pending purchase order proposals expire after this many minutes and can
    | no longer be confirmed. Override with ERP_PO_PROPOSAL_TTL_MINUTES in .env.
    |
    */

    'purchase_order_proposal_ttl_minutes' => (int) env('ERP_PO_PROPOSAL_TTL_MINUTES', 30),

];
