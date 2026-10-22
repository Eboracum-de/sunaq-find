<?php
declare(strict_types=1);

namespace OCA\SunaqFind\Service;

/**
 * Retrieval-arm registry/hook boundary.
 *
 * Only files is active in the prototype. Other SunaQ arm IDs are retained as
 * extension points without shipping dummy backends.
 */
final class RetrievalCoordinator {
    private const RESERVED_ARMS = ['files', 'vector', 'graph', 'resolver', 'mail', 'web'];

    /** @var array<string, RetrievalArm> */
    private $activeArms;

    public function __construct(FilesRetrievalArm $files) {
        $this->activeArms = [$files->id() => $files];
    }

    public function capabilities(): array {
        $result = [];
        foreach (self::RESERVED_ARMS as $id) {
            $result[$id] = isset($this->activeArms[$id]);
        }
        return $result;
    }

    public function retrieve(array $plan, string $originalQuery, int $limit = 15): array {
        $requested = is_array($plan['retrieval_arms'] ?? null) ? $plan['retrieval_arms'] : ['files'];

        foreach ($requested as $armId) {
            $armId = (string)$armId;
            if (isset($this->activeArms[$armId])) {
                return $this->activeArms[$armId]->retrieve($plan, $originalQuery, $limit);
            }
        }

        throw new \RuntimeException('No requested retrieval arm is available');
    }
}
