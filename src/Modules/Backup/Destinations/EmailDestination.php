<?php
namespace TT\Modules\Backup\Destinations;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Backup\BackupSettings;

/**
 * EmailDestination — emails the backup file to configured recipients.
 *
 * Uses wp_mail() under the hood so it inherits whatever transport the
 * site has set up (often a transactional-email plugin in production).
 *
 * Size cap (~10MB attachment) — over that, we send a notice-only email
 * pointing the admin at the local copy and surface a flag in store()'s
 * StoreResult so the UI can show "stored locally only."
 *
 * Email is fundamentally write-only — once sent, we have no way to
 * list / fetch / purge prior backups via this destination. listBackups()
 * returns empty; purge() is a no-op.
 */
class EmailDestination implements BackupDestinationInterface {

    private const SIZE_CAP_BYTES = 10 * 1024 * 1024; // 10 MB

    public function key(): string {
        return 'email';
    }

    public function label(): string {
        return __( 'Email', 'talenttrack' );
    }

    public function isEnabled(): bool {
        $settings = BackupSettings::get();
        $email    = $settings['destinations']['email'] ?? [];
        return ! empty( $email['enabled'] ) && ! empty( $email['recipients'] );
    }

    public function store( string $backup_path, array $metadata ): StoreResult {
        $settings   = BackupSettings::get();
        $recipients = $settings['destinations']['email']['recipients'] ?? [];
        if ( ! is_array( $recipients ) || empty( $recipients ) ) {
            return StoreResult::failure( 'No recipients configured' );
        }
        if ( ! is_readable( $backup_path ) ) {
            return StoreResult::failure( 'Backup file not readable' );
        }
        $size = (int) @filesize( $backup_path );

        $site_name   = get_bloginfo( 'name' );
        $backup_date = isset( $metadata['created_at'] ) ? (string) $metadata['created_at'] : gmdate( 'c' );
        $preset      = isset( $metadata['preset'] ) ? (string) $metadata['preset'] : '';

        if ( $size > self::SIZE_CAP_BYTES ) {
            // Send a notice-only email and flag the size-cap miss.
            $subject = sprintf(
                /* translators: 1: site name, 2: backup date */
                __( '[%1$s] TalentTrack backup ready (too large to email): %2$s', 'talenttrack' ),
                $site_name,
                $backup_date
            );
            $body = sprintf(
                /* translators: 1: backup date, 2: size in MB, 3: cap in MB */
                __( "A TalentTrack backup was created at %1\$s but is too large to email (%2\$.1f MB > %3\$.0f MB cap).\n\nThe backup is stored locally. Sign in to wp-admin to download or restore it.", 'talenttrack' ),
                $backup_date,
                $size / 1024 / 1024,
                self::SIZE_CAP_BYTES / 1024 / 1024
            );
            $sent = wp_mail( $recipients, $subject, $body );
            return new StoreResult( true, 'notice-' . md5( $backup_path ), $sent ? '' : 'Notice email failed', [
                'over_size_cap' => true,
                'sent'          => (bool) $sent,
            ] );
        }

        $subject = sprintf(
            /* translators: 1: site name, 2: backup date */
            __( '[%1$s] TalentTrack backup: %2$s', 'talenttrack' ),
            $site_name,
            $backup_date
        );
        $body = sprintf(
            /* translators: 1: site name, 2: preset, 3: size in MB */
            __( "Attached: TalentTrack backup for %1\$s.\nPreset: %2\$s. Size: %3\$.1f MB.\n\nKeep this file safe — it contains personal data subject to your retention policy.", 'talenttrack' ),
            $site_name,
            $preset,
            $size / 1024 / 1024
        );

        $sent = wp_mail( $recipients, $subject, $body, [], [ $backup_path ] );

        if ( ! $sent ) {
            return StoreResult::failure( 'wp_mail returned false' );
        }
        return StoreResult::success( 'mailed-' . md5( basename( $backup_path ) ), [
            'recipients' => $recipients,
            'size'       => $size,
        ] );
    }

    public function listBackups(): array {
        // Email is fire-and-forget — we don't track outbound copies.
        return [];
    }

    public function fetchLocalPath( string $id ): string {
        return '';
    }

    public function purge( string $id ): bool {
        return true;
    }
}
