<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Administrator Contact Email
    |--------------------------------------------------------------------------
    |
    | Shown on the login page's "Forgot Password" recovery screen so a
    | locked-out user knows who to contact - never used to perform a
    | password reset itself. Reuses the same ADMIN_EMAIL the database
    | seeder already provisions the primary Super Admin account with, so
    | there is exactly one place this address is configured, never two
    | that could drift apart.
    |
    */

    'admin_contact_email' => env('ADMIN_EMAIL', 'admin@gedi.finance'),

];
