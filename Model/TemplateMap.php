<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Model;

/**
 * WhatsApp templates (approved in Infobip / Meta) per notification type and locale (*Infobip > WhatsApp templates*):
 * the row for the type and the store's locale, else for the type and the locale's language ("de" for "de_CH"), else
 * for the type alone (empty locale). The variables are the DeliveryContent::$vars names in the template's order
 * ({{1}}, {{2}}, …); the language is the template's language code in Infobip (e.g. "en", "de").
 */
class TemplateMap
{
    public function __construct(private readonly Config $config)
    {
    }

    /** @return array{template: string, language: string, variables: string[]}|null */
    public function find(int $storeId, string $type, string $locale): ?array
    {
        if ($type === '') {
            return null;
        }
        $language = strtolower(explode('_', $locale)[0]);
        $best = null;
        $bestRank = 0;
        foreach ($this->config->getTemplateMap($storeId) as $row) {
            if (trim((string)($row['type'] ?? '')) !== $type || trim((string)($row['template'] ?? '')) === '') {
                continue;
            }
            $rowLocale = trim((string)($row['locale'] ?? ''));
            $rank = match (true) {
                strcasecmp($rowLocale, $locale) === 0 => 3,
                $rowLocale !== '' && strcasecmp($rowLocale, $language) === 0 => 2,
                $rowLocale === '' => 1,
                default => 0,
            };
            if ($rank > $bestRank) {
                [$best, $bestRank] = [$row, $rank];
            }
        }
        if ($best === null) {
            return null;
        }
        $variables = array_values(array_filter(array_map('trim', explode(',', (string)($best['variables'] ?? ''))),
            fn (string $name) => $name !== ''));
        $templateLanguage = trim((string)($best['language'] ?? ''));
        return ['template' => trim((string)$best['template']),
            'language' => $templateLanguage !== '' ? $templateLanguage : $language, 'variables' => $variables];
    }
}
