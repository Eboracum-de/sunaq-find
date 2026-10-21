<?php
declare(strict_types=1);

namespace OCA\SunaqFind\Service;

final class FilesRetrievalArm implements RetrievalArm {
    private $search;
    public function __construct(ElasticsearchSearch $search) {
        $this->search = $search;
    }

    public function id(): string {
        return 'files';
    }

    public function retrieve(array $plan, string $originalQuery, int $limit = 15): array {
        return $this->search->search($plan, $originalQuery, $limit);
    }
}
