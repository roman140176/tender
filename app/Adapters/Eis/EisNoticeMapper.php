<?php

namespace App\Adapters\Eis;

use App\Enums\LawType;

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
}
