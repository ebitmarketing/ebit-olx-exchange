<?php

namespace EbitOlx\Contracts;

defined( 'ABSPATH' ) || exit;

interface RepositoryInterface {

    /**
     * @return object|null
     */
    public function find( int $id );

    /**
     * @param array $criteria Key-value pairs for WHERE conditions
     * @return array
     */
    public function findAll( array $criteria = [] ): array;

    /**
     * @param array $data Column-value pairs
     * @return int Inserted row ID
     */
    public function insert( array $data ): int;

    public function delete( int $id ): bool;

    public function truncate(): void;
}
