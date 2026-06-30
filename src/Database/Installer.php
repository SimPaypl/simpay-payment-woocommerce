<?php

namespace SimPay\WooCommerce\Database;

if (!defined('ABSPATH')) {
    exit;
}

final class Installer
{
    public const DB_VERSION_OPTION = 'simpay_db_version';
    public const CURRENT_DB_VERSION = '1.0.0';

    /**
     * Run on plugin activation or version change.
     */
    public static function install(): void
    {
        $installed_version = get_option(self::DB_VERSION_OPTION, '0');

        if (version_compare($installed_version, self::CURRENT_DB_VERSION, '>=')) {
            return;
        }

        self::createBlikAliasesTable();

        update_option(self::DB_VERSION_OPTION, self::CURRENT_DB_VERSION);
    }

    private static function createBlikAliasesTable(): void
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'simpay_blik_aliases';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id BIGINT UNSIGNED NOT NULL,
            alias_uuid VARCHAR(64) NOT NULL,
            alias_value VARCHAR(255) NOT NULL,
            alias_type VARCHAR(16) NOT NULL DEFAULT 'UID',
            alias_label VARCHAR(255) NOT NULL DEFAULT '',
            status VARCHAR(32) NOT NULL DEFAULT 'alias_pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_customer_value (customer_id, alias_value),
            KEY idx_alias_uuid (alias_uuid),
            KEY idx_status (status)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Get the full table name with prefix.
     */
    public static function getTableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'simpay_blik_aliases';
    }
}

