<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Events;

use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandReference;

/**
 * How the Editor picks and checks one kind of resource a registered script
 * command names.
 *
 * The Engine owns the reference kinds and adds new ones with new commands.
 * Every kind the Editor knows resolves to a picker here. A kind this Editor
 * predates resolves as {@see ScriptReferenceScope::UNSUPPORTED}: its fields
 * stay editable as plain text and validation reports each command using one,
 * so an Engine update never stops a project from opening.
 *
 * @package Ichiloto\Editor\Events
 */
final readonly class ScriptReferenceSource
{
    /** Whether the Editor has a picker and a check for this kind. */
    public bool $isSupported;

    /**
     * @param ScriptCommandReference $reference The Engine's reference kind.
     * @param ScriptReferenceScope $scope Where its values come from.
     * @param string|null $category The ReferenceCatalog category its picker lists; null for a troop member, which the
     *   owning troop lists, and for an unsupported kind.
     */
    private function __construct(
        public ScriptCommandReference $reference,
        public ScriptReferenceScope $scope,
        public ?string $category,
    ) {
        $this->isSupported = $scope !== ScriptReferenceScope::UNSUPPORTED;
    }

    /**
     * Resolves an Engine reference kind.
     *
     * Every current kind has its own arm; the default arm is for a kind an
     * Engine adds after this Editor was built, never a substitute for one.
     */
    public static function forReference(ScriptCommandReference $reference): self
    {
        return match ($reference) {
            // The item store resolves every inventory definition.
            ScriptCommandReference::ITEM => self::project($reference, 'inventory'),
            ScriptCommandReference::MUSIC => self::project($reference, 'bgm'),
            ScriptCommandReference::SOUND => self::project($reference, 'sfx'),
            ScriptCommandReference::MAP => self::project($reference, 'maps'),
            ScriptCommandReference::TROOP => self::project($reference, 'troops'),
            ScriptCommandReference::QUEST => self::project($reference, 'quests'),
            ScriptCommandReference::ACTOR => self::project($reference, 'actors'),
            ScriptCommandReference::STAGE_TIMELINE => self::project($reference, 'stage_timelines'),
            ScriptCommandReference::INCOME_SCHEDULE => self::project($reference, 'income_schedules'),
            // The owning troop's members: chosen from that troop, checked by the Engine's page validation.
            ScriptCommandReference::TROOP_MEMBER => new self($reference, ScriptReferenceScope::OWNING_TROOP, null),
            // NPC ids are map-local: the map an event belongs to, or the maps a cinematic runs on.
            ScriptCommandReference::NPC => new self($reference, ScriptReferenceScope::CURRENT_MAP, 'map_npcs'),
            // The groups the running cinematic's conversation declares.
            ScriptCommandReference::CONVERSATION_GROUP => new self($reference, ScriptReferenceScope::CURRENT_CINEMATIC,
                ReferenceCatalog::CINEMATIC_CONVERSATION_GROUPS),
            default => new self($reference, ScriptReferenceScope::UNSUPPORTED, null),
        };
    }

    /** Why a field of an unsupported kind is plain text, for its label and validation. */
    public function describeUnsupported(): string
    {
        return sprintf('This Editor has no picker or check for the Engine\'s "%s" references; the value is kept as typed. Update the Editor to choose it from a picker.',
            $this->reference->value);
    }

    private static function project(ScriptCommandReference $reference, string $category): self
    {
        return new self($reference, ScriptReferenceScope::PROJECT, $category);
    }
}
