<?php

namespace App\Services\Import;

use App\Enums\LawType;
use App\Enums\TenderSourceStatus;
use Carbon\CarbonImmutable;

final readonly class NormalizedTender
{
    /** @param list<string> $okpd2 */
    public function __construct(
        public string $title,
        public ?string $description,
        public ?string $customerName,
        public ?string $customerInn,
        public ?string $region,
        public ?string $price,          // decimal-строка '1200000.50' или null
        public string $currency,
        public LawType $lawType,
        public ?string $procedureType,
        public TenderSourceStatus $sourceStatus,
        public ?CarbonImmutable $publishedAt,
        public ?CarbonImmutable $deadlineAt,
        public string $sourceUrl,       // '' если нет
        public ?string $number,
        public array $okpd2,
        public string $contentHash,
    ) {}
}
