<?php

declare(strict_types=1);

namespace App\Delivery\Web\TagManager;

use App\Features\TagManager\ShareEventSnippet\ShareEventSnippet;
use App\Features\TagManager\StopSharingEventSnippet\StopSharingEventSnippet;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Share link" and "Stop sharing" in the developer snippet panel: a read-only page
 * for the saved tag at an unguessable address, lasting the days set on General
 * settings, and the way to close it at once. app:tag-manager:snippet does both.
 */
final class ShareEventSnippetController
{
    public function __construct(
        private readonly SnippetEditorRequest $check,
        private readonly ShareEventSnippet $share,
        private readonly StopSharingEventSnippet $stop,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    #[Route(
        '/dashboard/tag-manager/snippet/share',
        name: 'app_tag_manager_snippet_share',
        methods: ['POST'],
    )]
    public function share(Request $request): Response
    {
        [$siteId, $tagId, $refused] = $this->target($request);
        if ($refused !== null) {
            return $refused;
        }
        try {
            $link = $this->share->share($siteId, $tagId, self::now());
        } catch (\InvalidArgumentException $e) {
            return SnippetEditorRequest::json(['error' => $e->getMessage()], 422);
        }
        $url = $this->urls->generate(
            SharedEventSnippetController::ROUTE,
            ['site' => $siteId ?? SharedEventSnippetController::SHARED, 'token' => $link->token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return SnippetEditorRequest::json([
            'url' => $url,
            'shared_until' => $link->expires->format(\DateTimeInterface::ATOM),
            'message' => 'Anyone with this link can read this snippet until '
                .$link->expires->format('j M Y').'. It shows the saved tag only.',
        ]);
    }

    #[Route(
        '/dashboard/tag-manager/snippet/share/stop',
        name: 'app_tag_manager_snippet_share_stop',
        methods: ['POST'],
    )]
    public function stop(Request $request): Response
    {
        [$siteId, $tagId, $refused] = $this->target($request);
        if ($refused !== null) {
            return $refused;
        }
        $stopped = $this->stop->stop($siteId, $tagId, self::now());

        return SnippetEditorRequest::json([
            'shared_until' => null,
            'message' => $stopped
                ? 'Sharing stopped. The link no longer opens.'
                : 'This tag had no open link.',
        ]);
    }

    /** @return array{0: ?string, 1: string, 2: ?Response} */
    private function target(Request $request): array
    {
        $siteId = $this->check->site($request);
        if ($siteId instanceof Response) {
            return [null, '', $siteId];
        }
        $tagId = $request->request->all()['tag'] ?? null;
        if (!is_string($tagId) || $tagId === '') {
            $refused = SnippetEditorRequest::json(
                ['error' => 'Save this tag first. A shared link shows the saved tag only.'],
                422,
            );

            return [null, '', $refused];
        }

        return [$siteId === '' ? null : $siteId, $tagId, null];
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
