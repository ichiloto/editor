<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use LogicException;

/**
 * Authors the Engine's storybook playback contract
 * ({@see CinematicCommandSchema::export()} `storybookPlayback`): the
 * `play_storybook` command that selects one of the project's storybooks by
 * its stable id.
 *
 * The Engine owns the command name and its reference field; this reads them
 * from the export, so the Editor offers exactly what the runtime accepts.
 * The Engine's StorybookLibrary owns which storybooks a project has
 * ({@see ProjectCatalogs::listStorybookIds()}).
 */
final class StorybookPlaybackFields
{
    /** The reference kind listing the project's storybooks by stable id. */
    public const string REFERENCE_CATEGORY = 'storybooks';

    private function __construct()
    {
    }

    /** The command that plays a selected storybook. */
    public static function getCommandType(): string
    {
        return strval(self::readContract()['command']);
    }

    /** The command field naming the storybook by its stable id. */
    public static function getReferenceField(): string
    {
        return strval(self::readContract()['referenceField']);
    }

    /**
     * The fields of the `play_storybook` command: its storybook, chosen from
     * the project's storybooks by stable id.
     *
     * @return list<RecordField>
     */
    public static function getCommandFields(): array
    {
        return [RecordField::reference(self::getReferenceField(), 'Storybook', self::REFERENCE_CATEGORY)];
    }

    /** @return array<string, mixed> */
    private static function readContract(): array
    {
        $contract = CinematicCommandSchema::export()['storybookPlayback'] ?? null;

        return is_array($contract) ? $contract : throw new LogicException('The Engine exports no storybookPlayback contract.');
    }
}
