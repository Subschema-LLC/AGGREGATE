<?php

declare(strict_types=1);

namespace App\Delivery\Web\TagManager;

use App\Security\Access\DashboardAccessVoter;
use App\Security\Access\WebsiteAccess;
use App\Service\AggregateConfigLoader;
use App\Service\SiteScriptConfig;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The checks every developer snippet request from the Tag manager editor passes
 * before it is answered: the dashboard is on, the route is permitted, the website
 * is one the user can use and is registered, and the form's token is valid.
 */
final class SnippetEditorRequest
{
    public const CSRF_TOKEN_ID = 'tag_manager_settings';

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly WebsiteAccess $access,
        private readonly SiteScriptConfig $sites,
    ) {
    }

    /**
     * The website's script ID ('' for the shared configuration), or the answer that
     * refuses the request.
     */
    public function site(Request $request): string|Response
    {
        if (!$this->config->isDashboardEnabled()) {
            throw new NotFoundHttpException('Dashboard is disabled.');
        }
        if (!$this->authorization->isGranted(DashboardAccessVoter::ROUTE, $request)) {
            throw new AccessDeniedException(
                'You do not have access to this page. Ask an administrator if you need it.',
            );
        }
        $siteId = $request->request->all()['site'] ?? '';
        if (!is_string($siteId)) {
            return self::json(['error' => 'Choose a registered website.'], 422);
        }
        $usable = $siteId === ''
            ? $this->access->allowsEveryWebsite()
            : $this->access->allowsSiteId($siteId);
        if (!$usable) {
            throw new AccessDeniedException('Choose a website you can use.');
        }
        $csrf = $request->request->all()['_csrf_token'] ?? null;
        $token = new CsrfToken(self::CSRF_TOKEN_ID, is_string($csrf) ? $csrf : '');
        if (!is_string($csrf) || !$this->csrf->isTokenValid($token)) {
            return self::json(
                ['error' => 'Invalid security token. Reload the page and try again.'],
                Response::HTTP_FORBIDDEN,
            );
        }
        try {
            if ($siteId !== '') {
                $this->sites->site($siteId);
            }
        } catch (\InvalidArgumentException $e) {
            return self::json(['error' => $e->getMessage()], 422);
        }

        return $siteId;
    }

    /** @param array<string, mixed> $body */
    public static function json(array $body, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($body, $status);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
