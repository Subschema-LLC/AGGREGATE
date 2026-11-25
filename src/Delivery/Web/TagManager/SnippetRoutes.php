<?php

declare(strict_types=1);

namespace App\Delivery\Web\TagManager;

use App\Security\Access\DashboardPages;
use App\Service\Operations\AuditTrailSettings as Area;

/**
 * The developer snippet's dashboard routes, as the two route lists take them:
 * what each records in the audit trail (AdminActions::ROUTES) and the Tag
 * manager level it needs (DashboardPages). All three run the editor's tag,
 * or open or close a public page for it, so they go with editing.
 * app:tag-manager:snippet does each from the command line.
 */
final class SnippetRoutes
{
    public const AUDITED = [
        'app_tag_manager_snippet' => [Area::SETTINGS, 'tag_snippet_built'],
        'app_tag_manager_snippet_share' => [Area::SETTINGS, 'tag_snippet_shared'],
        'app_tag_manager_snippet_share_stop' => [Area::SETTINGS, 'tag_snippet_share_stopped'],
    ];

    public const PAGES = [
        'app_tag_manager_snippet' => ['tag_manager', DashboardPages::EDIT],
        'app_tag_manager_snippet_share' => ['tag_manager', DashboardPages::EDIT],
        'app_tag_manager_snippet_share_stop' => ['tag_manager', DashboardPages::EDIT],
    ];

    /** The command that does the same as each route, for SettingsParityTest. */
    public const COMMAND = 'app:tag-manager:snippet';

    /** The shared page's path, as the route builds it, for the command line. */
    public static function sharedPath(?string $siteId, string $token): string
    {
        return '/shared-snippet/'.($siteId ?? SharedEventSnippetController::SHARED).'/'.$token;
    }
}
