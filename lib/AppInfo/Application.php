<?php
declare(strict_types=1);

namespace OCA\SunaqFind\AppInfo;

use OCA\SunaqFind\Search\SearchShortcutProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

final class Application extends App implements IBootstrap {
    public const APP_ID = 'sunaqfind';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerSearchProvider(SearchShortcutProvider::class);
    }

    public function boot(IBootContext $context): void {
    }
}
