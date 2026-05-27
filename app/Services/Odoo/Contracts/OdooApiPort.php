<?php

namespace App\Services\Odoo\Contracts;

interface OdooApiPort
{
    public function authenticate(): array;

    public function executeKw(string $model, string $method, array $args = [], array $kwargs = []): array;
}
