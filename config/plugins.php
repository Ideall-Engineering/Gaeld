<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Plugin System Configuration
    |--------------------------------------------------------------------------
    */

    'enabled' => env('PLUGINS_ENABLED', false),

    'path' => base_path('plugins'),

    'namespace' => 'Plugins',

    /*
    |--------------------------------------------------------------------------
    | Deployment Allow-List
    |--------------------------------------------------------------------------
    |
    | Comma-separated plugin slugs. When set, PLUGINS_ENABLED=true activates
    | only these plugins instead of every plugin found under `path`. Leave
    | empty (the default) to activate every discovered, compatible plugin —
    | unchanged behavior for existing single-plugin deployments.
    |
    */
    'allowed_slugs' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PLUGINS_ALLOWED', '')),
    ))),

];
