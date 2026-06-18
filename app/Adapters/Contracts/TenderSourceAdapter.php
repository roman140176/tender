<?php

namespace App\Adapters\Contracts;

use App\Adapters\Eis\CollectParams;
use App\Adapters\Eis\RawTender;
use App\Adapters\Eis\RawTenderDetails;
use App\Services\Import\NormalizedTender;

interface TenderSourceAdapter
{
    public function sourceCode(): string;

    /**
     * @return iterable<RawTender>
     */
    public function collect(CollectParams $params): iterable;

    public function normalize(RawTender $raw): NormalizedTender;

    public function details(string $externalId): ?RawTenderDetails;
}
