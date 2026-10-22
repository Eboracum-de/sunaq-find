<?php
declare(strict_types=1);

namespace OCA\SunaqFind\Service;

final class EvidenceBuilder {
    public const FORMAT = 'sunaq-search-evidence-v1';
    private const MAX_DOCUMENTS = 12;
    private const MAX_SNIPPET_CHARS = 1000;
    private const MAX_TOTAL_CHARS = 12000;

    public function build(array $hits, int $maxDocuments = self::MAX_DOCUMENTS): array {
        $maxDocuments = max(1, min(20, $maxDocuments));
        $records = [];
        $sources = [];
        foreach (array_slice($hits, 0, $maxDocuments) as $hit) {
            if (!is_array($hit) || ($hit['authorized'] ?? false) !== true) {
                continue;
            }
            $index = count($records) + 1;
            $record = [
                'citation' => '[' . $index . ']',
                'file_id' => (int)($hit['file_id'] ?? 0),
                'name' => mb_substr((string)($hit['name'] ?? ''), 0, 300, 'UTF-8'),
                'path' => mb_substr((string)($hit['path'] ?? ''), 0, 700, 'UTF-8'),
                'snippet' => mb_substr((string)($hit['snippet'] ?? ''), 0, self::MAX_SNIPPET_CHARS, 'UTF-8'),
            ];
            if ($record['file_id'] <= 0 || $record['name'] === '') {
                continue;
            }

            $candidate = $records;
            $candidate[] = $record;
            $json = $this->serialize($candidate);
            while (strlen($json) > self::MAX_TOTAL_CHARS && $record['snippet'] !== '') {
                $length = max(0, mb_strlen($record['snippet'], 'UTF-8') - 100);
                $record['snippet'] = mb_substr($record['snippet'], 0, $length, 'UTF-8');
                $candidate[count($candidate) - 1] = $record;
                $json = $this->serialize($candidate);
            }
            if (strlen($json) > self::MAX_TOTAL_CHARS) {
                break;
            }
            $records[] = $record;
            $sources[] = [
                'index' => $index,
                'file_id' => $record['file_id'],
                'name' => $record['name'],
                'path' => $record['path'],
                'url' => (string)($hit['url'] ?? ''),
                'snippet' => mb_substr($record['snippet'], 0, 360, 'UTF-8'),
            ];
        }

        return [
            'json' => $this->serialize($records),
            'documents' => $records,
            'sources' => $sources,
        ];
    }

    private function serialize(array $records): string {
        return json_encode([
            'format' => self::FORMAT,
            'kind' => 'nextcloud_documents',
            'records' => $records,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
