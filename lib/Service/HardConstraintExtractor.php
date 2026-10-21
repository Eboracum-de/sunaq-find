<?php
declare(strict_types=1);

namespace OCA\SunaqFind\Service;

/**
 * PHP port of the safe literal extraction used by SunaQ v0.8.7-rc1.
 * Ordinary words and entity names are deliberately not promoted to hard filters.
 */
final class HardConstraintExtractor {
    private const COMPLETE_FILENAME_EXTENSIONS =
        'pdf|odt|ods|odp|doc|docx|xls|xlsx|ppt|pptx|rtf|txt|csv|eml|msg|'
        . 'jpg|jpeg|png|tif|tiff|gif|webp|zip|7z|rar|xml|json|yaml|yml';

    public function extract(string $question): array {
        $text = trim($this->normalizeQuotes($question));
        $result = [
            'filenames' => [],
            'identifiers' => [],
            'dates' => [],
            'years' => [],
        ];

        $filename = $this->extractCompleteFilename($text);
        if ($filename !== '') {
            $result['filenames'][] = $filename;
        }

        $result['dates'] = $this->matches(
            '~(?<!\d)(?:\d{4}-\d{2}-\d{2}|\d{1,2}[./-]\d{1,2}[./-]\d{2,4})(?!\d)~u',
            $text
        );
        $result['years'] = $this->matches(
            '~(?<!\d)(?:18|19|20|21)\d{2}(?!\d)~u',
            $text
        );

        $identifiers = $this->matches(
            '~(?<![\w.-])(?=[A-Za-z0-9._/-]{5,}(?![\w.-]))(?=[A-Za-z0-9._/-]*[A-Za-z])(?=[A-Za-z0-9._/-]*\d)[A-Za-z0-9]+(?:[-_/][A-Za-z0-9]+)+(?![\w.-])~u',
            $text
        );
        foreach ($identifiers as $identifier) {
            if ($filename !== '' && strpos(mb_strtolower($filename, 'UTF-8'), mb_strtolower($identifier, 'UTF-8')) !== false) {
                continue;
            }
            $result['identifiers'][] = $identifier;
        }

        return $result;
    }

    public function normalizeQuotes(string $text): string {
        return strtr($text, [
            '“' => '"', '”' => '"', '„' => '"', '‟' => '"',
            '«' => '"', '»' => '"', '‹' => '"', '›' => '"',
        ]);
    }

    private function extractCompleteFilename(string $question): string {
        $pattern = "~(?:^|[\\s\"'])([^\\s\"'<>|]+\\.(?:" . self::COMPLETE_FILENAME_EXTENSIONS . "))(?=\$|[\\s,;:!?\"'])~iu";
        if (!preg_match($pattern, $question, $match)) {
            return '';
        }
        return rtrim(trim((string)$match[1]), '.,;:!?');
    }

    private function matches(string $pattern, string $text): array {
        if (!preg_match_all($pattern, $text, $matches)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($matches[0] as $value) {
            $value = trim((string)$value);
            $key = mb_strtolower($value, 'UTF-8');
            if ($value === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $value;
        }
        return $out;
    }
}
