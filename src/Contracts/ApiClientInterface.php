<?php

namespace EbitOlx\Contracts;

defined( 'ABSPATH' ) || exit;

interface ApiClientInterface {

    /**
     * @param string     $endpoint API endpoint path (e.g. '/listings')
     * @param string     $method   HTTP method (GET, POST, PUT, DELETE)
     * @param array|null $body     Request body for POST/PUT
     * @return array Decoded response data
     * @throws \EbitOlx\Api\OlxApiException
     */
    public function request( string $endpoint, string $method = 'GET', ?array $body = null ): array;

    /**
     * @return bool True on success
     * @throws \EbitOlx\Api\OlxApiException
     */
    public function authenticate( string $username, string $password ): bool;
}
