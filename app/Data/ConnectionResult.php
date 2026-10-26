<?php

namespace App\Data;

readonly class ConnectionResult
{
    public function __construct(
        public bool $connected,
        public string $message,
    ) {}
}
