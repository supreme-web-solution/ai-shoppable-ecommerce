<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform admin emails
    |--------------------------------------------------------------------------
    |
    | Comma-separated list in ADMIN_EMAILS. Only these users can access
    | platform user management and related admin-only routes.
    |
    */

    'emails' => array_values(array_filter(array_map(
        static fn (string $email): string => mb_strtolower(trim($email)),
        explode(',', (string) env('ADMIN_EMAILS', '')),
    ))),

];
