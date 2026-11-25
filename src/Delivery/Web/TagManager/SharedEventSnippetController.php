<?php

declare(strict_types=1);

namespace App\Delivery\Web\TagManager;

use App\Features\TagManager\BuildEventSnippet\BuildEventSnippet;
use App\Features\TagManager\ReadSharedEventSnippet\ReadSharedEventSnippet;
use App\Service\AggregateConfigLoader;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Twig\Environment;

/**
 * The read-only page a shared snippet link opens, with no sign-in: the saved tag's
 * snippet and its attention items, and nothing else of the tag or the website.
 * An unknown, expired or stopped link gets the same "not open" page, so a guess
 * learns nothing. The page is never cached or indexed and sends no referrer.
 */
final class SharedEventSnippetController
{
    public const ROUTE = 'app_tag_manager_shared_snippet';
    /** The site part of a link to the shared (all-website) configuration. */
    public const SHARED = 'shared';

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly ReadSharedEventSnippet $shared,
        private readonly BuildEventSnippet $builder,
        private readonly Environment $twig,
    ) {
    }

    #[Route(
        '/shared-snippet/{site}/{token}',
        name: self::ROUTE,
        requirements: ['site' => '[a-f0-9]{24}|shared', 'token' => '[a-f0-9]{48}'],
        methods: ['GET'],
    )]
    public function __invoke(string $site, string $token): Response
    {
        if (!$this->config->isDashboardEnabled()) {
            throw new NotFoundHttpException('Dashboard is disabled.');
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        try {
            $opened = $this->shared->open($site === self::SHARED ? null : $site, $token, $now);
        } catch (\InvalidArgumentException|\RuntimeException) {
            $opened = null;
        }
        $snippet = $opened === null
            ? null
            : $this->builder->build($opened['tag'], $opened['variables']);
        $response = new Response($this->twig->render('tag_manager/shared_snippet.html.twig', [
            'snippet' => $snippet?->snippet,
            'reason' => $snippet?->reason,
            'attention' => $snippet === null ? [] : $snippet->attention,
            'open' => $opened !== null,
            'expires' => $opened['expires'] ?? null,
        ]), $opened === null ? Response::HTTP_NOT_FOUND : Response::HTTP_OK);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
