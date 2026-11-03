<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\Database\StorybookPlaybackFields;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Messaging\Storybook\StorybookDefinition;
use Ichiloto\Engine\Messaging\Storybook\StorybookLibrary;
use Throwable;

/**
 * Checks the project's storybooks as the Engine reads them, and what a
 * `play_storybook` command selects. Each storybook the Engine's library
 * discovers is loaded once per validation: one the Engine refuses is an
 * error, and a page picture the game cannot show is a warning, since that
 * page stays readable as narration alone.
 */
final class StorybookValidator
{
    /** @var array<string, StorybookDefinition|string> Each storybook, or the Engine's reason for refusing it, by id. */
    private array $books = [];

    private string $assetRoot;

    public function __construct(ProjectWorkspace $workspace)
    {
        $this->assetRoot = $workspace->catalogs->getAssetRoot();
        foreach ($workspace->catalogs->listStorybookIds() as $id) {
            try {
                $this->books[$id] = $workspace->catalogs->loadStorybook($id);
            } catch (Throwable $error) {
                $this->books[$id] = $error->getMessage();
            }
        }
    }

    /**
     * Checks every storybook the project has.
     *
     * @return Issue[]
     */
    public function validate(): array
    {
        $issues = [];
        foreach ($this->books as $id => $book) {
            $where = self::describeSource((string) $id);
            if (is_string($book)) {
                $issues[] = Issue::error($where, 'The game refuses this storybook: ' . $book,
                    'A play_storybook command that selects it fails. Correct its source.');
                continue;
            }
            foreach ($book->diagnostics as $diagnostic) {
                $issues[] = Issue::warning($where, $diagnostic, 'The page shows its narration without a picture.');
            }
            foreach ($book->pages as $page) {
                if ($page->picture !== null && $page->getPictureSize($this->assetRoot) === null) {
                    $issues[] = Issue::warning($where, sprintf('Page %s shows the picture %s, which the game cannot read.', $page->id, $page->picture),
                        'The page shows its narration without a picture. Check the path, or add the PNG.');
                }
            }
        }

        return $issues;
    }

    /**
     * Checks what a `play_storybook` command selects: it must name a
     * storybook the project has and the Engine can load, or the runtime fails
     * the command.
     *
     * @param array<array-key, mixed> $command
     * @return Issue[]
     */
    public function checkPlayback(array $command, string $where): array
    {
        $id = $command[StorybookPlaybackFields::getReferenceField()] ?? null;
        if (! is_string($id) || trim($id) === '') {
            return [Issue::error($where, 'It plays no storybook.', 'Choose a storybook from the picker.')];
        }
        $book = $this->books[$id] ?? null;
        if ($book === null) {
            return [Issue::error($where, sprintf('It names the storybook "%s", which does not exist.', $id),
                'The runtime fails this command. Choose a storybook from the picker.')];
        }
        if (is_string($book)) {
            return [Issue::error($where, sprintf('It plays the storybook "%s", which the game refuses.', $id),
                sprintf('The runtime fails this command. Correct %s.', self::describeSource($id)))];
        }

        return [];
    }

    private static function describeSource(string $id): string
    {
        return sprintf('assets/%s/%s.php', StorybookLibrary::DIRECTORY, $id);
    }
}
