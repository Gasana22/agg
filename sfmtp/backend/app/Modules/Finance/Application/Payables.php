<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Contracts\Payable;
use App\Support\Http\ApiException;

/** The registry of documents payments can settle (see Contracts\Payable). */
class Payables
{
    /** @var array<string, Payable> */
    private array $types = [];

    public function register(string $type, Payable $payable): void
    {
        $this->types[$type] = $payable;
    }

    public function get(string $type): Payable
    {
        return $this->types[$type] ?? throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['payable_type' => ['Unknown document type.']]);
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->types);
    }
}
