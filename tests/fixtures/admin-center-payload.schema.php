<?php
/**
 * Admin Center payload schema fixture (#0065 / TTA #0001).
 *
 * Locked v1 shape. Top-level keys are exhaustive; future fields are
 * additive only and must be appended with explicit nullability.
 *
 * `bin/admin-center-self-check.php` validates that PayloadBuilder
 * still produces exactly these top-level keys with the right types.
 */

return [
    'protocol_version'          => 'string',
    'install_id'                => 'uuid',
    'trigger'                   => 'string',
    'sent_at'                   => 'string',

    'site_url'                  => 'string',
    'contact_email'             => 'string',
    'freemius_license_key_hash' => 'string|null',

    'plugin_version'            => 'string',
    'wp_version'                => 'string',
    'php_version'               => 'string',
    'db_version'                => 'string',
    'locale'                    => 'string',
    'timezone'                  => 'string',

    'club_count'                => 'integer',
    'team_count'                => 'integer',
    'player_count_active'       => 'integer',
    'player_count_archived'     => 'integer',
    'staff_count'               => 'integer',
    'dau_7d_avg'                => 'number',
    'wau_count'                 => 'integer',
    'mau_count'                 => 'integer',
    'last_login_date'           => 'string|null',

    'error_counts_24h'          => 'array',
    'error_count_total_24h'     => 'integer',

    'license_tier'              => 'string|null',
    'license_status'            => 'string|null',
    'license_renews_at'         => 'string|null',

    'module_status'             => 'array',

    'feature_flags_enabled'     => 'array',
    'custom_caps_in_use'        => 'boolean',
];
