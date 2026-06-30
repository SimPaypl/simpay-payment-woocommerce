<?php

namespace SimPay\WooCommerce\Database;

if (!defined('ABSPATH')) {
    exit;
}

final class BlikAliasRepository
{
    /**
     * Find active alias for a customer.
     *
     * @return object|null Row with: id, customer_id, alias_uuid, alias_value, alias_type, alias_label, status
     */
    public function findActiveByCustomerId(int $customerId): ?object
    {
        global $wpdb;
        $table = Installer::getTableName();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE customer_id = %d AND status = %s LIMIT 1",
            $customerId,
            'alias_active'
        ));

        return $row ?: null;
    }

    /**
     * Find alias by SimPay UUID.
     */
    public function findByUuid(string $uuid): ?object
    {
        global $wpdb;
        $table = Installer::getTableName();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE alias_uuid = %s LIMIT 1",
            $uuid
        ));

        return $row ?: null;
    }

    /**
     * Find alias by customer ID and value.
     */
    public function findByCustomerAndValue(int $customerId, string $value): ?object
    {
        global $wpdb;
        $table = Installer::getTableName();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE customer_id = %d AND alias_value = %s LIMIT 1",
            $customerId,
            $value
        ));

        return $row ?: null;
    }

    /**
     * Create or update an alias (upsert by customer_id + alias_value).
     */
    public function upsert(int $customerId, string $value, string $label, string $type = 'UID', string $status = 'alias_pending', string $uuid = ''): int
    {
        global $wpdb;
        $table = Installer::getTableName();

        $existing = $this->findByCustomerAndValue($customerId, $value);

        if ($existing) {
            $wpdb->update(
                $table,
                [
                    'alias_label'  => $label,
                    'alias_type'   => $type,
                    'status'       => $status,
                    'alias_uuid'   => $uuid ?: $existing->alias_uuid,
                    'updated_at'   => current_time('mysql', true),
                ],
                ['id' => $existing->id],
                ['%s', '%s', '%s', '%s', '%s'],
                ['%d']
            );

            return (int) $existing->id;
        }

        $wpdb->insert(
            $table,
            [
                'customer_id'  => $customerId,
                'alias_uuid'   => $uuid,
                'alias_value'  => $value,
                'alias_type'   => $type,
                'alias_label'  => $label,
                'status'       => $status,
                'created_at'   => current_time('mysql', true),
                'updated_at'   => current_time('mysql', true),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Update alias status and UUID by SimPay UUID (from IPN).
     */
    public function updateStatusByUuid(string $uuid, string $status): bool
    {
        global $wpdb;
        $table = Installer::getTableName();

        $updated = $wpdb->update(
            $table,
            [
                'status'     => $status,
                'updated_at' => current_time('mysql', true),
            ],
            ['alias_uuid' => $uuid],
            ['%s', '%s'],
            ['%s']
        );

        return $updated !== false;
    }

    /**
     * Update UUID for an existing alias (after IPN confirms registration).
     */
    public function setUuid(int $id, string $uuid): bool
    {
        global $wpdb;
        $table = Installer::getTableName();

        $updated = $wpdb->update(
            $table,
            [
                'alias_uuid' => $uuid,
                'updated_at' => current_time('mysql', true),
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );

        return $updated !== false;
    }

    /**
     * Delete alias by ID.
     */
    public function delete(int $id): bool
    {
        global $wpdb;
        $table = Installer::getTableName();

        return $wpdb->delete($table, ['id' => $id], ['%d']) !== false;
    }

    /**
     * Delete all aliases for a customer.
     */
    public function deleteByCustomerId(int $customerId): bool
    {
        global $wpdb;
        $table = Installer::getTableName();

        return $wpdb->delete($table, ['customer_id' => $customerId], ['%d']) !== false;
    }
}

