<?php

namespace App\Services;

use App\Models\OdooConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OdooClient
{
    private ?int $uid = null;

    public function __construct(private OdooConnection $connection) {}

    /**
     * Read records that match an Odoo domain.
     *
     * @param  array<int, mixed>  $domain
     * @param  array<int, string>  $fields
     * @return array<int, array<string, mixed>>
     */
    public function searchRead(string $model, array $domain, array $fields, string $order, int $limit, int $offset): array
    {
        $result = $this->execute($model, 'search_read', [$domain], [
            'fields' => $fields,
            'order' => $order,
            'limit' => $limit,
            'offset' => $offset,
        ]);

        if (! is_array($result) || ! array_is_list($result)) {
            throw new RuntimeException('Could not read first orders from Odoo.');
        }

        /** @var array<int, array<string, mixed>> $result */
        return $result;
    }

    /**
     * Read specific Odoo records.
     *
     * @param  array<int, int>  $ids
     * @param  array<int, string>  $fields
     * @return array<int, array<string, mixed>>
     */
    public function read(string $model, array $ids, array $fields): array
    {
        $result = $this->execute($model, 'read', [$ids], [
            'fields' => $fields,
        ]);

        if (! is_array($result) || ! array_is_list($result)) {
            throw new RuntimeException('Could not read first orders from Odoo.');
        }

        /** @var array<int, array<string, mixed>> $result */
        return $result;
    }

    /**
     * Call an Odoo model method.
     *
     * @param  array<int, mixed>  $args
     * @param  array<string, mixed>  $kwargs
     */
    private function execute(string $model, string $method, array $args, array $kwargs): mixed
    {
        try {
            $response = Http::connectTimeout(5)
                ->timeout(20)
                ->withoutRedirecting()
                ->acceptJson()
                ->asJson()
                ->post($this->endpoint(), [
                    'jsonrpc' => '2.0',
                    'method' => 'call',
                    'params' => [
                        'service' => 'object',
                        'method' => 'execute_kw',
                        'args' => [
                            $this->connection->database,
                            $this->uid(),
                            $this->connection->api_key,
                            $model,
                            $method,
                            $args,
                            (object) $kwargs,
                        ],
                    ],
                    'id' => 1,
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Could not read first orders from Odoo.');
        }

        $payload = $response->json();

        if (! $response->successful() || ! is_array($payload) || array_key_exists('error', $payload)) {
            throw new RuntimeException('Could not read first orders from Odoo.');
        }

        return $payload['result'] ?? null;
    }

    /**
     * Sign in and return the Odoo user id.
     */
    private function uid(): int
    {
        if ($this->uid !== null) {
            return $this->uid;
        }

        try {
            $response = Http::connectTimeout(5)
                ->timeout(20)
                ->withoutRedirecting()
                ->acceptJson()
                ->asJson()
                ->post($this->endpoint(), [
                    'jsonrpc' => '2.0',
                    'method' => 'call',
                    'params' => [
                        'service' => 'common',
                        'method' => 'authenticate',
                        'args' => [
                            $this->connection->database,
                            $this->connection->username,
                            $this->connection->api_key,
                            (object) [],
                        ],
                    ],
                    'id' => 1,
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Could not read first orders from Odoo.');
        }

        $userId = $response->json('result');

        if (! $response->successful() || ! is_int($userId) || $userId < 1) {
            throw new RuntimeException('Could not read first orders from Odoo.');
        }

        return $this->uid = $userId;
    }

    private function endpoint(): string
    {
        return rtrim($this->connection->url, '/').'/jsonrpc';
    }
}
