<?php

namespace App\Data;

final class DeneCatalogueSearch
{
    public function __construct(
        public bool $found,
        public string $message,
    ) {}
}
