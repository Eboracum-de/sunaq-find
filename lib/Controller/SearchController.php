<?php
declare(strict_types=1);

namespace OCA\SunaqFind\Controller;

use OCA\SunaqFind\Service\SearchService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

final class SearchController extends Controller {
    private $searchService;
    private $logger;
    private $l10n;
    public function __construct(
        string $appName,
        IRequest $request,
        SearchService $searchService,
        LoggerInterface $logger,
        ?IL10N $l10n = null
    ) {
        parent::__construct($appName, $request);
        $this->searchService = $searchService;
        $this->logger = $logger;
        $this->l10n = $l10n;
    }

    private function t(string $text): string {
        return $this->l10n !== null ? $this->l10n->t($text) : $text;
    }

    /**
     * @NoAdminRequired
     * @param string $query
     * @param string $plannerReasoning
     */
    public function run($query = '', $plannerReasoning = 'current'): DataResponse {
        try {
            return new DataResponse(['ok' => true, 'result' => $this->searchService->search((string)$query, (string)$plannerReasoning)]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            $this->logger->error('SunaQ Search request failed', [
                'app' => 'sunaqfind',
                'exception_class' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            return new DataResponse([
                'ok' => false,
                'error' => $this->t('Search is temporarily unavailable.'),
            ], 502);
        }
    }
}
