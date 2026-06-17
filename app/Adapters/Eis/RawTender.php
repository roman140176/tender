<?php

namespace App\Adapters\Eis;

use App\Enums\LawType;

final readonly class RawTender
{
    /**
     * @param  array<string,mixed>  $data
     */
    public function __construct(
        public LawType $lawType,
        public string $rootElement,
        public array $data,
        public ?string $regNumber,
    ) {}
}
