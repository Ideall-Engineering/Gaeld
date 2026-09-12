<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | Control which features are enabled in this installation.
    | Community Edition disables SaaS-only features by default.
    |
    */

    // Monitoring
    'schedule_heartbeat_url' => env('SCHEDULE_HEARTBEAT_URL'),

    // CE features (enabled by default)
    'bank_import' => env('FEATURE_BANK_IMPORT', true),

    // Public self-registration via /register. Disable on single-tenant or
    // admin-only installations; the initial /setup wizard stays available so
    // an empty installation can still be provisioned.
    'self_registration' => env('SELF_REGISTRATION_ENABLED', true),

    // Per-organization toggleable modules — defaults preserve current behavior.
    // Owners override these from Settings → Modules.
    'budgets' => env('FEATURE_BUDGETS', true),
    'year_end_closing' => env('FEATURE_YEAR_END_CLOSING', true),
    'social_charges' => env('FEATURE_SOCIAL_CHARGES', true),
    'account_matching' => env('FEATURE_ACCOUNT_MATCHING', true),
    'fiduciary_export' => env('FEATURE_FIDUCIARY_EXPORT', true),
    'legal_archives' => env('FEATURE_LEGAL_ARCHIVES', true),
    'assets' => env('FEATURE_ASSETS', true),
    'payroll' => env('FEATURE_PAYROLL', true),

    // Cookie consent banner and the floating button that reopens its
    // preferences. An installation that sets only technically necessary cookies
    // — the session and the CSRF token — needs no consent for them, and then
    // the banner asks a question it does not have to ask.
    'cookie_consent' => env('FEATURE_COOKIE_CONSENT', true),

    // The floating camera button that scans a receipt straight into an expense,
    // shown on the dashboard and the expense screens.
    'quick_receipt' => env('FEATURE_QUICK_RECEIPT', true),

    // Integration features available in Community and SaaS editions
    'api_access' => env('FEATURE_API_ACCESS', true),

    // EE features (disabled by default)
    'auto_reconciliation' => env('FEATURE_AUTO_RECONCILIATION', false),
    'bank_sync' => env('FEATURE_BANK_SYNC', false),
    'saas' => env('FEATURE_SAAS', false),
    'automation' => env('FEATURE_AUTOMATION', false),
    'multi_currency' => env('FEATURE_MULTI_CURRENCY', false),
    'rule_engine' => env('FEATURE_RULE_ENGINE', false),

    // Lets rules marked auto_apply actually write without confirmation. Kept
    // separate from 'rule_engine' so the rule table can express automation long
    // before the installation is willing to act on it.
    'rule_engine_auto_apply' => env('FEATURE_RULE_ENGINE_AUTO_APPLY', false),
    'advanced_permissions' => env('FEATURE_ADVANCED_PERMISSIONS', false),
    'analytical' => env('FEATURE_ANALYTICAL', false),
    'withholding_tax' => env('FEATURE_WITHHOLDING_TAX', false),
    'tax_declaration' => env('FEATURE_TAX_DECLARATION', false),
    'e_invoicing' => env('FEATURE_E_INVOICING', false),
    'consolidation' => env('FEATURE_CONSOLIDATION', false),

];
