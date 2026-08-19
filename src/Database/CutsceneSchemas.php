<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\Cutscenes\CutsceneAsset;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;

/**
 * How a cinematic and a summon are edited: the fields, sub-lists and
 * command lists their records offer.
 *
 * Every vocabulary here is the engine's, read from `CinematicCommandSchema`
 * and the interpreter rather than copied: command types, block shapes,
 * subject kinds, camera operations, staged-actor fields, skip policies,
 * finalizer types and shapes, music fields, and the summon definition,
 * timeline and playback fields. The editor adds only labels, controls and
 * which project catalogue a reference is picked from.
 *
 * @package Ichiloto\Editor\Database
 */
final class CutsceneSchemas
{
    /**
     * The Database-style category key of the cinematic records.
     */
    public const string CINEMATICS_KEY = 'cinematics';

    /**
     * The Database-style category key of the summon records.
     */
    public const string SUMMONS_KEY = 'summons';

    /**
     * The payload key a cinematic's finalizer commands are edited under.
     */
    public const string FINALIZER_KEY = 'finalizer';

    /**
     * The payload key a summon's tracks are edited under.
     */
    public const string TRACKS_KEY = 'tracks';

    /**
     * The payload key a summon's cues are edited under.
     */
    public const string CUES_KEY = 'cues';

    /**
     * Cardinal facings a staged actor and a movement route step use.
     */
    public const array FACINGS = ['north', 'south', 'east', 'west'];

    /**
     * Returns the cinematic category: one record per asset, its metadata as
     * fields, its cast as a sub-list, and its script and finalizer as
     * command lists opened as frames.
     */
    public static function cinematics(): RecordSchema
    {
        return new RecordSchema(
            key: self::CINEMATICS_KEY,
            entryNoun: 'cinematic',
            storage: RecordStorage::MAP_OWNED,
            relativePath: 'assets/Cutscenes/Cinematics',
            fields: [
                // The id is the folder: identity, not a field to retype.
                new RecordField('id', 'Id', isReadOnly: true),
                new RecordField('name', 'Name'),
                new RecordField('description', 'Description', removeWhenEmpty: true),
                new RecordField('version', 'Version', InputControlType::INTEGER),
                RecordField::reference('startMap', 'Start Map', 'maps', allowsNone: true, noneLabel: '(not declared)'),
                new RecordField('presentation.initial', 'Initial Presentation', options: ['visible', 'hidden', 'black'], removeWhenEmpty: true, displayDefault: 'visible'),
                new RecordField('presentation.reducedMotion', 'Reduced Motion', options: ['final-state'], removeWhenEmpty: true, displayDefault: '(engine default)'),
                new RecordField('skip.policy', 'Skip Policy', options: CinematicCommandSchema::SKIP_POLICIES),
                new RecordField('checkpoints', 'Checkpoints', codec: RecordFieldCodec::CSV_LIST, removeWhenEmpty: true),
                new RecordField('authoring', 'Authoring Metadata', codec: RecordFieldCodec::KEY_VALUES, removeWhenEmpty: true),
            ],
            labelKey: 'name',
            identityKey: 'id',
            blank: [
                'id' => 'new-cinematic',
                'name' => 'New Cinematic',
                'description' => '',
                'version' => 1,
                'skip' => ['policy' => 'forbidden'],
                'cast' => [],
                CutsceneAsset::COMMANDS_KEY => [],
            ],
            subList: self::castList(),
            commandLists: [
                CutsceneAsset::COMMANDS_KEY => self::cinematicCommandList(CutsceneAsset::COMMANDS_KEY),
                self::FINALIZER_KEY => self::finalizerCommandList(),
            ],
        );
    }

    /**
     * Returns the summon category: one record per asset, its definition as
     * fields, its tracks and cues as command-style lists opened as frames.
     */
    public static function summons(): RecordSchema
    {
        return new RecordSchema(
            key: self::SUMMONS_KEY,
            entryNoun: 'summon',
            storage: RecordStorage::MAP_OWNED,
            relativePath: 'assets/Cutscenes/Summons',
            fields: [
                new RecordField('id', 'Id', isReadOnly: true),
                new RecordField('name', 'Name'),
                new RecordField('description', 'Description', removeWhenEmpty: true),
                new RecordField('moveName', 'Move Name', removeWhenEmpty: true),
                new RecordField('version', 'Version', InputControlType::INTEGER, removeWhenEmpty: true),
                new RecordField('linkedSummonId', 'Linked Summon Id', removeWhenEmpty: true),
                RecordField::reference('linkedActionId', 'Linked Action', 'skills', allowsNone: true, noneLabel: '(none)'),
                new RecordField('tags', 'Tags', codec: RecordFieldCodec::CSV_LIST, removeWhenEmpty: true),
                // Lore and metadata.
                new RecordField('lore', 'Lore', removeWhenEmpty: true),
                RecordField::reference('element', 'Element', 'elements', allowsNone: true, noneLabel: '(none)'),
                new RecordField('strengths', 'Strengths', codec: RecordFieldCodec::CSV_LIST, removeWhenEmpty: true),
                new RecordField('weaknesses', 'Weaknesses', codec: RecordFieldCodec::CSV_LIST, removeWhenEmpty: true),
                new RecordField('attributes', 'Attributes', codec: RecordFieldCodec::KEY_VALUES, removeWhenEmpty: true),
                new RecordField('authoring', 'Authoring Metadata', codec: RecordFieldCodec::KEY_VALUES, removeWhenEmpty: true),
                // Availability: omitted is open; declared conditions must hold.
                new RecordField('availability.conditions', 'Availability', codec: RecordFieldCodec::CONDITIONS, removeWhenEmpty: true, displayDefault: '(omitted: open)'),
                // Wielder policy.
                new RecordField('wielders.mode', 'Wielders', options: ['all', 'roles', 'characters'], removeWhenEmpty: true, displayDefault: '(omitted: open)'),
                new RecordField('wielders.roles', 'Wielder Roles', codec: RecordFieldCodec::CSV_LIST, reference: 'classes', removeWhenEmpty: true),
                new RecordField('wielders.characters', 'Wielder Characters', codec: RecordFieldCodec::CSV_LIST, reference: 'actors', removeWhenEmpty: true),
                new RecordField('wielders.tenancy', 'Tenancy', options: ['shared', 'exclusive'], removeWhenEmpty: true, displayDefault: 'shared'),
                // Playback and presentation.
                new RecordField('playback.defaultSpeed', 'Default Speed', InputControlType::FLOAT, removeWhenEmpty: true, displayDefault: '1'),
                RecordField::boolean('playback.allowSkip', 'Allow Skip'),
                RecordField::boolean('playback.loopPreview', 'Loop Preview'),
                new RecordField('transitionIn.type', 'Transition In', options: ['fadeToBlack', 'fadeFromBlack', 'none'], removeWhenEmpty: true, displayDefault: 'fadeToBlack'),
                new RecordField('transitionIn.durationMs', 'Transition In Ms', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: '0'),
                new RecordField('transitionIn.color', 'Transition In Color', removeWhenEmpty: true),
                new RecordField('transitionOut.type', 'Transition Out', options: ['fadeFromBlack', 'fadeToBlack', 'none'], removeWhenEmpty: true, displayDefault: 'fadeFromBlack'),
                new RecordField('transitionOut.durationMs', 'Transition Out Ms', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: '0'),
                new RecordField('transitionOut.color', 'Transition Out Color', removeWhenEmpty: true),
                new RecordField('effectTiming.mode', 'Effect Timing', options: ['end', 'cue', 'frame'], removeWhenEmpty: true, displayDefault: 'end'),
                new RecordField('effectTiming.cueId', 'Effect Cue', removeWhenEmpty: true),
                new RecordField('effectTiming.frame', 'Effect Frame', InputControlType::INTEGER, removeWhenEmpty: true),
                new RecordField('targetPresentation.mode', 'Target Presentation', options: ['full_screen', 'inline'], removeWhenEmpty: true, displayDefault: 'full_screen'),
                RecordField::boolean('targetPresentation.showCasterNameBanner', 'Caster Name Banner'),
                // Timeline.
                new RecordField('formatVersion', 'Timeline Format', InputControlType::INTEGER),
                new RecordField('fps', 'FPS', InputControlType::INTEGER),
                new RecordField('lengthFrames', 'Length (frames)', InputControlType::INTEGER),
                new RecordField('editor', 'Editor Metadata', codec: RecordFieldCodec::KEY_VALUES, removeWhenEmpty: true),
            ],
            labelKey: 'name',
            identityKey: 'id',
            blank: [
                'id' => 'new-summon',
                'name' => 'New Summon',
                'description' => '',
                'formatVersion' => 1,
                'fps' => 12,
                'lengthFrames' => 24,
                self::TRACKS_KEY => [],
                self::CUES_KEY => [],
            ],
            commandLists: [
                self::TRACKS_KEY => self::trackList(),
                self::CUES_KEY => self::cueList(),
            ],
        );
    }

    /**
     * Returns the cast sub-list: who a cinematic addresses.
     */
    public static function castList(): RecordSubList
    {
        return new RecordSubList(
            key: 'cast',
            prefix: 'cast',
            singular: 'cast member',
            fields: [
                new RecordField('kind', 'Kind', options: ['player', 'party_actor', 'npc', 'staged_actor']),
            ],
            blank: ['kind' => 'staged_actor', 'id' => 'actor', 'sprite' => ['@'], 'x' => 0, 'y' => 0],
            variants: [
                'player' => [new RecordField('id', 'Id')],
                'party_actor' => [RecordField::reference('id', 'Actor', 'actors')],
                'npc' => [new RecordField('id', 'NPC Id', reference: 'map_npcs')],
                'staged_actor' => self::stagedActorFields(),
            ],
            variantKey: 'kind',
        );
    }

    /**
     * Returns the fields a staged actor declares: the engine's
     * `STAGED_ACTOR_FIELDS`, each with a control.
     *
     * @return RecordField[]
     */
    public static function stagedActorFields(): array
    {
        return [
            new RecordField('id', 'Id'),
            new RecordField('sprite', 'Sprite', InputControlType::MULTILINE, codec: RecordFieldCodec::LINES, removeWhenEmpty: true),
            new RecordField('asset', 'Asset', removeWhenEmpty: true),
            new RecordField('x', 'X', InputControlType::INTEGER),
            new RecordField('y', 'Y', InputControlType::INTEGER),
            new RecordField('facing', 'Facing', options: self::FACINGS, removeWhenEmpty: true, displayDefault: 'south'),
            RecordField::boolean('visible', 'Visible'),
            RecordField::boolean('collision', 'Collision'),
            new RecordField('sprites.north', 'Sprite North', InputControlType::MULTILINE, codec: RecordFieldCodec::LINES, removeWhenEmpty: true),
            new RecordField('sprites.south', 'Sprite South', InputControlType::MULTILINE, codec: RecordFieldCodec::LINES, removeWhenEmpty: true),
            new RecordField('sprites.east', 'Sprite East', InputControlType::MULTILINE, codec: RecordFieldCodec::LINES, removeWhenEmpty: true),
            new RecordField('sprites.west', 'Sprite West', InputControlType::MULTILINE, codec: RecordFieldCodec::LINES, removeWhenEmpty: true),
        ];
    }

    /**
     * Returns the command list a cinematic script is edited as: every type
     * the interpreter understands, each with its fields, nested blocks as
     * frames, and routes, lanes and camera points as nested lists.
     */
    public static function cinematicCommandList(string $key): RecordSubList
    {
        return new RecordSubList(
            key: $key,
            prefix: 'command',
            singular: 'command',
            fields: [
                new RecordField('type', 'Type', options: CinematicCommandSchema::COMMAND_TYPES),
            ],
            blank: ['type' => 'wait', 'seconds' => 0.5],
            variants: self::cinematicCommandVariants(),
            variantKey: 'type',
            nestedLists: [
                'move_route' => RecordSchemaCatalog::routeStepList(),
                'parallel' => self::laneList(),
                'camera' => self::cameraPointList(),
            ],
            variantArms: [
                'sequence' => ['commands' => 'Sequence'],
                'choice' => ['cancel' => 'Cancel'],
            ],
        );
    }

    /**
     * Returns the finalizer command list: only the types the engine allows
     * a finalizer to hold, each in the exact shape the engine requires.
     */
    public static function finalizerCommandList(): RecordSubList
    {
        $variants = self::cinematicCommandVariants();

        return new RecordSubList(
            key: self::FINALIZER_KEY,
            prefix: 'final',
            singular: 'finalizer command',
            fields: [
                new RecordField('type', 'Type', options: CinematicCommandSchema::FINALIZER_COMMAND_TYPES),
            ],
            blank: ['type' => 'set_switch', 'name' => 'cinematic_complete', 'value' => true],
            variants: [
                'set_switch' => $variants['set_switch'],
                'set_variable' => [
                    new RecordField('name', 'Variable'),
                    new RecordField('value', 'Value'),
                ],
                'record_event' => $variants['record_event'],
                'move_player' => $variants['move_player'],
                'transfer' => $variants['transfer'],
                'camera' => [
                    new RecordField('operation', 'Operation', options: ['attach', 'reset']),
                ],
                'remove_actor' => [new RecordField('actorId', 'Actor', reference: 'cinematic_cast')],
                'clear_presentation' => [],
                'cinematic_music' => [
                    RecordField::reference('track', 'Track', 'bgm'),
                    RecordField::boolean('loop', 'Loop', removeWhenEmpty: false),
                    new RecordField('completionBehavior', 'On Completion', options: CinematicCommandSchema::MUSIC_COMPLETION_BEHAVIORS),
                    new RecordField('fadeIn', 'Fade In (s)', InputControlType::FLOAT, removeWhenEmpty: true),
                    new RecordField('fadeOut', 'Fade Out (s)', InputControlType::FLOAT, removeWhenEmpty: true),
                ],
            ],
            variantKey: 'type',
        );
    }

    /**
     * Returns the per-type fields of cinematic commands: the interpreter's
     * base vocabulary extended with the cinematic families.
     *
     * @return array<string, RecordField[]|\Closure(array<string, mixed>): RecordField[]>
     */
    public static function cinematicCommandVariants(): array
    {
        $base = RecordSchemaCatalog::eventCommandVariants();
        $subject = static fn(string $prefix, string $label, bool $screen = false): array => [
            new RecordField(
                $prefix . '.kind',
                $label . ' Kind',
                options: $screen
                    ? CinematicCommandSchema::SUBJECT_KINDS
                    : array_values(array_diff(CinematicCommandSchema::SUBJECT_KINDS, ['screen_position'])),
            ),
            new RecordField($prefix . '.id', $label . ' Id', reference: 'cinematic_subjects', removeWhenEmpty: true, allowsNone: true),
            new RecordField($prefix . '.x', $label . ' X', InputControlType::INTEGER, removeWhenEmpty: true),
            new RecordField($prefix . '.y', $label . ' Y', InputControlType::INTEGER, removeWhenEmpty: true),
        ];

        return [
            ...$base,
            'move_route' => [
                new RecordField('subject', 'Subject', options: ['player', 'npc', 'staged_actor']),
                new RecordField('npcId', 'NPC Id', reference: 'map_npcs', removeWhenEmpty: true, allowsNone: true),
                new RecordField('actorId', 'Staged Actor', reference: 'cinematic_cast', removeWhenEmpty: true, allowsNone: true),
                new RecordField('secondsPerStep', 'Seconds Per Step', InputControlType::FLOAT, removeWhenEmpty: true),
                new RecordField('speed', 'Steps Per Second', InputControlType::FLOAT, removeWhenEmpty: true),
                new RecordField('wait', 'Wait For Completion', InputControlType::BOOLEAN, ['true'], removeWhenEmpty: false),
            ],
            'transfer' => [
                RecordField::reference('map', 'Map', 'maps'),
                new RecordField('x', 'X', InputControlType::INTEGER),
                new RecordField('y', 'Y', InputControlType::INTEGER),
                new RecordField('sprite', 'Player Sprite', InputControlType::MULTILINE, codec: RecordFieldCodec::LINES, removeWhenEmpty: true),
            ],
            'sequence' => [],
            'parallel' => [],
            'common_event' => [RecordField::reference('id', 'Common Event', 'common_events')],
            'checkpoint' => [new RecordField('name', 'Checkpoint', reference: 'cinematic_checkpoints')],
            'camera' => static fn(array $entry): array => [
                new RecordField('operation', 'Operation', options: CinematicCommandSchema::CAMERA_OPERATIONS),
                ...(in_array($entry['operation'] ?? '', ['focus', 'pan', 'track'], true) ? $subject('target', 'Target') : []),
                ...(in_array($entry['operation'] ?? '', ['pan', 'track', 'shake'], true)
                    ? [new RecordField('seconds', 'Seconds', InputControlType::FLOAT)]
                    : []),
                ...(($entry['operation'] ?? '') === 'shake'
                    ? [new RecordField('magnitude', 'Magnitude', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: '1')]
                    : []),
            ],
            'stage_actor' => self::stagedActorFields(),
            'show_actor' => [new RecordField('actorId', 'Actor', reference: 'cinematic_cast')],
            'hide_actor' => [new RecordField('actorId', 'Actor', reference: 'cinematic_cast')],
            'remove_actor' => [new RecordField('actorId', 'Actor', reference: 'cinematic_cast')],
            'field_animation' => [
                RecordField::reference('animation', 'Animation', 'animations'),
                ...$subject('target', 'Target', true),
                new RecordField('secondsPerFrame', 'Seconds Per Frame', InputControlType::FLOAT, removeWhenEmpty: true, displayDefault: '0.12'),
            ],
            'title_card' => [
                new RecordField('title', 'Title', removeWhenEmpty: true),
                new RecordField('text', 'Text', InputControlType::MULTILINE, removeWhenEmpty: true),
                new RecordField('seconds', 'Seconds', InputControlType::FLOAT, removeWhenEmpty: true, displayDefault: '2.5'),
            ],
            'narration' => [
                new RecordField('title', 'Title', removeWhenEmpty: true),
                new RecordField('text', 'Text', InputControlType::MULTILINE, removeWhenEmpty: true),
                new RecordField('seconds', 'Seconds', InputControlType::FLOAT, removeWhenEmpty: true, displayDefault: '2.5'),
            ],
            'transition' => [
                new RecordField('style', 'Style', options: ['fade', 'wipe', 'none'], removeWhenEmpty: true, displayDefault: 'fade'),
                new RecordField('direction', 'Direction', options: ['out', 'in']),
                new RecordField('seconds', 'Seconds', InputControlType::FLOAT, removeWhenEmpty: true, displayDefault: '0.24'),
            ],
            'clear_presentation' => [],
            'cinematic_music' => [
                RecordField::reference('track', 'Track', 'bgm'),
                RecordField::boolean('loop', 'Loop', removeWhenEmpty: false),
                new RecordField('fadeIn', 'Fade In (s)', InputControlType::FLOAT, removeWhenEmpty: true),
                new RecordField('fadeOut', 'Fade Out (s)', InputControlType::FLOAT, removeWhenEmpty: true),
                new RecordField('completionBehavior', 'On Completion', options: CinematicCommandSchema::MUSIC_COMPLETION_BEHAVIORS, removeWhenEmpty: true, displayDefault: 'continue'),
            ],
        ];
    }

    /**
     * Returns the lane list of a parallel block: stable lane ids, each lane
     * owning its command list as a frame.
     */
    public static function laneList(): RecordSubList
    {
        return new RecordSubList(
            key: 'lanes',
            prefix: 'lane',
            singular: 'lane',
            fields: [new RecordField('id', 'Id')],
            blank: ['id' => 'lane', 'commands' => [['type' => 'wait', 'seconds' => 0.5]]],
            commandArms: ['commands' => 'Lane'],
        );
    }

    /**
     * Returns the points of a camera route: a target each, with a duration.
     */
    public static function cameraPointList(): RecordSubList
    {
        return new RecordSubList(
            key: 'points',
            prefix: 'point',
            singular: 'route point',
            fields: [
                new RecordField('kind', 'Kind', options: array_values(array_diff(CinematicCommandSchema::SUBJECT_KINDS, ['screen_position']))),
                new RecordField('id', 'Id', reference: 'cinematic_subjects', removeWhenEmpty: true, allowsNone: true),
                new RecordField('x', 'X', InputControlType::INTEGER, removeWhenEmpty: true),
                new RecordField('y', 'Y', InputControlType::INTEGER, removeWhenEmpty: true),
                new RecordField('seconds', 'Seconds', InputControlType::FLOAT),
            ],
            blank: ['kind' => 'position', 'x' => 0, 'y' => 0, 'seconds' => 0.5],
        );
    }

    /**
     * Returns the track list of a summon timeline, each track owning its
     * keyframes as a nested list.
     */
    public static function trackList(): RecordSubList
    {
        return new RecordSubList(
            key: self::TRACKS_KEY,
            prefix: 'track',
            singular: 'track',
            fields: [
                new RecordField('id', 'Id'),
                new RecordField('type', 'Type', options: ['glyph', 'text', 'flash', 'shake'], removeWhenEmpty: true, displayDefault: 'glyph'),
            ],
            blank: ['type' => 'glyph', 'id' => 'track', 'keyframes' => []],
            nestedLists: ['*' => self::keyframeList()],
        );
    }

    /**
     * Returns the keyframe list of a track: every engine keyframe field.
     */
    public static function keyframeList(): RecordSubList
    {
        return new RecordSubList(
            key: 'keyframes',
            prefix: 'keyframe',
            singular: 'keyframe',
            fields: [
                new RecordField('frame', 'Frame', InputControlType::INTEGER),
                new RecordField('duration', 'Duration', InputControlType::INTEGER),
                new RecordField('position', 'Position', codec: RecordFieldCodec::POINT, removeWhenEmpty: true),
                new RecordField('content', 'Content', InputControlType::MULTILINE, removeWhenEmpty: true),
                new RecordField('assetId', 'Asset Id', removeWhenEmpty: true),
                new RecordField('color', 'Color', removeWhenEmpty: true),
                RecordField::boolean('visible', 'Visible'),
                new RecordField('zIndex', 'Z Index', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: '0'),
                new RecordField('blendMode', 'Blend Mode', removeWhenEmpty: true),
                new RecordField('easing', 'Easing', removeWhenEmpty: true),
                new RecordField('payload', 'Payload', codec: RecordFieldCodec::KEY_VALUES, removeWhenEmpty: true),
            ],
            blank: ['frame' => 0, 'duration' => 1, 'content' => '*', 'position' => [0, 0]],
        );
    }

    /**
     * Returns the cue list of a summon timeline.
     */
    public static function cueList(): RecordSubList
    {
        return new RecordSubList(
            key: self::CUES_KEY,
            prefix: 'cue',
            singular: 'cue',
            fields: [
                new RecordField('id', 'Id'),
                new RecordField('frame', 'Frame', InputControlType::INTEGER),
                new RecordField('type', 'Type', options: ['applyEffect', 'showMessage', 'playSound', 'shake', 'flash'], removeWhenEmpty: true, displayDefault: 'showMessage'),
                new RecordField('payload', 'Payload', codec: RecordFieldCodec::KEY_VALUES, removeWhenEmpty: true),
            ],
            blank: ['id' => 'cue', 'frame' => 0, 'type' => 'showMessage', 'payload' => []],
        );
    }
}
