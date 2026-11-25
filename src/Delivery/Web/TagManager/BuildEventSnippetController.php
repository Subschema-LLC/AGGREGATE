<?php

declare(strict_types=1);

namespace App\Delivery\Web\TagManager;

use App\Features\TagManager\BuildEventSnippet\BuildEventSnippet;
use App\Security\Access\DashboardAccessVoter;
use App\Security\Access\WebsiteAccess;
use App\Service\AggregateConfigLoader;
use App\Service\SiteScriptConfig;
use App\Service\TagManagerForm;
use App\Service\TagManagerSettings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "Get developer snippet": builds the dataLayer.push for one tag row as the editor
 * has it, saved or not, and answers with the snippet or the reason there is none.
 * Nothing is saved or sent. The same builder serves app:tag-manager:snippet.
 */
final class BuildEventSnippetController
{
    public const CSRF_TOKEN_ID = 'tag_manager_settings';

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly WebsiteAccess $access,
        private readonly TagManagerSettings $settings,
        private readonly SiteScriptConfig $sites,
        private readonly BuildEventSnippet $builder,
    ) {
    }

    #[Route(
        '/dashboard/tag-manager/snippet',
        name: 'app_tag_manager_snippet',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): Response
    {
        if (!$this->config->isDashboardEnabled()) {
            throw new NotFoundHttpException('Dashboard is disabled.');
        }
        if (!$this->authorization->isGranted(DashboardAccessVoter::ROUTE, $request)) {
            throw new AccessDeniedException(
                'You do not have access to this page. Ask an administrator if you need it.',
            );
        }
        $submitted = $request->request->all();
        $siteId = $submitted['site'] ?? '';
        if (!is_string($siteId)) {
            return $this->failure('Choose a registered website.');
        }
        $usable = $siteId === ''
            ? $this->access->allowsEveryWebsite()
            : $this->access->allowsSiteId($siteId);
        if (!$usable) {
            throw new AccessDeniedException('Choose a website you can use.');
        }
        $csrf = $submitted['_csrf_token'] ?? null;
        $token = new CsrfToken(self::CSRF_TOKEN_ID, is_string($csrf) ? $csrf : '');
        if (!is_string($csrf) || !$this->csrf->isTokenValid($token)) {
            return $this->failure(
                'Invalid security token. Reload the page and try again.',
                Response::HTTP_FORBIDDEN,
            );
        }
        $index = $submitted['snippet_tag'] ?? null;
        $rows = $submitted['tags'] ?? null;
        $valid = is_string($index) && preg_match('/^(0|[1-9][0-9]{0,2})$/D', $index) === 1;
        if (!$valid || !is_array($rows) || !is_array($rows[(int) $index] ?? null)) {
            return $this->failure('Choose a tag to make a snippet for.');
        }
        $row = $rows[(int) $index];
        unset($row['remove']);
        if (TagManagerForm::isBlankRow($row)) {
            return $this->answer($this->builder->build([], [])->toArray());
        }
        if (($row['id'] ?? '') === '') {
            $row['id'] = 'tag-being-handed-off';
        }

        try {
            if ($siteId !== '') {
                $this->sites->site($siteId);
            }
            $settings = TagManagerForm::settings(
                [
                    'site' => $siteId,
                    'enabled' => '0',
                    'variables' => $submitted['variables'] ?? [],
                    'tags' => [$row],
                ],
                $this->settings->properties(),
                $this->settings->syntax(),
            );
        } catch (\InvalidArgumentException $e) {
            // The editor shows the reason in the panel; the button is never disabled.
            return $this->answer([
                'snippet' => null,
                'reason' => str_replace('tag-being-handed-off', 'this tag', $e->getMessage()),
                'attention' => [],
            ]);
        } catch (\Throwable) {
            return $this->failure(
                'We couldn\'t build the snippet. Check the tag\'s trigger and variables, '
                .'then try again.',
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }
        $tag = $settings['tags'][0] ?? [];

        return $this->answer($this->builder->build($tag, $settings['variables'])->toArray());
    }

    /** @param array<string, mixed> $body */
    private function answer(array $body, int $status = Response::HTTP_OK): Response
    {
        $response = new JsonResponse($body, $status);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function failure(
        string $message,
        int $status = Response::HTTP_UNPROCESSABLE_ENTITY,
    ): Response {
        return $this->answer(['error' => $message], $status);
    }
}
