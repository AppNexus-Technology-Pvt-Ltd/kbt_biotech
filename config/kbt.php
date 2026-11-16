<?php

return [
    'otp' => [
        // Force OTP on every login (users can also opt in individually via two_factor_enabled).
        'required' => (bool) env('AUTH_OTP_REQUIRED', false),
        'ttl_seconds' => (int) env('AUTH_OTP_TTL', 180),
        'max_attempts' => (int) env('AUTH_OTP_MAX_ATTEMPTS', 5),
        'resend_cooldown_seconds' => (int) env('AUTH_OTP_RESEND_COOLDOWN', 60),
        'pending_login_seconds' => 600,
    ],

    'login' => [
        'max_attempts' => (int) env('AUTH_LOGIN_MAX_ATTEMPTS', 5),
        'decay_seconds' => (int) env('AUTH_LOGIN_DECAY', 900),
        'lockout_minutes' => (int) env('AUTH_LOCKOUT_MINUTES', 15),
    ],

    'password_reset' => [
        'ttl_minutes' => (int) env('AUTH_RESET_TTL_MINUTES', 60),
        'max_requests' => 5,
        'decay_seconds' => 900,
    ],

    // Cookie that remembers "this browser had a login", used to tell "session expired" from "never logged in".
    'session_marker_cookie' => 'kbt_had_session',

    // Sidebar / placeholder modules: slug => [label, route path, permission, icon, description]
    'modules' => [
        'assets' => ['Assets', '/assets', 'assets.view', 'box', 'Register and track every biomedical asset across your facilities.'],
        'installations' => ['Installations', '/installations', 'installations.view', 'install', 'Plan and track equipment installations and commissioning.'],
        'contracts' => ['Contracts', '/contracts', 'contracts.view', 'file-text', 'Manage AMC/CMC contracts and warranty coverage.'],
        'preventive-maintenance' => ['Preventive Maintenance', '/preventive-maintenance', 'pm.view', 'calendar-check', 'Schedule and monitor preventive maintenance.'],
        'work-orders' => ['Work Orders', '/work-orders', 'work_orders.view', 'clipboard', 'Create, assign and track maintenance work orders.'],
        'calibration' => ['Calibration', '/calibration', 'calibration.view', 'gauge', 'Calibration schedules, certificates and compliance.'],
        'inventory' => ['Inventory', '/inventory', 'inventory.view', 'package', 'Spare parts and consumables inventory.'],
        'vendors' => ['Vendors', '/vendors', 'vendors.view', 'truck', 'Vendor and service-provider directory.'],
        'analytics' => ['Analytics', '/analytics', 'analytics.view', 'bar-chart', 'Equipment uptime and maintenance analytics.'],
        'reports' => ['Reports', '/reports', 'reports.view', 'file-bar', 'Operational and compliance reports.'],
        'settings' => ['Settings', '/settings', 'settings.manage', 'settings', 'System configuration and user administration.'],
    ],
];
