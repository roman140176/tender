<?php

namespace App\Adapters\Eis;

use App\Enums\LawType;
use App\Enums\TenderSourceStatus;
use App\Services\Import\Canonicalizer;
use App\Services\Import\NormalizationException;
use App\Services\Import\NormalizedTender;
use Carbon\CarbonImmutable;

class EisNoticeMapper
{
    /**
     * Извлечь поля из DOM-узла извещения по конфигу eis.fields[law->value].
     *
     * @throws \InvalidArgumentException
     */
    public function extract(\DOMNode $notice, LawType $law): RawTender
    {
        $fields = config('eis.fields')[$law->value] ?? [];

        if (empty($fields)) {
            throw new \InvalidArgumentException(
                "Нет конфигурации eis.fields для закона: {$law->value}"
            );
        }

        $doc = ($notice instanceof \DOMDocument) ? $notice : $notice->ownerDocument;
        $xpath = new \DOMXPath($doc);

        // Хелпер: первое значение элемента по local-name относительно $notice
        $first = function (string $elem) use ($xpath, $notice): string {
            $nodes = $xpath->query(".//*[local-name()=\"{$elem}\"]", $notice);
            if ($nodes === false || $nodes->length === 0) {
                return '';
            }

            return trim($nodes->item(0)->textContent);
        };

        // Хелпер: все значения элемента (для okpd2/ktru)
        $all = function (string $elem) use ($xpath, $notice): array {
            $nodes = $xpath->query(".//*[local-name()=\"{$elem}\"]", $notice);
            if ($nodes === false) {
                return [];
            }
            $result = [];
            foreach ($nodes as $node) {
                $v = trim($node->textContent);
                if ($v !== '') {
                    $result[] = $v;
                }
            }

            return $result;
        };

        $data = [
            'number' => $first($fields['number']),
            'title' => $first($fields['title']),
            'publishedAt' => $first($fields['publishedAt']),
            'deadlineAt' => $first($fields['deadlineAt']),
            'customerName' => $first($fields['customerName']),
            'customerInn' => $first($fields['customerInn']),
            'region' => $first($fields['region']),
            'price' => $first($fields['price']),
            'okpd2' => $all($fields['okpd2']),
            'ktru' => $all($fields['ktru']),
        ];

        // rootElement — localName DOM-узла (уже без префикса)
        $rootElement = $notice->localName;
        $regNumber = $data['number'] !== '' ? $data['number'] : null;

        return new RawTender(
            lawType: $law,
            rootElement: $rootElement,
            data: $data,
            regNumber: $regNumber,
        );
    }

    /**
     * Преобразовать RawTender в NormalizedTender.
     *
     * @throws NormalizationException при пустом title
     */
    public function toNormalizedTender(RawTender $raw): NormalizedTender
    {
        $data = $raw->data;

        // title обязателен
        $title = trim($data['title'] ?? '');
        if ($title === '') {
            throw new NormalizationException('Пустое название закупки в ЕИС-извещении.');
        }

        // price: числовая и >= 0 → decimal-строка, иначе null (0 валиден)
        $priceRaw = trim($data['price'] ?? '');
        $price = null;
        if ($priceRaw !== '' && is_numeric($priceRaw) && (float) $priceRaw >= 0) {
            $price = number_format((float) $priceRaw, 2, '.', '');
        }

        // Даты — offset-aware → UTC
        $publishedAt = null;
        if (! empty($data['publishedAt'])) {
            try {
                $publishedAt = CarbonImmutable::parse($data['publishedAt'])->utc();
            } catch (\Throwable) {
                $publishedAt = null;
            }
        }

        $deadlineAt = null;
        if (! empty($data['deadlineAt'])) {
            try {
                $deadlineAt = CarbonImmutable::parse($data['deadlineAt'])->utc();
            } catch (\Throwable) {
                $deadlineAt = null;
            }
        }

        // sourceStatus: Cancel в rootElement → Cancelled, иначе Active
        $sourceStatus = str_contains($raw->rootElement, 'Cancel')
            ? TenderSourceStatus::Cancelled
            : TenderSourceStatus::Active;

        // sourceUrl по шаблону из конфига
        $sourceUrl = $raw->regNumber !== null
            ? sprintf(config('eis.source_url_template'), $raw->regNumber)
            : '';

        // okpd2 и ktru — уже списки из extract()
        $okpd2 = $data['okpd2'] ?? [];
        $ktru = $data['ktru'] ?? [];

        $customerName = ($data['customerName'] ?? '') !== '' ? $data['customerName'] : null;
        $customerInn = ($data['customerInn'] ?? '') !== '' ? $data['customerInn'] : null;
        $region = ($data['region'] ?? '') !== '' ? $data['region'] : null;

        $contentHash = Canonicalizer::contentHash(
            $title,
            $customerName,
            $customerInn,
            $publishedAt?->format('Y-m-d'),
            $price,
        );

        return new NormalizedTender(
            title: $title,
            description: null,
            customerName: $customerName,
            customerInn: $customerInn,
            region: $region,
            price: $price,
            currency: 'RUB',
            lawType: $raw->lawType,
            procedureType: null,
            sourceStatus: $sourceStatus,
            publishedAt: $publishedAt,
            deadlineAt: $deadlineAt,
            sourceUrl: $sourceUrl,
            number: $raw->regNumber,
            okpd2: $okpd2,
            contentHash: $contentHash,
            ktru: $ktru,
            extra: ['rootElement' => $raw->rootElement],
        );
    }
}
