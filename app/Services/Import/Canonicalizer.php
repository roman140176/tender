<?php

namespace App\Services\Import;

final class Canonicalizer
{
    /** lower + trim + NBSP→пробел + схлопывание пробелов + ё→е */
    public static function text(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $value = mb_strtolower(str_replace("\u{00A0}", ' ', $value));
        $value = str_replace('ё', 'е', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Детерминированный хеш идентичности закупки (спека §6.5):
     * title | customer_name | customer_inn | published_date(Y-m-d) | price.
     */
    public static function contentHash(
        ?string $title,
        ?string $customerName,
        ?string $customerInn,
        ?string $publishedDate,
        ?string $price,
    ): string {
        return hash('sha256', implode('|', [
            self::text($title),
            self::text($customerName),
            self::text($customerInn),
            $publishedDate ?? '',
            $price ?? '',
        ]));
    }
}
