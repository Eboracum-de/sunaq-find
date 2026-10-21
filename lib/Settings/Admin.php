<?php
declare(strict_types=1);

namespace OCA\SunaqFind\Settings;

use OCA\SunaqFind\AppInfo\Application;
use OCA\SunaqFind\Service\ConfigService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

final class Admin implements ISettings {
    private $config;
    private $appManager;
    public function __construct(
        ConfigService $config,
        IAppManager $appManager
    ) {
        $this->config = $config;
        $this->appManager = $appManager;
    }

    public function getForm(): TemplateResponse {
        $values = $this->config->adminValues();
        $values['app_version'] = $this->appManager->getAppVersion(Application::APP_ID);

        return new TemplateResponse('sunaqfind', 'admin', $values);
    }

    public function getSection(): string {
        return 'sunaqfind';
    }

    public function getPriority(): int {
        return 0;
    }
}
