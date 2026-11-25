<?php

declare(strict_types=1);

namespace App\Delivery\Web\TagManager;

use App\Features\TagManager\BuildEventSnippet\BuildEventSnippet;
use App\Features\TagManager\ReadSharedEventSnippet\ReadSharedEventSnippet;
use App\Service\TagManagerForm;
use App\Service\TagManagerSettings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * "Get developer snippet": builds the dataLayer.push for one tag row as the editor
 * has it, saved or not, and answers with the snippet or the reason there is none,
 * and until when the saved tag has a shared link. Nothing is saved or sent. The
 * same builder serves app:tag-manager:snippet.
 */
final class BuildEventSnippetController
{
    public const CSRF_TOKEN_ID = SnippetEditorRequest::CSRF_TOKEN_ID;
    private const UNSAVED_ID = 'tag-being-handed-off';

    public function __construct(
        private readonly SnippetEditorRequest $check,
        private readonly TagManagerSettings $settings,
        private readonly BuildEventSnippet $builder,
        private readonly ReadSharedEventSnippet $shared,
    ) {
    }

    #[Route(
        '/dashboard/tag-manager/snippet',
        name: 'app_tag_manager_snippet',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): Response
    {
        $siteId = $this->check->site($request);
        if ($siteId instanceof Response) {
            return $siteId;
        }
        $submitted = $request->request->all();
        $index = $submitted['snippet_tag'] ?? null;
        $rows = $submitted['tags'] ?? null;
        $valid = is_string($index) && preg_match('/^(0|[1-9][0-9]{0,2})$/D', $index) === 1;
        if (!$valid || !is_array($rows) || !is_array($rows[(int) $index] ?? null)) {
            return SnippetEditorRequest::json(
                ['error' => 'Choose a tag to make a snippet for.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
        $row = $rows[(int) $index];
        unset($row['remove']);
        if (TagManagerForm::isBlankRow($row)) {
            return SnippetEditorRequest::json($this->builder->build([], [])->toArray());
        }
        $savedId = is_string($row['id'] ?? null) ? $row['id'] : '';
        if ($savedId === '') {
            $row['id'] = self::UNSAVED_ID;
        }

        try {
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
            $until = $savedId === '' ? null : $this->shared->sharedUntil(
                $siteId === '' ? null : $siteId,
                $savedId,
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            );
        } catch (\InvalidArgumentException $e) {
            // The editor shows the reason in the panel; the button is never disabled.
            return SnippetEditorRequest::json([
                'snippet' => null,
                'reason' => str_replace(self::UNSAVED_ID, 'this tag', $e->getMessage()),
                'attention' => [],
            ]);
        } catch (\Throwable) {
            return SnippetEditorRequest::json(
                ['error' => 'We couldn\'t build the snippet. Check the tag\'s trigger and '
                    .'variables, then try again.'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }
        $tag = $settings['tags'][0] ?? [];
        $body = $this->builder->build($tag, $settings['variables'])->toArray();

        return SnippetEditorRequest::json($body + [
            'tag' => $savedId,
            'shared_until' => $until?->format(\DateTimeInterface::ATOM),
        ]);
    }
}
