<?php

namespace EbitOlx\Database;

defined( 'ABSPATH' ) || exit;

use EbitOlx\Contracts\RepositoryInterface;

class ProductQueueRepository implements RepositoryInterface {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'drtechno_olx_prod_queue';
    }

    public function find( int $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $id
        ) );
    }

    public function findAll( array $criteria = [] ): array {
        global $wpdb;

        if ( empty( $criteria ) ) {
            return $wpdb->get_results( "SELECT * FROM {$this->table} ORDER BY id ASC" );
        }

        $where  = [];
        $values = [];
        foreach ( $criteria as $col => $val ) {
            $where[]  = "{$col} = %s";
            $values[] = $val;
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE " . implode( ' AND ', $where ) . " ORDER BY id ASC",
            ...$values
        ) );
    }

    public function insert( array $data ): int {
        global $wpdb;
        $wpdb->insert( $this->table, $data );
        return (int) $wpdb->insert_id;
    }

    /**
     * Enqueue a product for sync (ignore if already queued).
     */
    public function enqueue( int $postId ): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$this->table} (post_id) VALUES (%d)",
            $postId
        ) );
    }

    /**
     * Enqueue multiple products at once.
     *
     * @param int[] $postIds
     */
    public function enqueueMany( array $postIds ): void {
        global $wpdb;
        foreach ( $postIds as $pid ) {
            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$this->table} (post_id) VALUES (%d)",
                (int) $pid
            ) );
        }
    }

    public function delete( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( $this->table, [ 'id' => $id ], [ '%d' ] );
    }

    /**
     * Remove a product from the queue by post_id.
     */
    public function removeByPostId( int $postId ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( $this->table, [ 'post_id' => $postId ], [ '%d' ] );
    }

    public function truncate(): void {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$this->table}" );
    }

    /**
     * Dequeue the next batch of products for processing (FIFO).
     *
     * @return object[] Rows with id, post_id, retries
     */
    public function dequeue( int $limit = 30 ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table} ORDER BY id ASC LIMIT %d",
            $limit
        ) );
    }

    /**
     * Increment retry count for a queue item.
     */
    public function incrementRetries( int $id ): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->table} SET retries = retries + 1 WHERE id = %d",
            $id
        ) );
    }

    public function count(): int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" );
    }
}
