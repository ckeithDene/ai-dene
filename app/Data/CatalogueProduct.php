<?php

namespace App\Data;

final class CatalogueProduct
{
    public function __construct(
        public int $id,
        public string $name,
        public string $sku,
        public string $category,
        public ?string $listPrice,
        public ?string $url,
        public ?float $available,
        public string $description,
        public bool $pharmaceutical,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPrompt(): array
    {
        return [
            'odoo_product_id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'category' => $this->category,
            'list_price' => $this->listPrice,
            'catalogue_url' => $this->url,
            'quantity_available' => $this->available,
            'description' => $this->description,
        ];
    }
}
