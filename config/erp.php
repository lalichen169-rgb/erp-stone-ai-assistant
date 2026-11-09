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

    /*
    |--------------------------------------------------------------------------
    | Procurement / draft PO business rules
    |--------------------------------------------------------------------------
    */

    'procurement' => [
        'enforce_supplier_product_catalog' => filter_var(
            env('ERP_ENFORCE_SUPPLIER_PRODUCT_CATALOG', false),
            FILTER_VALIDATE_BOOL
        ),
        'require_active_supplier_product' => true,
        'allow_manual_unit_cost_when_catalog_missing' => filter_var(
            env('ERP_ALLOW_MANUAL_UNIT_COST', true),
            FILTER_VALIDATE_BOOL
        ),
        'require_catalog_unit_cost_when_configured' => true,
        'revalidate_catalog_on_confirm' => true,
        'max_line_quantity' => (int) env('ERP_MAX_PO_LINE_QUANTITY', 10000),
        'allow_zero_freight' => filter_var(env('ERP_ALLOW_ZERO_FREIGHT', true), FILTER_VALIDATE_BOOL),
        'require_line_destination_warehouse' => filter_var(
            env('ERP_REQUIRE_PO_LINE_WAREHOUSE', true),
            FILTER_VALIDATE_BOOL
        ),
        'revalidate_warehouse_on_confirm' => true,
        'pending_clarification_ttl_minutes' => (int) env('ERP_PROCUREMENT_CLARIFICATION_TTL_MINUTES', 30),
    ],

];
