<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Dialogue;

use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueContext;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePresentationCatalog;
use Throwable;

/**
 * A dialogue line's emotion row as an author sees it: chosen among the emotions its speaker's catalogue identity
 * names, and, when it holds an emotion that identity lacks, followed by a row saying so. The value is kept as it is,
 * since art for it may be on its way; the Engine shows the identity's Neutral artwork until it exists.
 */
final class DialogueEmotionFields
{
    private function __construct() {}

    /**
     * The emotions one line may show, as its speaker's catalogue identity names them: who speaks, the identity
     * whose artwork shows (null when the speaker has none), the emotions offered, and no issue.
     *
     * @return array{speaker: string, identity: ?string, emotions: list<string>, issue: ?string}
     */
    public static function describeChoices(DialogueLineSpeaker $rule, array $line, array $owner, DialoguePresentationCatalog $catalogue): array
    {
        $identity = $rule->resolveIdentity($line, $owner, $catalogue);
        $identity = DialogueCatalogFile::findIdentity($catalogue, $identity) === null ? null : $identity;

        return ['speaker' => $rule->describeSpeaker($line, $owner), 'identity' => $identity,
            'emotions' => DialogueCatalogFile::listEmotions($catalogue, $identity), 'issue' => null];
    }

    /**
     * A line's choices while the catalogue cannot be read: Neutral, which the Engine always shows, and why.
     *
     * @return array{speaker: string, identity: ?string, emotions: list<string>, issue: ?string}
     */
    public static function describeUnreadableCatalogue(DialogueLineSpeaker $rule, array $line, array $owner, Throwable $error): array
    {
        return ['speaker' => $rule->describeSpeaker($line, $owner), 'identity' => null,
            'emotions' => [DialogueContext::DEFAULT_EMOTION], 'issue' => 'The dialogue catalogue cannot be read: ' . $error->getMessage()];
    }

    /**
     * The emotion row with its choices, and the warning row after it when the value is not one of them.
     *
     * @param array<string, mixed> $row The emotion row as the record describes it.
     * @param mixed $current The line's emotion as authored, null when it has none.
     * @param array{speaker: string, identity: ?string, emotions: list<string>, issue: ?string} $emotions
     * @return list<array<string, mixed>>
     */
    public static function describeRows(array $row, mixed $current, array $emotions): array
    {
        if (($row['editable'] ?? true) !== false) {
            // Chosen, never typed: the speaker's emotions are the choices.
            unset($row['control']);
            $row['options'] = $emotions['emotions'];
            $row['choices'] = array_map(static fn(string $emotion): array => ['value' => $emotion, 'label' => $emotion], $emotions['emotions']);
        }
        $warning = self::describeWarning($current, $emotions);
        if ($warning === null) {
            return [$row];
        }

        return [$row, array_filter([
            'label' => preg_replace('/Emotion$/', 'Emotion Warning', (string) ($row['label'] ?? 'Emotion')),
            'value' => $warning,
            'editable' => false,
            'tone' => 'warning',
            'entry' => $row['entry'] ?? null,
            'entryLabel' => $row['entryLabel'] ?? null,
            'name' => 'Emotion Warning',
        ], static fn(mixed $value): bool => $value !== null)];
    }

    /**
     * Why a line's emotion is not one its speaker's identity offers, or null when it is.
     *
     * @param array{speaker: string, identity: ?string, emotions: list<string>, issue: ?string} $emotions
     */
    public static function describeWarning(mixed $current, array $emotions): ?string
    {
        if ($current === null || $current === '' || $current === DialogueContext::DEFAULT_EMOTION || in_array($current, $emotions['emotions'], true)) {
            return null;
        }
        $emotion = is_scalar($current) ? (string) $current : get_debug_type($current);
        if ($emotions['issue'] !== null) {
            return $emotions['issue'];
        }
        if ($emotions['identity'] === null) {
            return $emotions['speaker'] === ''
                ? sprintf('The line has no speaker, so "%s" shows no portrait.', $emotion)
                : sprintf('%s has no dialogue portrait, so "%s" shows none. Bind the name under Speaker Names.', $emotions['speaker'], $emotion);
        }

        return sprintf('%s has no "%s" emotion; the game shows its Neutral artwork until one is added under Dialogue Portraits.',
            $emotions['identity'], $emotion);
    }
}
