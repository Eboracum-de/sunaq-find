<?php
declare(strict_types=1);

namespace OCA\SunaqFind\Search;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

final class SearchShortcutProvider implements IProvider {
    private $urlGenerator;
    private $l10n;
    public function __construct(
        IURLGenerator $urlGenerator,
        IL10N $l10n
    ) {
        $this->urlGenerator = $urlGenerator;
        $this->l10n = $l10n;
    }

    public function getId(): string {
        return 'sunaqfind';
    }

    public function getName(): string {
        return $this->l10n->t('SunaQ Find');
    }

    public function getOrder(string $route, array $routeParameters): int {
        return -1;
    }

    public function search(IUser $user, ISearchQuery $query): SearchResult {
        $term = trim($query->getTerm());
        if ($term === '' || mb_strlen($term, 'UTF-8') < 2) {
            return SearchResult::complete($this->getName(), []);
        }

        $url = $this->urlGenerator->linkToRoute('sunaqfind.page.index');
        $url .= (strpos($url, '?') !== false ? '&' : '?') . http_build_query([
            'q' => $term,
            'run' => '1',
        ], '', '&', PHP_QUERY_RFC3986);

        $entry = new SearchResultEntry(
            $this->urlGenerator->imagePath('sunaqfind', 'app.svg'),
            $this->l10n->t('Search with SunaQ Find'),
            mb_substr($term, 0, 180, 'UTF-8'),
            $url,
            '',
            false
        );

        return SearchResult::complete($this->getName(), [$entry]);
    }
}
