<?php

namespace App\Actions;

use App\Data\CatalogueProduct;
use App\Data\DeneCatalogueSearch;
use App\Enums\NhsCoverage;
use App\Enums\NhsGapType;
use App\Enums\NhsSourcingAction;
use App\Enums\OdooCompany;
use App\Models\NhsCatalogueGap;
use App\Models\NhsDirective;
use App\Models\NhsProductOpportunity;
use App\Models\OdooConnection;
use App\Services\DeneCatalogue;
use App\Services\OdooClient;
use RuntimeException;

class FindDeneProducts
{
    public function __construct(private DeneCatalogue $catalogue) {}

    /**
     * Search the Dene Healthcare catalogue for products to push for one pattern.
     */
    public function forOpportunity(NhsProductOpportunity $opportunity): DeneCatalogueSearch
    {
        $connection = $this->connection();

        if (is_string($connection)) {
            return $this->store($opportunity, [], [], $connection, false);
        }

        return $this->search($connection, $opportunity);
    }

    /**
     * Search the Dene Healthcare catalogue for every pattern linked to a directive.
     */
    public function forDirective(NhsDirective $directive): DeneCatalogueSearch
    {
        $connection = $this->connection();

        if (is_string($connection)) {
            foreach ($directive->opportunities()->get() as $opportunity) {
                $this->store($opportunity, [], [], $connection, false);
            }

            return new DeneCatalogueSearch(false, $connection);
        }

        $opportunities = $directive->opportunities()->get();

        if ($opportunities->isEmpty()) {
            return new DeneCatalogueSearch(false, __('This directive has no purchasing patterns to match yet.'));
        }

        $found = false;

        foreach ($opportunities as $opportunity) {
            if ($this->search($connection, $opportunity)->found) {
                $found = true;
            }
        }

        return new DeneCatalogueSearch($found, __('Searched :host for :count purchasing patterns.', [
            'host' => $this->host($connection),
            'count' => $opportunities->count(),
        ]));
    }

    private function connection(): OdooConnection|string
    {
        $connection = OdooConnection::for(OdooCompany::Dene);

        if ($connection !== null && filled($connection->api_key)) {
            return $connection;
        }

        if (OdooConnection::for(OdooCompany::MidMeds) !== null) {
            return __('Dene Healthcare Odoo is not connected. The saved MidMeds connection is not used here. Add the Dene Healthcare connection on the Odoo connections page.');
        }

        return __('Connect the Dene Healthcare Odoo connection before searching the catalogue.');
    }

    private function search(OdooConnection $connection, NhsProductOpportunity $opportunity): DeneCatalogueSearch
    {
        $host = $this->host($connection);
        $terms = $this->terms($opportunity);
        $client = new OdooClient($connection);

        try {
            $indexed = [];

            foreach ($this->catalogue->products($client, $terms) as $product) {
                $indexed[$product->id] = $product;
            }
        } catch (RuntimeException) {
            return $this->store($opportunity, [], [], __('Could not read the Dene Healthcare catalogue from :host.', ['host' => $host]), false);
        }

        try {
            foreach ($this->catalogue->products($client, $terms, true) as $service) {
                $indexed[$service->id] = $service;
            }
        } catch (RuntimeException) {
            // A catalogue without a service type can still return products.
        }

        $products = array_values($indexed);

        try {
            $categories = $this->catalogue->categories($client, $terms);
            $categorySample = false;

            if ($categories === []) {
                $categories = $this->catalogue->categorySample($client);
                $categorySample = $categories !== [];
            }
        } catch (RuntimeException) {
            $categories = [];
            $categorySample = false;
        }

        $goods = count(array_filter($products, fn (CatalogueProduct $product): bool => ! $product->service));
        $services = count($products) - $goods;
        $message = __('Searched :host. Found :products products, :services services and :categories categories.', [
            'host' => $host,
            'products' => $goods,
            'services' => $services,
            'categories' => count($categories),
        ]);

        if ($categorySample && $products === []) {
            $message = __('Searched :host. No product name matched. Showing :count categories from the Dene Healthcare catalogue.', [
                'host' => $host,
                'count' => count($categories),
            ]);
        }

        return $this->store($opportunity, $products, $categories, $message, true);
    }

    /**
     * @param  array<int, CatalogueProduct>  $products
     * @param  array<int, string>  $categories
     */
    private function store(NhsProductOpportunity $opportunity, array $products, array $categories, string $message, bool $searched): DeneCatalogueSearch
    {
        $gap = NhsCatalogueGap::query()->firstOrNew([
            'nhs_product_opportunity_id' => $opportunity->id,
        ]);

        if (! $gap->exists) {
            $gap->gap_type = NhsGapType::Directive;
            $gap->sourcing_action = $products === [] ? NhsSourcingAction::FindSupplier : NhsSourcingAction::Bundle;
        }

        $gap->coverage = ! $searched
            ? NhsCoverage::Uncertain
            : ($products === [] ? NhsCoverage::PotentialGap : NhsCoverage::Covered);
        $gap->matched_products = array_map(fn (CatalogueProduct $product): array => [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'category' => $product->category,
            'list_price' => $product->listPrice,
            'service' => $product->service,
        ], $products);
        $gap->matched_categories = $categories;
        $gap->catalogue_status = mb_substr($message, 0, 255);
        $gap->save();

        return new DeneCatalogueSearch($searched && ($products !== [] || $categories !== []), $gap->catalogue_status);
    }

    /**
     * @return array<int, string>
     */
    private function terms(NhsProductOpportunity $opportunity): array
    {
        $terms = is_array($opportunity->search_terms) ? $opportunity->search_terms : [];
        $terms[] = $opportunity->name;

        if (filled($opportunity->category)) {
            $terms[] = $opportunity->category;
        }

        return $terms;
    }

    private function host(OdooConnection $connection): string
    {
        $host = parse_url($connection->url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : $connection->url;
    }
}
