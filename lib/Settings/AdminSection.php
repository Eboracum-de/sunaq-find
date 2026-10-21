<?php
declare(strict_types=1);

namespace OCA\SunaqFind\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

final class AdminSection implements IIconSection {
    private $l10n;
    private $urlGenerator;
    public function __construct(
        IL10N $l10n,
        IURLGenerator $urlGenerator
    ) {
        $this->l10n = $l10n;
        $this->urlGenerator = $urlGenerator;
    }

    public function getID(): string {
        return 'sunaqfind';
    }

    public function getName(): string {
        return $this->l10n->t('SunaQ Find');
    }

    public function getPriority(): int {
        return 75;
    }

    public function getIcon(): string {
        return $this->urlGenerator->imagePath('sunaqfind', 'app-dark.svg');
    }
}
