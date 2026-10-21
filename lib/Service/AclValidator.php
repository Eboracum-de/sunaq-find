<?php
declare(strict_types=1);

namespace OCA\SunaqFind\Service;

use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserSession;

class AclValidator {
    private $rootFolder;
    private $userSession;
    private $urlGenerator;
    private $groupManager;
    public function __construct(
        IRootFolder $rootFolder,
        IUserSession $userSession,
        IURLGenerator $urlGenerator,
        IGroupManager $groupManager
    ) {
        $this->rootFolder = $rootFolder;
        $this->userSession = $userSession;
        $this->urlGenerator = $urlGenerator;
        $this->groupManager = $groupManager;
    }

    public function prefilterContext(): array {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return ['available' => false, 'user' => '', 'groups' => [], 'reason' => 'no_user'];
        }

        $uid = trim((string)$user->getUID());
        if ($uid === '') {
            return ['available' => false, 'user' => '', 'groups' => [], 'reason' => 'empty_uid'];
        }

        try {
            if (!method_exists($this->groupManager, 'getUserGroupIds')) {
                return ['available' => false, 'user' => $uid, 'groups' => [], 'reason' => 'group_api_missing'];
            }
            $rawGroups = $this->groupManager->getUserGroupIds($user);
        } catch (\Throwable $e) {
            return ['available' => false, 'user' => $uid, 'groups' => [], 'reason' => 'group_lookup_failed'];
        }

        $groups = [];
        foreach (is_array($rawGroups ?? null) ? $rawGroups : [] as $group) {
            $group = trim((string)$group);
            if ($group === '' || isset($groups[$group])) {
                continue;
            }
            $groups[$group] = true;
            if (count($groups) >= 256) {
                break;
            }
        }

        return [
            'available' => true,
            'user' => $uid,
            'groups' => array_keys($groups),
            'reason' => 'ok',
        ];
    }

    public function inspect(int $fileId): array {
        if ($fileId <= 0) {
            throw new \InvalidArgumentException('fileId must be a positive integer');
        }
        $user = $this->userSession->getUser();
        if ($user === null) {
            return ['allowed' => false, 'reason' => 'not_authenticated'];
        }

        try {
            $folder = $this->rootFolder->getUserFolder($user->getUID());
            $resolved = $folder->getById($fileId);
        } catch (\Throwable $e) {
            return ['allowed' => false, 'reason' => 'not_found'];
        }

        $nodes = is_array($resolved) ? $resolved : ($resolved === null ? [] : [$resolved]);
        foreach ($nodes as $node) {
            try {
                if (!$node->isReadable()) {
                    continue;
                }
                $resolvedId = (int)$node->getId();
                $path = (string)$node->getPath();
                if (method_exists($folder, 'getRelativePath')) {
                    $relative = $folder->getRelativePath($path);
                    if (is_string($relative) && $relative !== '') {
                        $path = $relative;
                    }
                }
                return [
                    'allowed' => true,
                    'file_id' => $resolvedId,
                    'name' => (string)$node->getName(),
                    'path' => $path,
                    'mimetype' => (string)$node->getMimeType(),
                    'permissions' => (int)$node->getPermissions(),
                    'url' => $this->sourceUrl($resolvedId),
                ];
            } catch (\Throwable $e) {
                continue;
            }
        }

        return ['allowed' => false, 'reason' => $nodes === [] ? 'not_found' : 'not_readable'];
    }

    private function sourceUrl(int $fileId): string {
        // The dedicated /f/{fileid} route exists on both old and current
        // Nextcloud releases, but historical releases registered the route
        // with an upper-case controller segment (files.View.showFile).
        // Try both public route names before giving up.
        foreach (['files.View.showFile', 'files.view.showFile'] as $route) {
            try {
                $url = $this->urlGenerator->linkToRoute($route, ['fileid' => $fileId]);
                if (is_string($url) && $url !== '') {
                    return $url;
                }
            } catch (\Throwable $e) {
                // Try the cross-version spelling below.
            }
        }

        return '';
    }
}
