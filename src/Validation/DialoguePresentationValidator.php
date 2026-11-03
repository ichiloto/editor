<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Dialogue\DialogueCatalogFile;
use Ichiloto\Editor\Dialogue\DialogueEmotionFields;
use Ichiloto\Editor\Dialogue\DialogueLineSpeaker;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Events\Triggers\DialogueEventTrigger;
use Ichiloto\Engine\Events\Triggers\ShopEventTrigger;
use Ichiloto\Engine\Messaging\Dialogue\ConditionalDialogue;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePresentationCatalog;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Throwable;

/**
 * Checks the game's dialogue presentation as its pages hold it, unsaved edits included: that the Engine reads the
 * catalogue, that every speaker name binds an identity it holds, that every portrait and bust is an image the game
 * can draw, that every line's emotion is one its speaker's identity has, and that every line whose speaker has more
 * than one emotion names one. Missing artwork, unknown emotions and lines naming none are warnings: the Engine draws
 * the line with the identity's Neutral or base artwork, or none.
 */
final class DialoguePresentationValidator
{
    private const string WHERE = DialogueCatalogFile::RELATIVE_PATH;

    /** @return Issue[] */
    public function validate(ProjectWorkspace $workspace): array
    {
        $references = new ReferenceCatalog($workspace);
        $whole = $references->getDialogueCatalogPayload();
        $issues = $whole === null ? [] : $this->checkSpeakerNames($whole);
        try {
            $catalogue = $references->getDialoguePresentationCatalog();
        } catch (Throwable $error) {
            // A speaker name naming nothing is said above, by name.
            return $issues !== [] ? $issues : [Issue::error(self::WHERE, 'The game refuses the dialogue catalogue: ' . $error->getMessage(),
                'Fix it under Dialogue Portraits and Speaker Names; until then no line shows a portrait.')];
        }

        return [...$issues, ...$this->checkArtwork($catalogue, $references->getAssetRoot()), ...$this->checkLines($workspace, $catalogue)];
    }

    /** @return Issue[] Every speaker name that binds no identity the catalogue holds. */
    private function checkSpeakerNames(array $whole): array
    {
        $identities = [...array_keys((array) ($whole['actors'] ?? [])), ...array_keys((array) ($whole['resources'] ?? []))];
        $issues = [];
        foreach ((array) ($whole['speakers'] ?? []) as $name => $identity) {
            if (! is_string($identity) || ! in_array($identity, array_map(strval(...), $identities), true)) {
                $issues[] = Issue::error(self::WHERE . ':speakers.' . $name,
                    sprintf('Speaker name "%s" shows %s, which is no dialogue portrait.', $name, is_scalar($identity) ? '"' . $identity . '"' : 'nothing'),
                    'Choose one of the Dialogue Portraits for it under Speaker Names, or remove the name. The game refuses the catalogue until then.');
            }
        }

        return $issues;
    }

    /** @return Issue[] Every portrait and bust the game cannot draw, base and emotion alike. */
    private function checkArtwork(DialoguePresentationCatalog $catalogue, string $assetRoot): array
    {
        $issues = [];
        foreach ([...$catalogue->actors, ...$catalogue->resources] as $identity => $record) {
            $sets = ['' => $record];
            foreach ((array) ($record['emotions'] ?? []) as $emotion => $roles) {
                $sets[(string) $emotion] = is_string($roles) ? ['portrait' => $roles] : (array) $roles;
            }
            foreach ($sets as $emotion => $roles) {
                foreach (['portrait' => 'dialogue portrait', 'bust' => 'skit bust'] as $role => $noun) {
                    $asset = $roles[$role] ?? null;
                    if (! is_string($asset)) { continue; }
                    try {
                        PngAssetPreflight::inspect($assetRoot, $asset);
                    } catch (Throwable $error) {
                        $issues[] = Issue::warning(self::WHERE . ':' . $identity . ($emotion === '' ? '' : '.' . $emotion) . '.' . $role,
                            sprintf('%s%s %s %s cannot be drawn: %s', $identity, $emotion === '' ? '\'s' : ' ' . $emotion . '\'s', $noun, $asset, $error->getMessage()),
                            'Choose an image under Dialogue Portraits. Until then the game shows the Neutral or base artwork, or none.');
                    }
                }
            }
        }

        return $issues;
    }

    /** @return Issue[] Every line whose emotion its speaker's identity does not have, or that names none it could. */
    private function checkLines(ProjectWorkspace $workspace, DialoguePresentationCatalog $catalogue): array
    {
        $issues = [];
        $check = function (string $where, DialogueLineSpeaker $rule, array $line, array $owner = []) use (&$issues, $catalogue): void {
            $warning = DialogueEmotionFields::describeWarning($line['emotion'] ?? null, DialogueEmotionFields::describeChoices($rule, $line, $owner, $catalogue));
            if ($warning !== null) {
                $issues[] = Issue::warning($where, $warning);
            }
        };
        $root = rtrim($workspace->projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $relative = static fn(string $path): string => str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;

        foreach ($workspace->getRecordDatabase('skits')?->getRecords() ?? [] as $record) {
            $skit = (array) $record->toArray();
            $where = $relative($record->sourcePath ?? 'assets/Data/Skits/' . $record->recordId . '.php');
            foreach (array_values((array) ($skit['beats'] ?? [])) as $index => $beat) {
                if (is_array($beat)) { $check(sprintf('%s:beat %d %s', $where, $index + 1, self::quoteLine($beat)), DialogueLineSpeaker::forSkitBeat(), $beat); }
            }
        }
        foreach ($workspace->getRecordDatabase('common_events')?->getRecords() ?? [] as $record) {
            $this->visitTextCommands((array) $record->toArray(), $relative($record->sourcePath ?? 'assets/Events/' . $record->recordId . '.php'), $check);
        }
        foreach ($workspace->maps as $map) {
            $found = count($issues);
            $data = $map->getEditableData();
            $where = $relative($map->dataPath);
            foreach (array_values((array) ($data['npcs'] ?? [])) as $index => $npc) {
                if (! is_array($npc)) { continue; }
                $npcWhere = sprintf('%s:NPC %s', $where, (string) ($npc['id'] ?? $npc['name'] ?? $index + 1));
                foreach ($this->listPages((array) ($npc['dialogue'] ?? [])) as $line => $page) {
                    $check(sprintf('%s line %d %s', $npcWhere, $line + 1, self::quoteLine($page)), DialogueLineSpeaker::forNpcLine(), $page, $npc);
                }
                $this->visitTextCommands($npc, $npcWhere, $check);
            }
            foreach ((array) ($data['events'] ?? []) as $marker => $event) {
                if (! is_array($event)) { continue; }
                $eventWhere = sprintf('%s:event %s', $where, $marker);
                $class = ltrim((string) ($event['class'] ?? ''), '\\');
                if (in_array($class, [DialogueEventTrigger::class, ShopEventTrigger::class], true)) {
                    foreach ($this->listPages((array) ($event['data']['dialogue'] ?? [])) as $line => $page) {
                        $check(sprintf('%s page %d %s', $eventWhere, $line + 1, self::quoteLine($page)), DialogueLineSpeaker::forText(), $page);
                    }
                }
                $this->visitTextCommands($event, $eventWhere, $check);
            }
            array_splice($issues, $found, count($issues) - $found, Issue::attributeToMap(array_slice($issues, $found), $map->mapId));
        }
        foreach ($workspace->cutscenes?->assets(CutsceneType::CINEMATIC) ?? [] as $cinematic) {
            if (! $cinematic->isDeleted()) {
                $this->visitTextCommands($cinematic->payload(), $relative($cinematic->dataPath()), $check);
            }
        }

        return $issues;
    }

    /**
     * Every page a dialogue list shows: its pages, or each conditional variant's lines, in order.
     *
     * @return list<array<string, mixed>>
     */
    private function listPages(array $dialogue): array
    {
        if (! ConditionalDialogue::isVariantList($dialogue)) {
            return array_values(array_filter($dialogue, 'is_array'));
        }
        $pages = [];
        foreach ($dialogue as $variant) {
            foreach (is_array($variant) ? (array) ($variant['lines'] ?? []) : [] as $page) {
                if (is_array($page)) { $pages[] = $page; }
            }
        }

        return $pages;
    }

    /**
     * Visits every `text` command anywhere under a payload: a script's, a branch's arms, a choice's options, a
     * cinematic's blocks.
     *
     * @param callable(string, DialogueLineSpeaker, array<string, mixed>): void $check
     */
    private function visitTextCommands(array $payload, string $where, callable $check): void
    {
        if (($payload['type'] ?? null) === 'text') {
            $check(sprintf('%s:text %s', $where, self::quoteLine($payload)), DialogueLineSpeaker::forText(), $payload);
        }
        foreach ($payload as $value) {
            if (is_array($value)) { $this->visitTextCommands($value, $where, $check); }
        }
    }

    /** The opening of a line's text, quoted, so an author finds the line among its neighbours. */
    private static function quoteLine(array $line): string
    {
        return '"' . mb_strimwidth(is_scalar($line['text'] ?? null) ? (string) $line['text'] : '', 0, 40, '...') . '"';
    }
}
