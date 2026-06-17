<?php

namespace App\Adapters\Eis;

final readonly class RawTenderDetails
{
    public function __construct(
        public RawTender $raw,
        public string $rawXml,
    ) {}
}
