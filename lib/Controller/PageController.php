<?php
declare(strict_types=1);

namespace OCA\SunaqFind\Controller;

use OCA\SunaqFind\Service\ConfigService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

final class PageController extends Controller {
    private $config;
    private $userSession;
    private $groupManager;
    private $urlGenerator;

    public function __construct(
        string $appName,
        IRequest $request,
        ConfigService $config,
        IUserSession $userSession,
        IGroupManager $groupManager,
        IURLGenerator $urlGenerator
    ) {
        parent::__construct($appName, $request);
        $this->config = $config;
        $this->userSession = $userSession;
        $this->groupManager = $groupManager;
        $this->urlGenerator = $urlGenerator;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): TemplateResponse {
        $configured = $this->config->isConfigured();
        $isAdmin = false;
        $adminUrl = '';

        if (!$configured) {
            $user = $this->userSession->getUser();
            if ($user !== null && $this->groupManager->isAdmin($user->getUID())) {
                $isAdmin = true;
                $adminUrl = $this->urlGenerator->linkToRoute(
                    'settings.AdminSettings.index',
                    ['section' => 'sunaqfind']
                );
            }
        }

        return new TemplateResponse('sunaqfind', 'main', [
            'analysis_mode' => $this->config->isAnalysisMode(),
            'configured' => $configured,
            'is_admin' => $isAdmin,
            'admin_url' => $adminUrl,
        ]);
    }
}
