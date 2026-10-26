<?php

return [

    // One-time gift credits granted to a newly eligible account (E08).
    // Set to 0 to disable the grant.
    'gift_credits' => (int) env('KM_GIFT_CREDITS', 0),

    // Default employee template provisioned for every new workspace.
    'default_employee_template' => 'product-prep',

];
