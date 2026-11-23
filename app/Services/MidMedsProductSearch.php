<?php

namespace App\Services;

use RuntimeException;

class MidMedsProductSearch
{
    public const int RESULT_LIMIT = 20;

    /**
     * Search sellable MidMeds products by name or SKU.
     *
     * @return array<int, array{id: int, name: string, sku: string, image: string}>
     */
    public function search(OdooClient $client, string $term): array
    {
        try {
            $rows = $client->searchRead('product.template', [
                '&',
                ['sale_ok', '=', true],
                '|',
                ['name', 'ilike', $term],
                ['default_code', 'ilike', $term],
            ], ['name', 'default_code', 'image_128'], 'name asc', self::RESULT_LIMIT, 0);
        } catch (RuntimeException) {
            throw new RuntimeException('Could not read the MidMeds catalogue from Odoo.');
        }

        $products = [];

        foreach ($rows as $row) {
            $product = $this->summary($row);

            if ($product !== null) {
                $products[] = $product;
            }
        }

        return $products;
    }

    /**
     * Read the catalogue photo for each selected product, in the given order.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array{id: int, name: string, sku: string, image: string}>
     */
    public function photos(OdooClient $client, array $ids): array
    {
        $rows = null;
        $field = null;

        foreach (['image_1024', 'image_1920'] as $candidate) {
            try {
                $rows = $client->read('product.template', $ids, ['name', 'default_code', $candidate]);
                $field = $candidate;
                break;
            } catch (RuntimeException) {
                continue;
            }
        }

        if ($rows === null || $field === null) {
            throw new RuntimeException('Could not read the MidMeds catalogue from Odoo.');
        }

        $byId = [];

        foreach ($rows as $row) {
            $id = $row['id'] ?? null;

            if (! is_int($id)) {
                continue;
            }

            $byId[$id] = [
                'id' => $id,
                'name' => $this->label($row['name'] ?? null),
                'sku' => $this->label($row['default_code'] ?? null),
                'image' => $this->dataUrl($row[$field] ?? null),
            ];
        }

        $photos = [];

        foreach ($ids as $id) {
            $photo = $byId[$id] ?? null;

            if ($photo === null) {
                throw new RuntimeException('A selected product could not be read from Odoo.');
            }

            $photos[] = $photo;
        }

        return $photos;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: int, name: string, sku: string, image: string}|null
     */
    private function summary(array $row): ?array
    {
        $id = $row['id'] ?? null;
        $name = $this->label($row['name'] ?? null);

        if (! is_int($id) || $name === '') {
            return null;
        }

        return [
            'id' => $id,
            'name' => mb_substr($name, 0, 180),
            'sku' => mb_substr($this->label($row['default_code'] ?? null), 0, 64),
            'image' => $this->dataUrl($row['image_128'] ?? null),
        ];
    }

    private function label(mixed $value): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return '';
        }

        $text = trim(strip_tags((string) $value));

        return strtolower($text) === 'false' ? '' : $text;
    }

    /**
     * Turn an Odoo image into a data URL, or return an empty string when it is missing or not a photo.
     */
    private function dataUrl(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $encoded = preg_replace('/\s+/', '', $value) ?? '';
        $binary = base64_decode($encoded, true);

        if (! is_string($binary) || $binary === '') {
            return '';
        }

        $mime = match (true) {
            str_starts_with($binary, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($binary, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($binary, 'RIFF') && substr($binary, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };

        if ($mime === null) {
            return '';
        }

        return 'data:'.$mime.';base64,'.base64_encode($binary);
    }
}
