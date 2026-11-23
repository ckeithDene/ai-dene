<?php

namespace App\Actions;

use App\Models\GoogleShoppingSuggestion;
use App\Services\OdooClient;
use RuntimeException;

class BuildGoogleShoppingFeed
{
    private const int PAGE_SIZE = 100;

    private const int MAX_PRODUCTS = 10000;

    private const string SHOP = 'https://www.midmeds.co.uk';

    /**
     * Build a tab-separated product feed for Google Merchant Center.
     */
    public function handle(OdooClient $client): string
    {
        set_time_limit(0);

        $lines = [implode("\t", $this->columns())];
        $usedIds = [];

        foreach ($this->catalogue($client) as $row) {
            $item = $this->item($row, $usedIds);

            if ($item === null) {
                continue;
            }

            $lines[] = implode("\t", $item);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return array<int, string>
     */
    private function columns(): array
    {
        return [
            'id',
            'title',
            'description',
            'link',
            'image_link',
            'availability',
            'price',
            'condition',
            'brand',
            'gtin',
            'mpn',
            'identifier_exists',
            'product_type',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function catalogue(OdooClient $client): array
    {
        $exception = null;

        foreach ($this->attempts() as [$domain, $fields]) {
            try {
                return $this->pages($client, $domain, $fields);
            } catch (RuntimeException $caught) {
                $exception = $caught;
            }
        }

        throw $exception ?? new RuntimeException('Could not read products from Odoo.');
    }

    /**
     * @return array<int, array{0: array<int, array<int, mixed>>, 1: array<int, string>}>
     */
    private function attempts(): array
    {
        $published = [
            ['sale_ok', '=', true],
            ['website_published', '=', true],
            ['type', '!=', 'service'],
        ];
        $sellable = [
            ['sale_ok', '=', true],
            ['type', '!=', 'service'],
        ];
        $full = ['name', 'default_code', 'barcode', 'lst_price', 'qty_available', 'description_sale', 'website_url', 'categ_id', 'product_tmpl_id', 'product_brand_id', 'type'];
        $withoutBrand = ['name', 'default_code', 'barcode', 'lst_price', 'qty_available', 'description_sale', 'website_url', 'categ_id', 'product_tmpl_id', 'type'];
        $minimal = ['name', 'default_code', 'barcode', 'lst_price', 'website_url', 'categ_id', 'product_tmpl_id', 'type'];

        return [
            [$published, $full],
            [$sellable, $full],
            [$sellable, $withoutBrand],
            [$sellable, $minimal],
        ];
    }

    /**
     * @param  array<int, array<int, mixed>>  $domain
     * @param  array<int, string>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function pages(OdooClient $client, array $domain, array $fields): array
    {
        $rows = [];
        $offset = 0;

        do {
            $page = $client->searchRead('product.product', $domain, $fields, 'id asc', self::PAGE_SIZE, $offset);
            array_push($rows, ...$page);
            $offset += self::PAGE_SIZE;
        } while (count($page) === self::PAGE_SIZE && count($rows) < self::MAX_PRODUCTS);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, true>  $usedIds
     * @return array<int, string>|null
     */
    private function item(array $row, array &$usedIds): ?array
    {
        if (($row['type'] ?? null) === 'service') {
            return null;
        }

        $productId = $this->relationId($row['id'] ?? null);
        $title = $this->title($this->text($row['name'] ?? null));
        $price = $this->price($row['lst_price'] ?? null);
        $link = $this->shopLink($this->text($row['website_url'] ?? null));

        if ($productId === null || $title === '' || $price === null || $link === null) {
            return null;
        }

        $sku = $this->plain($this->text($row['default_code'] ?? null), 70);
        $gtin = $this->gtin($this->text($row['barcode'] ?? null));
        $brand = $this->plain($this->many2oneName($row['product_brand_id'] ?? null), 70);
        $description = $this->plain($this->text($row['description_sale'] ?? null), 5000);
        $id = $this->id($sku, $productId, $usedIds);
        $identifierExists = $gtin === '' && ($brand === '' || $sku === '') ? 'no' : '';

        return [
            $id,
            $title,
            $description !== '' ? $description : $title,
            $link,
            $this->image($productId, $this->relationId($row['product_tmpl_id'] ?? null)),
            is_numeric($row['qty_available'] ?? null) && (float) $row['qty_available'] <= 0 ? 'out_of_stock' : 'in_stock',
            $price,
            'new',
            $brand,
            $gtin,
            $sku,
            $identifierExists,
            str_replace(' / ', ' > ', $this->plain($this->many2oneName($row['categ_id'] ?? null), 750)),
        ];
    }

    /**
     * @param  array<string, true>  $usedIds
     */
    private function id(string $sku, int $productId, array &$usedIds): string
    {
        $candidate = $sku !== '' && mb_strlen($sku) <= 50 ? $sku : (string) $productId;

        if (isset($usedIds[$candidate])) {
            $candidate = (string) $productId;
        }

        if (isset($usedIds[$candidate])) {
            $candidate = mb_substr($productId.'-'.$sku, 0, 50);
        }

        $usedIds[$candidate] = true;

        return $candidate;
    }

    private function title(string $name): string
    {
        $name = preg_replace('/^\[[^\]]+\]\s*/', '', $name) ?? $name;

        return $this->plain($name, 150);
    }

    private function shopLink(string $websiteUrl): ?string
    {
        if ($websiteUrl === '') {
            return null;
        }

        $path = str_starts_with($websiteUrl, '/')
            ? $websiteUrl
            : parse_url($websiteUrl, PHP_URL_PATH);

        if (! is_string($path) || ! str_starts_with($path, '/shop/')) {
            return null;
        }

        $path = strtok($path, '?') ?: $path;

        return self::SHOP.$path;
    }

    private function image(int $productId, ?int $templateId): string
    {
        if ($templateId !== null) {
            return self::SHOP.'/web/image/product.template/'.$templateId.'/image_1920';
        }

        return self::SHOP.'/web/image/product.product/'.$productId.'/image_1024';
    }

    private function price(mixed $value): ?string
    {
        if (! is_numeric($value) || (float) $value <= 0) {
            return null;
        }

        $includingVat = round((float) $value * (1 + GoogleShoppingSuggestion::VAT_RATE), 2);

        return number_format($includingVat, 2, '.', '').' GBP';
    }

    private function gtin(string $barcode): string
    {
        $digits = preg_replace('/\D/', '', $barcode) ?? '';

        if (! in_array(strlen($digits), [8, 12, 13, 14], true) || ! $this->validGtin($digits)) {
            return '';
        }

        return $digits;
    }

    private function validGtin(string $digits): bool
    {
        $expected = (int) substr($digits, -1);
        $body = substr($digits, 0, -1);
        $sum = 0;
        $weight = 3;

        for ($index = strlen($body) - 1; $index >= 0; $index--) {
            $sum += (int) $body[$index] * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }

        return (10 - ($sum % 10)) % 10 === $expected;
    }

    private function plain(string $value, int $limit): string
    {
        $value = trim(strip_tags($value));
        $value = str_replace(["\t", "\r", "\n"], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_substr(trim($value), 0, $limit);
    }

    private function text(mixed $value): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return '';
        }

        $text = trim((string) $value);

        return strtolower($text) === 'false' ? '' : $text;
    }

    private function many2oneName(mixed $value): string
    {
        if (! is_array($value) || ! is_string($value[1] ?? null)) {
            return '';
        }

        return trim($value[1]);
    }

    private function relationId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_array($value) && is_int($value[0] ?? null)) {
            return $value[0];
        }

        return null;
    }
}
