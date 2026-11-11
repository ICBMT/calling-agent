<?php

namespace App\Services\Providers\Data;

final readonly class Discovery
{
    public function __construct(
        public array $models,
        public array $voices = [],
        public array $usage = [],
        public array $warnings = [],
    ) {}
}
