<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\Cutscenes\CutsceneAsset;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Animations\Timelines\EffectCadence;
use Ichiloto\Engine\Animations\Timelines\EffectImageAttachment;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImageFit;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use Ichiloto\Engine\Cutscenes\Presentation\CinematicStage;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandRegistry;
use Ichiloto\Engine\Cutscenes\Summons\SummonEffectTiming;

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

    /** The effect timeline category the Cutscenes workspace edits. */
    public const string EFFECTS_KEY = 'effects';

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

    /** The payload key a summon stage's subjects are edited under. */
    public const string STAGE_SUBJECTS_KEY = 'stage.subjects';

    /** The payload key a summon stage's camera keys are edited under. */
    public const string STAGE_CAMERA_KEY = 'stage.camera';

    /** The payload key a summon stage's cover keys are edited under. */
    public const string STAGE_COVERS_KEY = 'stage.covers';

    /** Where a summon image track is placed: on a battler, the screen, or the graphical stage. */
    public const array SUMMON_IMAGE_ANCHORS = ['target', 'caster', 'screen', 'stage'];

    /**
     * Cardinal facings a staged actor and a movement route step use.
     */
    public const array FACINGS = ['north', 'south', 'east', 'west'];

    /**
     * Returns the cinematic category: one record per asset, its metadata as
     * fields, its cast as a sub-list, and its script and finalizer as
     * command lists opened as frames.
     */
    public static function cinematics(bool $graphical = false): RecordSchema
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
            subList: self::castList($graphical),
            commandLists: [
                CutsceneAsset::COMMANDS_KEY => self::cinematicCommandList(CutsceneAsset::COMMANDS_KEY, $graphical),
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
        $fields = [
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
            new RecordField('wielders.characters', 'Wielder Characters', codec: RecordFieldCodec::CSV_LIST, reference: 'actor_ids', removeWhenEmpty: true),
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
            new RecordField('effectTiming.mode', 'Effect Timing', options: SummonEffectTiming::AUTHORING_MODES, removeWhenEmpty: true, displayDefault: SummonEffectTiming::DEFAULT_MODE),
            RecordField::reference('effectTiming.cueId', 'Effect Cue', 'summon_cues', allowsNone: true, noneLabel: '(none)'),
            new RecordField('effectTiming.frame', 'Effect Frame', InputControlType::INTEGER, removeWhenEmpty: true),
            new RecordField('targetPresentation.mode', 'Target Presentation', options: ['full_screen', 'inline'], removeWhenEmpty: true, displayDefault: 'full_screen'),
            RecordField::boolean('targetPresentation.showCasterNameBanner', 'Caster Name Banner'),
            // Timeline.
            new RecordField('formatVersion', 'Timeline Format', InputControlType::INTEGER),
            new RecordField('fps', 'FPS', InputControlType::INTEGER),
            new RecordField('lengthFrames', 'Length (frames)', InputControlType::INTEGER),
            new RecordField('restFrame', 'Rest Frame', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: 'Last frame'),
            new RecordField('editor', 'Editor Metadata', codec: RecordFieldCodec::KEY_VALUES, removeWhenEmpty: true),
        ];

        return new RecordSchema(
            key: self::SUMMONS_KEY,
            entryNoun: 'summon',
            storage: RecordStorage::MAP_OWNED,
            relativePath: 'assets/Cutscenes/Summons',
            fields: $fields,
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
                self::STAGE_SUBJECTS_KEY => self::stageSubjectList(),
                self::STAGE_CAMERA_KEY => self::stageCameraList(),
                self::STAGE_COVERS_KEY => self::stageCoverList(),
            ],
            // A sequence with a stage edits it as rows and lists of its own.
            fieldsFor: static fn(array $payload): array => is_array($payload['stage'] ?? null) ? [...$fields, ...self::stageFields()] : $fields,
            commandListsFor: static fn(array $payload): array => is_array($payload['stage'] ?? null)
                ? [self::TRACKS_KEY, self::CUES_KEY, self::STAGE_SUBJECTS_KEY, self::STAGE_CAMERA_KEY, self::STAGE_COVERS_KEY]
                : [self::TRACKS_KEY, self::CUES_KEY],
        );
    }

    /**
     * A summon's graphical stage: its own canvas in stage units, the frames
     * it is shown from and until the arena returns, and what it is drawn on.
     * Its subjects, camera keys and cover keys are lists opened as frames.
     *
     * @return list<RecordField>
     */
    public static function stageFields(): array
    {
        return [
            new RecordField('stage.canvas', 'Stage Canvas', codec: RecordFieldCodec::SIZE),
            new RecordField('stage.startFrame', 'Stage Start Frame', InputControlType::INTEGER),
            new RecordField('stage.restoreFrame', 'Stage Restore Frame', InputControlType::INTEGER),
            // black, white or #RRGGBB, as cover colours are written.
            new RecordField('stage.background', 'Stage Background', removeWhenEmpty: true, displayDefault: 'black'),
        ];
    }

    /**
     * The subjects a stage places art on: each a stable id, where it stands,
     * its registered box and the point of that box that stands there, and
     * named points within the box (a chest, the ground) that art and effects
     * attach to.
     */
    public static function stageSubjectList(): RecordSubList
    {
        return new RecordSubList(
            key: self::STAGE_SUBJECTS_KEY,
            prefix: 'subject',
            singular: 'subject',
            fields: [
                new RecordField('id', 'Id'),
                new RecordField('position', 'Position', codec: RecordFieldCodec::COORDINATES),
                new RecordField('size', 'Size', codec: RecordFieldCodec::SIZE),
                new RecordField('pivot', 'Pivot', codec: RecordFieldCodec::NORMALIZED_POINT, removeWhenEmpty: true, displayDefault: '0.5, 1 (bottom centre)'),
            ],
            blank: ['id' => 'subject', 'position' => ['x' => 0, 'y' => 0], 'size' => ['width' => 100, 'height' => 100]],
            nestedLists: ['*' => self::stageAttachmentList()],
            heading: 'Stage Subjects',
            removeWhenEmpty: true,
        );
    }

    /**
     * A stage subject's named points: each from 0 to 1 across and down its
     * registered box.
     */
    public static function stageAttachmentList(): RecordSubList
    {
        return new RecordSubList(
            key: 'attachments',
            prefix: 'attachment',
            singular: 'point',
            fields: [
                new RecordField('id', 'Id'),
                new RecordField('x', 'X', InputControlType::FLOAT),
                new RecordField('y', 'Y', InputControlType::FLOAT),
            ],
            blank: ['id' => 'point', 'x' => 0.5, 'y' => 0.5],
            removeWhenEmpty: true,
        );
    }

    /**
     * The stage camera's keys, in frame order from frame zero: the stage
     * point at the centre of the view and how near it is.
     */
    public static function stageCameraList(): RecordSubList
    {
        return new RecordSubList(
            key: self::STAGE_CAMERA_KEY,
            prefix: 'camera',
            singular: 'camera key',
            fields: [
                new RecordField('id', 'Id'),
                new RecordField('frame', 'Frame', InputControlType::INTEGER),
                new RecordField('focus', 'Focus', codec: RecordFieldCodec::COORDINATES),
                new RecordField('zoom', 'Zoom', InputControlType::FLOAT),
                new RecordField('easing', 'Easing', options: ['', ...CinematicStage::EASINGS], removeWhenEmpty: true, displayDefault: '(linear)'),
            ],
            blank: ['id' => 'key', 'frame' => 0, 'focus' => ['x' => 0, 'y' => 0], 'zoom' => 1],
            heading: 'Stage Camera',
        );
    }

    /**
     * The stage's full-screen covers, in frame order: a colour and how
     * opaque it is, from frame zero to a clear last frame.
     */
    public static function stageCoverList(): RecordSubList
    {
        return new RecordSubList(
            key: self::STAGE_COVERS_KEY,
            prefix: 'cover',
            singular: 'cover key',
            fields: [
                new RecordField('id', 'Id'),
                new RecordField('frame', 'Frame', InputControlType::INTEGER),
                new RecordField('color', 'Color'),
                new RecordField('opacity', 'Opacity', InputControlType::FLOAT),
                new RecordField('easing', 'Easing', options: ['', ...CinematicStage::EASINGS], removeWhenEmpty: true, displayDefault: '(linear)'),
            ],
            blank: ['id' => 'cover', 'frame' => 0, 'color' => 'black', 'opacity' => 1],
            heading: 'Stage Covers',
            removeWhenEmpty: true,
        );
    }

    /**
     * Returns the cast sub-list: who a cinematic addresses.
     */
    public static function castList(bool $graphical = false): RecordSubList
    {
        return new RecordSubList(
            key: 'cast',
            prefix: 'cast',
            singular: 'cast member',
            fields: [
                new RecordField('kind', 'Kind', options: ['player', 'party_actor', 'npc', 'staged_actor', ...($graphical ? ['world_object'] : [])]),
            ],
            blank: ['kind' => 'staged_actor', 'id' => 'actor', 'sprite' => ['@'], 'x' => 0, 'y' => 0],
            variants: [
                'player' => [new RecordField('id', 'Id')],
                'party_actor' => [RecordField::reference('id', 'Actor', 'actors')],
                'npc' => [new RecordField('id', 'NPC Id', reference: 'map_npcs')],
                'world_object' => [RecordField::reference('id', 'World Object', 'map_world_objects')],
                'staged_actor' => static fn(array $entry): array => self::stagedActorFields($graphical, $entry),
            ],
            variantKey: 'kind',
            nestedLists: $graphical ? ['staged_actor' => StagedActorFields::getSuppressionList(...)] : [],
            prepareEdit: $graphical ? StagedActorFields::prepareEdit(...) : null,
        );
    }

    /**
     * Returns the fields a staged actor declares: the engine's
     * `STAGED_ACTOR_FIELDS`, each with a control.
     *
     * @return RecordField[]
     */
    public static function stagedActorFields(bool $graphical = false, array $entry = []): array
    {
        $fields = [
            new RecordField('id', 'Id'),
            new RecordField('sprite', 'Sprite', InputControlType::MULTILINE, codec: RecordFieldCodec::LINES, removeWhenEmpty: true),
            new RecordField('asset', 'Asset', removeWhenEmpty: true),
            new RecordField('x', 'X', InputControlType::INTEGER),
            new RecordField('y', 'Y', InputControlType::INTEGER),
            new RecordField('facing', 'Facing', options: self::FACINGS, removeWhenEmpty: true, displayDefault: 'south'),
            RecordField::boolean('visible', 'Visible', removeWhenEmpty: false, displayDefault: 'true'),
            RecordField::boolean('collision', 'Collision'),
            new RecordField('sprites.north', 'Sprite North', InputControlType::MULTILINE, codec: RecordFieldCodec::LINES, removeWhenEmpty: true),
            new RecordField('sprites.south', 'Sprite South', InputControlType::MULTILINE, codec: RecordFieldCodec::LINES, removeWhenEmpty: true),
            new RecordField('sprites.east', 'Sprite East', InputControlType::MULTILINE, codec: RecordFieldCodec::LINES, removeWhenEmpty: true),
            new RecordField('sprites.west', 'Sprite West', InputControlType::MULTILINE, codec: RecordFieldCodec::LINES, removeWhenEmpty: true),
        ];

        if (! $graphical) {
            return $fields;
        }

        if (isset($entry['subject'])) {
            $fields = array_values(array_filter($fields, static fn(RecordField $field): bool
                => ! in_array($field->key, ['x', 'y', 'facing', 'collision'], true)));
        }

        return [...$fields, ...StagedActorFields::getFields($entry)];
    }

    /**
     * Returns the command list a cinematic script is edited as: every type
     * the interpreter understands, each with its fields, nested blocks as
     * frames, and routes, lanes and camera points as nested lists.
     */
    public static function cinematicCommandList(string $key, bool $graphical = false): RecordSubList
    {
        return new RecordSubList(
            key: $key,
            prefix: 'command',
            singular: 'command',
            fields: [
                new RecordField('type', 'Type', options: [...CinematicCommandSchema::COMMAND_TYPES, ...ScriptCommandRegistry::getCatalog()->types]),
            ],
            blank: ['type' => 'wait', 'seconds' => 0.5],
            variants: self::cinematicCommandVariants($graphical),
            variantKey: 'type',
            nestedLists: [
                'move_route' => MovementRouteFields::getPointList(...),
                'parallel' => self::laneList(),
                'camera' => static fn(array $entry): ?RecordSubList => strtolower(strval($entry['operation'] ?? '')) === 'route' ? self::cameraPointList($graphical) : null,
                ...RecordSchemaCatalog::getRegisteredCommandLists($graphical),
                ...($graphical ? ['stage_actor' => StagedActorFields::getSuppressionList(...)] : []),
            ],
            variantArms: [
                'sequence' => ['commands' => 'Sequence'],
                'choice' => ['cancel' => 'Cancel'],
            ],
            exclusiveFields: [['effect', 'animation'], ['effect', 'id'], ['effect', 'secondsPerFrame']],
            prepareEdit: static function (array $entry, string $field) use ($graphical): array {
                $entry = InnPresentationFields::prepareEdit($entry, $field);
                $entry = $graphical ? StagedActorFields::prepareEdit($entry, $field) : $entry;
                return MovementRouteFields::prepareEdit($entry, $field);
            },
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
    public static function cinematicCommandVariants(bool $graphical = false): array
    {
        $base = RecordSchemaCatalog::eventCommandVariants($graphical);
        $subject = static fn(string $prefix, string $label, bool $screen = false, array $entry = []): array => [
            new RecordField(
                $prefix . '.kind',
                $label . ' Kind',
                options: $screen
                    ? array_values(array_diff(CinematicCommandSchema::SUBJECT_KINDS, $graphical ? [] : ['world_object']))
                    : array_values(array_diff(CinematicCommandSchema::SUBJECT_KINDS, $graphical ? ['screen_position'] : ['screen_position', 'world_object'])),
            ),
            new RecordField($prefix . '.id', $label . ' Id', reference: ($entry[$prefix]['kind'] ?? null) === 'world_object' ? 'map_world_objects' : 'cinematic_subjects', removeWhenEmpty: true, allowsNone: true),
            new RecordField($prefix . '.x', $label . ' X', InputControlType::INTEGER, removeWhenEmpty: true),
            new RecordField($prefix . '.y', $label . ' Y', InputControlType::INTEGER, removeWhenEmpty: true),
        ];

        return [
            ...$base,
            'move_route' => static fn(array $entry): array => MovementRouteFields::getFields($entry, cinematic: true),
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
                ...(in_array($entry['operation'] ?? '', ['focus', 'pan', 'track'], true) ? $subject('target', 'Target', entry: $entry) : []),
                ...(in_array($entry['operation'] ?? '', ['pan', 'track', 'shake'], true)
                    ? [new RecordField('seconds', 'Seconds', InputControlType::FLOAT)]
                    : []),
                ...(($entry['operation'] ?? '') === 'shake'
                    ? [new RecordField('magnitude', 'Magnitude', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: '1')]
                    : []),
            ],
            'stage_actor' => static fn(array $entry): array => $graphical
                ? StagedActorFields::getCommandFields($entry)
                : self::stagedActorFields(),
            'show_actor' => [new RecordField('actorId', 'Actor', reference: 'cinematic_cast')],
            'hide_actor' => [new RecordField('actorId', 'Actor', reference: 'cinematic_cast')],
            'remove_actor' => [new RecordField('actorId', 'Actor', reference: 'cinematic_cast')],
            // An effect timeline owns its frame rate, so a command names
            // either an effect or a legacy animation and its pace, never
            // both; choosing one removes the other (see exclusiveFields).
            'field_animation' => static fn(array $entry): array => array_key_exists('effect', $entry)
                ? [
                    new RecordField('effect', 'Effect', reference: 'effects', removeWhenEmpty: true, allowsNone: true, displayDefault: '(legacy animation)'),
                    ...$subject('target', 'Target', true, $entry),
                ]
                : [
                    new RecordField('effect', 'Effect', reference: 'effects', removeWhenEmpty: true, allowsNone: true, displayDefault: '(legacy animation)'),
                    RecordField::reference('animation', 'Animation', 'animations'),
                    ...$subject('target', 'Target', true, $entry),
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
    public static function cameraPointList(bool $graphical = false): RecordSubList
    {
        return new RecordSubList(
            key: 'points',
            prefix: 'point',
            singular: 'route point',
            fields: [],
            getFields: static fn(array $entry): array => [
                new RecordField('kind', 'Kind', options: array_values(array_diff(CinematicCommandSchema::SUBJECT_KINDS, $graphical ? ['screen_position'] : ['screen_position', 'world_object']))),
                new RecordField('id', 'Id', reference: ($entry['kind'] ?? null) === 'world_object' ? 'map_world_objects' : 'cinematic_subjects', removeWhenEmpty: true, allowsNone: true),
                new RecordField('x', 'X', InputControlType::INTEGER, removeWhenEmpty: true),
                new RecordField('y', 'Y', InputControlType::INTEGER, removeWhenEmpty: true),
                new RecordField('seconds', 'Seconds', InputControlType::FLOAT),
            ],
            blank: ['kind' => 'position', 'x' => 0, 'y' => 0, 'seconds' => 0.5],
        );
    }

    /**
     * Returns the effect timeline category: one record per effect, the
     * sequence being edited as fields, its tracks and cues as lists opened
     * as frames. Every row is a key the Engine's effect library reads; an
     * effect with separate terminal and graphical sequences is edited one
     * sequence at a time. Battle-only keys (impact timing, facing, image
     * attachment, flips, flash and shake) are offered for every
     * effect; validation says where
     * an effect is used that refuses them.
     */
    public static function effects(bool $graphical = false): RecordSchema
    {
        $fields = [
            new RecordField('id', 'Id', isReadOnly: true),
            new RecordField('fps', 'FPS', InputControlType::INTEGER),
            new RecordField('lengthFrames', 'Length (frames)', InputControlType::INTEGER),
            new RecordField('playback', 'Playback', options: ['once', 'loop'], removeWhenEmpty: true, displayDefault: 'once'),
            new RecordField('loopFrom', 'Loop From', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: '0'),
            new RecordField('restFrame', 'Rest Frame', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: '0'),
            // Battle only: fixed plays at its FPS; battle_phase spreads its frames
            // over the battle phase it plays in, as the battle's pace sets it.
            new RecordField(
                'cadence',
                'Cadence',
                options: array_map(static fn(EffectCadence $cadence): string => $cadence->value, EffectCadence::cases()),
                removeWhenEmpty: true,
                displayDefault: EffectCadence::FIXED->value,
            ),
            // Battle only: when the command's result lands.
            new RecordField('effectTiming.mode', 'Impact Timing', options: ['end', 'frame', 'cue'], removeWhenEmpty: true, displayDefault: '(omitted)'),
            new RecordField('effectTiming.frame', 'Impact Frame', InputControlType::INTEGER, removeWhenEmpty: true),
            RecordField::reference('effectTiming.cueId', 'Impact Cue', 'effect_cues', allowsNone: true, noneLabel: '(none)'),
        ];

        return new RecordSchema(
            key: self::EFFECTS_KEY,
            entryNoun: 'effect',
            storage: RecordStorage::MAP_OWNED,
            relativePath: 'assets/' . EffectTimelineLibrary::DIRECTORY,
            fields: $fields,
            identityKey: 'id',
            blank: [
                'id' => 'new-effect',
                'fps' => 12,
                'lengthFrames' => 12,
                self::TRACKS_KEY => [[
                    'id' => 'glyph',
                    'type' => 'glyph',
                    'keyframes' => [['frame' => 0, 'duration' => 12, 'content' => '*', 'position' => ['x' => 0, 'y' => 0]]],
                ]],
                self::CUES_KEY => [],
            ],
            commandLists: [
                self::TRACKS_KEY => self::effectTrackList($graphical),
                self::CUES_KEY => self::effectCueList(),
                ...($graphical ? [
                    self::STAGE_SUBJECTS_KEY => self::stageSubjectList(),
                    self::STAGE_CAMERA_KEY => self::stageCameraList(),
                    self::STAGE_COVERS_KEY => self::stageCoverList(),
                ] : []),
            ],
            fieldsFor: static fn(array $payload): array => $graphical && is_array($payload['stage'] ?? null)
                ? [...$fields, ...self::stageFields()] : $fields,
            commandListsFor: static fn(array $payload): array => $graphical && is_array($payload['stage'] ?? null)
                ? [self::TRACKS_KEY, self::CUES_KEY, self::STAGE_SUBJECTS_KEY, self::STAGE_CAMERA_KEY, self::STAGE_COVERS_KEY]
                : [self::TRACKS_KEY, self::CUES_KEY],
        );
    }

    /**
     * Returns the track list of an effect timeline: the keys every track
     * reads, an image track's sheet and cells as its own rows, and each
     * track's keyframes as a nested list of the keys its type reads.
     */
    public static function effectTrackList(bool $graphical = false): RecordSubList
    {
        $keyframes = self::effectKeyframeList();
        $anchors = [
            new RecordField('anchor', 'Anchor', options: ['target', 'caster', 'screen'], removeWhenEmpty: true, displayDefault: 'target'),
            new RecordField('facing', 'Facing', options: ['', 'west', 'east'], removeWhenEmpty: true, displayDefault: '(undirected)'),
        ];

        return new RecordSubList(
            key: self::TRACKS_KEY,
            prefix: 'track',
            singular: 'track',
            fields: [
                new RecordField('id', 'Id'),
                new RecordField('type', 'Type', options: ['glyph', 'text', 'image', 'flash', 'shake']),
                new RecordField('presentation', 'Presentation',
                    options: ['all', ...array_map(static fn(EffectPresentation $presentation): string => $presentation->value, EffectPresentation::cases())],
                    removeWhenEmpty: true, displayDefault: 'all'),
            ],
            blank: ['id' => 'track', 'type' => 'glyph', 'keyframes' => [['frame' => 0, 'duration' => 1, 'content' => '*', 'position' => ['x' => 0, 'y' => 0]]]],
            variants: [
                'image' => static fn(array $entry): array => $graphical && self::isOnStage($entry)
                    ? self::stageImageTrackFields()
                    : [new RecordField('anchor', 'Anchor', options: $graphical ? self::SUMMON_IMAGE_ANCHORS : ['target', 'caster', 'screen'],
                        removeWhenEmpty: true, displayDefault: 'target'), $anchors[1], ...self::imageTrackFields(sharedWithField: true)],
                'glyph' => $anchors,
                'text' => $anchors,
                'flash' => $anchors,
                'shake' => $anchors,
            ],
            variantKey: 'type',
            nestedLists: [
                'image' => static fn(array $entry): RecordSubList => $graphical && self::isOnStage($entry)
                    ? self::stageImageKeyframeList() : self::effectImageKeyframeList(),
                'glyph' => $keyframes,
                'text' => $keyframes,
                'flash' => $keyframes,
                'shake' => $keyframes,
            ],
        );
    }

    /**
     * Returns the keyframes of an effect's glyph, text, flash or shake
     * track. A position is `x` and `y`, as the Engine reads it.
     */
    public static function effectKeyframeList(): RecordSubList
    {
        return new RecordSubList(
            key: 'keyframes',
            prefix: 'keyframe',
            singular: 'keyframe',
            fields: [
                new RecordField('frame', 'Frame', InputControlType::INTEGER),
                new RecordField('duration', 'Duration', InputControlType::INTEGER),
                new RecordField('position.x', 'Position X', InputControlType::INTEGER),
                new RecordField('position.y', 'Position Y', InputControlType::INTEGER),
                new RecordField('content', 'Content', InputControlType::MULTILINE, removeWhenEmpty: true),
                new RecordField('assetId', 'Asset Id', removeWhenEmpty: true),
                new RecordField('color', 'Color', removeWhenEmpty: true),
                RecordField::boolean('visible', 'Visible'),
                new RecordField('zIndex', 'Z Index', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: '0'),
                new RecordField('payload', 'Payload', codec: RecordFieldCodec::KEY_VALUES, removeWhenEmpty: true),
            ],
            blank: ['frame' => 0, 'duration' => 1, 'content' => '*', 'position' => ['x' => 0, 'y' => 0]],
        );
    }

    /**
     * Returns the keyframes of an effect's image track: which sheet frame
     * shows, where, and (battle only) whether it is flipped.
     */
    public static function effectImageKeyframeList(): RecordSubList
    {
        return new RecordSubList(
            key: 'keyframes',
            prefix: 'keyframe',
            singular: 'keyframe',
            fields: [
                new RecordField('frame', 'Frame', InputControlType::INTEGER),
                new RecordField('duration', 'Duration', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: '1'),
                new RecordField('sourceFrame', 'Sheet Frame', InputControlType::INTEGER),
                new RecordField('position.x', 'Position X', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: '0'),
                new RecordField('position.y', 'Position Y', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: '0'),
                RecordField::boolean('flipX', 'Flip Horizontally'),
                RecordField::boolean('flipY', 'Flip Vertically'),
            ],
            blank: ['frame' => 0, 'sourceFrame' => 0],
        );
    }

    /**
     * Returns the cue list of an effect timeline. The field plays only
     * `playSound`; battle plays every type.
     */
    public static function effectCueList(): RecordSubList
    {
        return new RecordSubList(
            key: self::CUES_KEY,
            prefix: 'cue',
            singular: 'cue',
            fields: [
                new RecordField('id', 'Id'),
                new RecordField('frame', 'Frame', InputControlType::INTEGER),
                new RecordField('type', 'Type', options: ['playSound', 'applyEffect', 'showMessage', 'flash', 'shake']),
                new RecordField('payload', 'Payload', codec: RecordFieldCodec::KEY_VALUES, removeWhenEmpty: true),
            ],
            blank: ['id' => 'cue', 'frame' => 0, 'type' => 'playSound', 'payload' => []],
        );
    }

    /**
     * Returns the track list of a summon timeline, each track owning its
     * keyframes as a nested list.
     */
    /**
     * An image track's own fields, the same on an effect and a summon: the
     * sheet it draws from, its depth, its normalized pivot and (in battle)
     * the battler point it is placed on. An effect may be used in both field
     * and battle: omission keeps the consumer's default, not an authored pivot.
     *
     * @return list<RecordField>
     */
    public static function imageTrackFields(bool $sharedWithField = false): array
    {
        return [
            RecordField::reference('asset', 'Image', 'png_assets'),
            new RecordField('sheet.columns', 'Sheet Columns', InputControlType::INTEGER, displayDefault: '1'),
            new RecordField('sheet.rows', 'Sheet Rows', InputControlType::INTEGER, displayDefault: '1'),
            new RecordField('cells.width', 'Cell Width', InputControlType::INTEGER, displayDefault: '1'),
            new RecordField('cells.height', 'Cell Height', InputControlType::INTEGER, displayDefault: '1'),
            // How a sheet cell fills its cells: stretched to them, or kept to its
            // own proportions inside them (the empty choice removes it: stretched).
            new RecordField('fit', 'Fit', options: ['', ...array_map(static fn(CanvasImageFit $fit): string => $fit->value, CanvasImageFit::cases())],
                removeWhenEmpty: true, displayDefault: '(' . CanvasImageFit::STRETCH->value . ')'),
            new RecordField('depth', 'Depth', options: ['front', 'behind'], removeWhenEmpty: true, displayDefault: 'front'),
            // Attachments are battle-only; a field image has only its pivot.
            new RecordField('attachment', $sharedWithField ? 'Attachment (battle only)' : 'Attachment',
                options: ['', ...array_map(static fn(EffectImageAttachment $attachment): string => $attachment->value, EffectImageAttachment::cases())],
                removeWhenEmpty: true, displayDefault: '(center)'),
            // Keep context-dependent defaults descriptive, never write one into
            // a shared effect just because it was opened in a particular preview.
            new RecordField('pivot', $sharedWithField ? 'Pivot (empty: field 0.5, 1; battle 0.5, 0.5)' : 'Pivot',
                codec: RecordFieldCodec::NORMALIZED_POINT, removeWhenEmpty: true,
                displayDefault: $sharedWithField ? '(field 0.5, 1; battle 0.5, 0.5)' : '0.5, 0.5'),
        ];
    }

    /**
     * A summon's tracks. Glyph and text tracks keep the summon's own screen
     * positions; an image track is an effect image track, placed on its
     * anchor (the target, by default) with the effect schema's fields and
     * keyframes, so a summon's art is authored as any effect's is.
     */
    public static function trackList(): RecordSubList
    {
        return new RecordSubList(
            key: self::TRACKS_KEY,
            prefix: 'track',
            singular: 'track',
            fields: [
                new RecordField('id', 'Id'),
                new RecordField('type', 'Type', options: ['glyph', 'text', 'image', 'flash', 'shake'], removeWhenEmpty: true, displayDefault: 'glyph'),
                // Which renderers draw the track: every one, or only the terminal or the graphical.
                new RecordField('presentation', 'Presentation',
                    options: ['all', ...array_map(static fn(EffectPresentation $presentation): string => $presentation->value, EffectPresentation::cases())],
                    removeWhenEmpty: true, displayDefault: 'all'),
            ],
            blank: ['type' => 'glyph', 'id' => 'track', 'keyframes' => []],
            // On a graphical stage, art is placed in stage units on a subject,
            // not on a battler; elsewhere it sits on its battle anchor.
            variants: ['image' => static fn(array $entry): array => self::isOnStage($entry) ? self::stageImageTrackFields() : [
                new RecordField('anchor', 'Anchor', options: self::SUMMON_IMAGE_ANCHORS, removeWhenEmpty: true, displayDefault: 'target'),
                // Battle only: the way the art is drawn; the battle mirrors it for a summon cast the other way.
                new RecordField('facing', 'Facing', options: ['', 'west', 'east'], removeWhenEmpty: true, displayDefault: '(undirected)'),
                ...self::imageTrackFields(),
            ]],
            variantKey: 'type',
            nestedLists: [
                'image' => static fn(array $entry): RecordSubList => self::isOnStage($entry) ? self::stageImageKeyframeList() : self::effectImageKeyframeList(),
                // A track whose type is left out is a glyph track.
                '' => self::keyframeList(),
                'glyph' => self::keyframeList(),
                'text' => self::keyframeList(),
                'flash' => self::keyframeList(),
                'shake' => self::keyframeList(),
            ],
        );
    }

    /** Whether a summon image track is placed on its sequence's graphical stage. */
    private static function isOnStage(array $track): bool
    {
        return ($track['anchor'] ?? null) === 'stage';
    }

    /**
     * A summon image track on the stage: its sheet and how a cell fills its
     * box, the subject and named point it stands on (else the stage origin),
     * an offset and box in stage units, and its depth among the stage's art.
     * Battler geometry (cells, facing, battler attachment) does not apply.
     *
     * @return list<RecordField>
     */
    public static function stageImageTrackFields(): array
    {
        $byKey = [];

        foreach (self::imageTrackFields() as $field) {
            $byKey[$field->key] = $field;
        }

        return [
            new RecordField('anchor', 'Anchor', options: self::SUMMON_IMAGE_ANCHORS, removeWhenEmpty: true, displayDefault: 'target'),
            $byKey['asset'],
            $byKey['sheet.columns'],
            $byKey['sheet.rows'],
            $byKey['fit'],
            $byKey['pivot'],
            RecordField::reference('placement.subject', 'Subject', 'stage_subjects', allowsNone: true, noneLabel: '(stage origin)'),
            RecordField::reference('placement.attachment', 'Subject Point', 'stage_attachments', allowsNone: true, noneLabel: "(the subject's pivot)"),
            new RecordField('placement.position', 'Offset', codec: RecordFieldCodec::COORDINATES, removeWhenEmpty: true, displayDefault: '0, 0'),
            new RecordField('placement.size', 'Size', codec: RecordFieldCodec::SIZE, removeWhenEmpty: true, displayDefault: "(the subject's size)"),
            new RecordField('zIndex', 'Z Index', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: '0'),
        ];
    }

    /**
     * The keyframes of an image on the stage: which sheet cell shows, its
     * offset in stage units, how opaque it is, and whether it is flipped.
     */
    public static function stageImageKeyframeList(): RecordSubList
    {
        return new RecordSubList(
            key: 'keyframes',
            prefix: 'keyframe',
            singular: 'keyframe',
            fields: [
                new RecordField('frame', 'Frame', InputControlType::INTEGER),
                new RecordField('duration', 'Duration', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: '1'),
                new RecordField('sourceFrame', 'Sheet Frame', InputControlType::INTEGER),
                new RecordField('position', 'Offset', codec: RecordFieldCodec::COORDINATES, removeWhenEmpty: true, displayDefault: '0, 0'),
                new RecordField('opacity', 'Opacity', InputControlType::FLOAT, removeWhenEmpty: true, displayDefault: '1'),
                RecordField::boolean('flipX', 'Flip Horizontally'),
                RecordField::boolean('flipY', 'Flip Vertically'),
            ],
            blank: ['frame' => 0, 'sourceFrame' => 0],
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
                new RecordField('type', 'Type', options: ['applyEffect', 'showMessage', 'playSound', 'shake', 'flash', 'restoreBattlefield'], removeWhenEmpty: true, displayDefault: 'showMessage'),
                new RecordField('payload', 'Payload', codec: RecordFieldCodec::KEY_VALUES, removeWhenEmpty: true),
            ],
            blank: ['id' => 'cue', 'frame' => 0, 'type' => 'showMessage', 'payload' => []],
        );
    }
}
