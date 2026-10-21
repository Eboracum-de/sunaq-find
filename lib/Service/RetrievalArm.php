<?php
declare(strict_types=1);

namespace OCA\SunaqFind\Service;

interface RetrievalArm {
    public function id(): string;

    public function retrieve(array $plan, string $originalQuery, int $limit = 15): array;
}
