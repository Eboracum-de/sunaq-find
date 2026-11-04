<?php
declare(strict_types=1);

namespace OCA\SunaqFind\Controller;

use OCA\SunaqFind\Service\ConfigService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

final class PageController extends Controller {
    private $config;

    public function __construct(
        string $appName,
        IRequest $request,
        ConfigService $config
    ) {
        parent::__construct($appName, $request);
        $this->config = $config;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): TemplateResponse {
        return new TemplateResponse('sunaqfind', 'main', [
            'analysis_mode' => $this->config->isAnalysisMode(),
        ]);
    }
}
