<?php

namespace App\Services\Import;

use App\Enums\LawType;
use App\Enums\TenderSourceStatus;
use Carbon\CarbonImmutable;

class Normalizer
{
    /**
     * @param  array<string, mixed>  $row  ключи — английские имена полей (header-маппинг применяет вызывающий)
     */
    public function normalize(array $row): NormalizedTender
    {
        $title = trim((string) ($row['title'] ?? ''));
        if ($title === '') {
            throw new NormalizationException('Пустое название закупки.');
        }

        $price = $this->parsePrice($row['price'] ?? null);
        $publishedAt = $this->parseDate($row['published_at'] ?? null);

        return new NormalizedTender(
            title: $title,
            description: $this->nullableString($row['description'] ?? null),
            customerName: $this->nullableString($row['customer_name'] ?? null),
            customerInn: $this->digitsOrNull($row['customer_inn'] ?? null),
            region: $this->nullableString($row['region'] ?? null),
            price: $price,
            currency: strtoupper($this->nullableString($row['currency'] ?? null) ?? 'RUB'),
            lawType: $this->mapEnum($row['law_type'] ?? null, config('import.law_type_map'), LawType::class, LawType::Other),
            procedureType: $this->nullableString($row['procedure_type'] ?? null),
            sourceStatus: $this->mapEnum($row['source_status'] ?? null, config('import.status_map'), TenderSourceStatus::class, TenderSourceStatus::Unknown),
            publishedAt: $publishedAt,
            deadlineAt: $this->parseDate($row['deadline_at'] ?? null),
            sourceUrl: trim((string) ($row['source_url'] ?? '')),
            number: $this->nullableString($row['number'] ?? null),
            okpd2: $this->parseList($row['okpd2'] ?? null),
            contentHash: Canonicalizer::contentHash(
                $title,
                $this->nullableString($row['customer_name'] ?? null),
                $this->digitsOrNull($row['customer_inn'] ?? null),
                $publishedAt?->format('Y-m-d'),
                $price,
            ),
        );
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function digitsOrNull(mixed $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) ($value ?? ''));

        return $digits === '' ? null : $digits;
    }

    /** «1 200 000,50 ₽» → '1200000.50' */
    private function parsePrice(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $clean = str_replace([' ', "\u{00A0}", '₽', 'руб.', 'руб'], '', mb_strtolower((string) $value));
        $clean = str_replace(',', '.', $clean);

        if (! is_numeric($clean) || (float) $clean < 0) {
            return null;
        }

        return number_format((float) $clean, 2, '.', '');
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        // ВНИМАНИЕ: CarbonImmutable::createFromFormat кидает исключение при несовпадении
        // формата в Carbon 3.x — оборачиваем каждую попытку в try/catch.
        // Сравниваем back-format, чтобы отсечь частичные совпадения.
        foreach (config('import.date_formats') as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat($format, $value);
                if ($parsed->format($format) === $value) {
                    return $parsed;
                }
            } catch (\Throwable) {
                // формат не совпал — пробуем следующий
            }
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  array<string, string>  $map
     * @param  class-string<T>  $enumClass
     * @param  T  $default
     * @return T
     */
    private function mapEnum(mixed $value, array $map, string $enumClass, \BackedEnum $default): \BackedEnum
    {
        $key = Canonicalizer::text((string) ($value ?? ''));
        $mapped = $map[$key] ?? null;

        return $mapped !== null ? $enumClass::from($mapped) : $default;
    }

    /** @return list<string> */
    private function parseList(mixed $value): array
    {
        $parts = array_map('trim', explode(';', (string) ($value ?? '')));

        return array_values(array_filter($parts, fn (string $p) => $p !== ''));
    }
}
