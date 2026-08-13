<?php

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\Database\PhpDataFile;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\ProjectDirectoryContext;
use Ichiloto\Editor\ProjectQuest;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Core\WorldConditionType;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Events\Interpreter\MovementRouteRunner;

/**
 * Checks a project's content for the mistakes that are otherwise found by
 * playing the game.
 *
 * The mistakes worth catching are the quiet ones. A map that declares
 * encounters in a shape the engine cannot read never rolls a fight and never
 * says why; a door that leads to a map that does not exist crashes only when
 * a player walks through it. Both look exactly like a design decision until
 * someone goes looking.
 *
 * @package Ichiloto\Editor\Validation
 */
class ProjectValidator
{
  /**
   * The trigger classes whose data this knows how to check.
   */
  protected const string TRANSFER_TRIGGER = 'TransferPlayerTrigger';
  protected const string SCRIPT_TRIGGER = 'ScriptEventTrigger';

  /**
   * Checks a whole project.
   *
   * @param ProjectWorkspace $workspace The project to check.
   * @return Issue[] Everything wrong with it, worst first.
   */
  public function validate(ProjectWorkspace $workspace): array
  {
    $issues = [
      ...$this->checkMaps($workspace),
      ...$this->checkQuests($workspace),
      ...$this->checkTroops($workspace),
      ...$this->checkSummons($workspace),
      ...$this->checkReferences($workspace),
      ...new SaveCompatibilityValidator()->validate($workspace),
    ];

    usort(
      $issues,
      static fn(Issue $a, Issue $b): int => [$a->severity->value, $a->where] <=> [$b->severity->value, $b->where]
    );

    return $issues;
  }

  /**
   * Checks optional troop-level battle policy without requiring older data
   * sets to declare it. The runtime defaults an omitted policy to allowed.
   *
   * @return Issue[]
   */
  protected function checkTroops(ProjectWorkspace $workspace): array
  {
    $database = $workspace->getRecordDatabase('troops');

    if (! $database instanceof ProjectRecordDatabase) {
      return [];
    }

    $issues = [];

    foreach ($database->getRecords() as $record) {
      $policy = $record->get('escapePolicy');

      if ($policy === null || $policy === '') {
        continue;
      }

      if (! is_string($policy) || ! in_array($policy, ['allowed', 'forbidden'], true)) {
        $name = trim(strval($record->get('name') ?? '')) ?: '(unnamed)';
        $issues[] = Issue::error(
          'troop ' . $name,
          sprintf('It has invalid escapePolicy "%s".', is_scalar($policy) ? strval($policy) : get_debug_type($policy)),
          'Use allowed or forbidden, or omit the field to use the allowed default.',
        );
      }
    }

    return $issues;
  }

  /**
   * Checks authored summon gates, wielder policies, linked actions, and
   * actor starting assignments without imposing any game-specific names.
   *
   * @return Issue[]
   */
  protected function checkSummons(ProjectWorkspace $workspace): array
  {
    $root = $workspace->projectRoot . '/assets/Cutscenes/Summons';
    $paths = glob($root . '/*/*.data.php') ?: [];

    if ($paths === []) {
      return $this->checkActorSummonAssignments($workspace, []);
    }

    $catalog = new ReferenceCatalog($workspace);
    $known = [
      'quests' => $catalog->valuesFor('quests'),
      'inventory' => $catalog->valuesFor('inventory'),
    ];
    $actorNames = array_map(
      static fn(\Ichiloto\Editor\ProjectActor $actor): string => $actor->getName(),
      $workspace->actorDatabase->getActors(),
    );
    $skillNames = array_map(
      static fn(\Ichiloto\Editor\ProjectSkill $skill): string => $skill->getName(),
      $workspace->skillDatabase->getSkills(),
    );
    $definitions = [];
    $issues = [];

    foreach ($paths as $path) {
      $file = ProjectDirectoryContext::run(
        $workspace->projectRoot,
        static fn(): PhpDataFile => PhpDataFile::load($path),
      );
      $data = $file->payload;
      $fallbackId = basename(dirname($path));

      if (! is_array($data)) {
        $issues[] = Issue::error(
          'summon ' . $fallbackId,
          'Its definition is malformed.',
          'The summon data file must return an array.',
        );
        continue;
      }

      $idValue = $data['id'] ?? $fallbackId;
      $id = is_string($idValue) ? trim($idValue) : '';
      $where = 'summon ' . ($id !== '' ? $id : $fallbackId);
      $definitions[strtolower($id)] = $data;
      $linkedActionValue = $data['linkedActionId'] ?? '';
      $linkedAction = is_string($linkedActionValue) ? trim($linkedActionValue) : '';

      if ($id === '') {
        $issues[] = Issue::error($where, 'Its id is empty or malformed.', 'Use a stable non-empty string summon id.');
      }

      if ($linkedAction === '') {
        $issues[] = Issue::error($where, 'It has no linkedActionId.', 'Link the summon to an authored battle action.');
      } elseif (! in_array($linkedAction, $skillNames, true)) {
        $issues[] = Issue::error(
          $where,
          sprintf('It links to action "%s", which does not exist.', $linkedAction),
          'Choose an action from assets/Data/skills.php.',
        );
      }

      $issues = [
        ...$issues,
        ...$this->checkSummonAvailability($data, $where, $known),
        ...$this->checkSummonWielders($data, $where, $actorNames),
      ];
    }

    return [
      ...$issues,
      ...$this->checkActorSummonAssignments($workspace, $definitions),
    ];
  }

  /** @return Issue[] */
  protected function checkSummonAvailability(array $data, string $where, array $known): array
  {
    if (! array_key_exists('availability', $data)) {
      return [];
    }

    $availability = $data['availability'];

    if (! is_array($availability)) {
      return [Issue::error($where, 'Its availability block is malformed.', 'Use an array with a non-empty conditions list.')];
    }

    $conditions = $availability['conditions'] ?? null;

    if (! is_array($conditions) || ! array_is_list($conditions) || $conditions === []) {
      return [Issue::error(
        $where,
        'Its availability conditions are malformed or empty.',
        'Declare a non-empty list using the shared world-condition vocabulary.',
      )];
    }

    $issues = [];

    foreach ($conditions as $index => $condition) {
      if (! is_array($condition)) {
        $issues[] = Issue::error(
          $where,
          sprintf('Availability condition %d is malformed.', $index + 1),
          'Each condition must be an array.',
        );
        continue;
      }

      $type = $condition['type'] ?? null;
      $name = $condition['name'] ?? null;

      if (! is_string($type) || trim($type) === '') {
        $issues[] = Issue::error(
          $where,
          sprintf('Availability condition %d has a malformed type.', $index + 1),
          'Use a type from the shared world-condition vocabulary.',
        );
      }

      if (! is_string($name) || trim($name) === '') {
        $issues[] = Issue::error(
          $where,
          sprintf('Availability condition %d has no name.', $index + 1),
          'Name the world value this condition reads.',
        );
      }
    }

    return [...$issues, ...$this->checkConditions($conditions, $where, $known)];
  }

  /** @return Issue[] */
  protected function checkSummonWielders(array $data, string $where, array $actorNames): array
  {
    if (! array_key_exists('wielders', $data)) {
      return [];
    }

    $wielders = $data['wielders'];

    if (! is_array($wielders)) {
      return [Issue::error($where, 'Its wielder policy is malformed.', 'Use an array with a valid mode and tenancy.')];
    }

    $issues = [];
    $modeValue = $wielders['mode'] ?? 'all';
    $tenancyValue = $wielders['tenancy'] ?? 'shared';
    $mode = is_string($modeValue) ? strtolower(trim($modeValue)) : '';
    $tenancy = is_string($tenancyValue) ? strtolower(trim($tenancyValue)) : '';

    if (! in_array($mode, ['all', 'roles', 'characters'], true)) {
      $issues[] = Issue::error($where, sprintf('It uses invalid wielder mode "%s".', $mode), 'Choose all, roles, or characters.');
    }

    if (! in_array($tenancy, ['shared', 'exclusive'], true)) {
      $issues[] = Issue::error($where, sprintf('It uses invalid tenancy "%s".', $tenancy), 'Choose shared or exclusive.');
    }

    if ($mode === 'characters') {
      $characters = $wielders['characters'] ?? null;

      if (! is_array($characters) || $characters === []) {
        $issues[] = Issue::error($where, 'Its character eligibility list is empty or malformed.', 'Name at least one project actor.');
      } else {
        foreach ($characters as $character) {
          $name = is_string($character) ? trim($character) : '';

          if ($name === '' || ! in_array($name, $actorNames, true)) {
            $issues[] = Issue::error(
              $where,
              sprintf('Its eligible character "%s" does not exist.', $name !== '' ? $name : '(malformed)'),
              'Use an exact actor identity from assets/Data/Actors.',
            );
          }
        }
      }
    }

    if ($mode === 'roles') {
      $roles = $wielders['roles'] ?? null;
      $validRoles = is_array($roles) && array_is_list($roles) && $roles !== [];

      if ($validRoles) {
        foreach ($roles as $role) {
          if (! is_string($role) || trim($role) === '') {
            $validRoles = false;
            break;
          }
        }
      }

      if (! $validRoles) {
        $issues[] = Issue::error($where, 'Its role eligibility list is empty or malformed.', 'Name at least one project class or role.');
      }
    }

    return $issues;
  }

  /**
   * @param array<string, array<string, mixed>> $definitions
   * @return Issue[]
   */
  protected function checkActorSummonAssignments(ProjectWorkspace $workspace, array $definitions): array
  {
    $issues = [];
    $holders = [];

    foreach ($workspace->actorDatabase->getActors() as $actor) {
      $assignments = $actor->getSummons();
      $where = 'actor ' . $actor->getName();

      if (! is_array($assignments) || ! array_is_list($assignments)) {
        $issues[] = Issue::error($where, 'Its summon assignments are malformed.', 'Use a list of summon ids.');
        continue;
      }

      $normalizedAssignments = array_map(
        static fn(mixed $assignment): string => is_string($assignment) ? strtolower(trim($assignment)) : '',
        $assignments,
      );

      if (count($normalizedAssignments) !== count(array_unique($normalizedAssignments))) {
        $issues[] = Issue::error(
          $where,
          'Its summon assignments contain duplicate ids.',
          'List each starting summon at most once.',
        );
      }

      foreach ($assignments as $assignment) {
        $summonId = is_string($assignment) ? trim($assignment) : '';
        $normalizedSummonId = strtolower($summonId);

        if ($summonId === '' || ! isset($definitions[$normalizedSummonId])) {
          $issues[] = Issue::error(
            $where,
            sprintf('It references missing summon "%s".', $summonId !== '' ? $summonId : '(malformed)'),
            'Use an authored summon id.',
          );
          continue;
        }

        $definition = $definitions[$normalizedSummonId];
        $wielders = is_array($definition['wielders'] ?? null) ? $definition['wielders'] : null;
        $modeValue = $wielders['mode'] ?? 'all';
        $mode = is_string($modeValue) ? strtolower(trim($modeValue)) : '';
        $eligible = match ($mode) {
          'characters' => in_array($actor->getName(), (array) ($wielders['characters'] ?? []), true),
          'roles' => in_array($actor->getClassName(), (array) ($wielders['roles'] ?? []), true),
          'all' => true,
          default => false,
        };

        if ($wielders !== null && ! $eligible) {
          $issues[] = Issue::error(
            $where,
            sprintf('It is not eligible to hold summon "%s".', $summonId),
            'Change the actor assignment or the generic wielder policy.',
          );
        }

        $conditions = is_array($definition['availability']['conditions'] ?? null)
          ? $definition['availability']['conditions']
          : [];
        $hasStoryLock = array_filter(
          $conditions,
          static fn(mixed $condition): bool => is_array($condition) && ($condition['type'] ?? null) === 'event',
        ) !== [];

        if ($hasStoryLock) {
          $issues[] = Issue::error(
            $where,
            sprintf('It starts with story-locked summon "%s".', $summonId),
            'Remove the starting assignment; preserve legal assignments only in saves after unlock.',
          );
        }

        $holders[$normalizedSummonId][] = $actor->getName();
      }
    }

    foreach ($holders as $summonId => $actorNames) {
      $wielders = $definitions[$summonId]['wielders'] ?? null;

      $tenancy = is_array($wielders) && is_string($wielders['tenancy'] ?? null)
        ? strtolower(trim($wielders['tenancy']))
        : 'shared';

      if (is_array($wielders)
        && $tenancy === 'exclusive'
        && count($actorNames) > 1
      ) {
        $issues[] = Issue::error(
          'summon ' . $summonId,
          sprintf('Exclusive starting ownership is duplicated across %s.', implode(', ', $actorNames)),
          'An exclusive summon may have at most one starting holder.',
        );
      }
    }

    return $issues;
  }

  /**
   * Checks every map.
   *
   * @param ProjectWorkspace $workspace The project.
   * @return Issue[] The issues found.
   */
  protected function checkMaps(ProjectWorkspace $workspace): array
  {
    $issues = [];
    $troops = $this->labelsOf($workspace, 'troops');

    foreach ($workspace->maps as $map) {
      $issues = [
        ...$issues,
        ...$this->checkLayers($map),
        ...$this->checkEventMarkers($map),
        ...$this->checkDoors($map, $workspace->mapIds),
        ...$this->checkEncounters($map, $troops),
        ...$this->checkNpcIdentities($map),
        ...$this->checkDuplicateKeys($map),
      ];
    }

    return $issues;
  }

  /**
   * Checks that a map's layers describe the same grid.
   *
   * @param ProjectMap $map The map.
   * @return Issue[] The issues found.
   */
  protected function checkLayers(ProjectMap $map): array
  {
    $tileRows = count($map->tileLines);
    $eventRows = count($map->eventLines);

    if ($tileRows === $eventRows) {
      return [];
    }

    return [Issue::error(
      $map->mapId,
      sprintf('The tile layer has %d rows and the event layer has %d.', $tileRows, $eventRows),
      'The engine refuses to load a map whose layers disagree. Pad the shorter file.'
    )];
  }

  /**
   * Checks that placed markers and event definitions agree.
   *
   * @param ProjectMap $map The map.
   * @return Issue[] The issues found.
   */
  protected function checkEventMarkers(ProjectMap $map): array
  {
    $issues = [];
    $defined = array_keys((array) ($map->data['events'] ?? []));
    $placed = $map->getPlacedEventMarkers();

    foreach (array_diff($placed, $defined) as $marker) {
      $issues[] = Issue::error(
        $map->mapId,
        sprintf('The event layer places marker "%s", which the map does not define.', $marker),
        'The engine throws "Unmapped event markers" when the map loads. Define it, or clear the marker.'
      );
    }

    foreach (array_diff($defined, $placed) as $marker) {
      $issues[] = Issue::warning(
        $map->mapId,
        sprintf('Marker "%s" is defined but never placed on the event layer.', $marker),
        'Nothing can trigger it. Place it, or remove the definition.'
      );
    }

    foreach ($placed as $marker) {
      if (! $map->isEventMarkerSolidRectangle($marker)) {
        $issues[] = Issue::error(
          $map->mapId,
          sprintf('Marker "%s" does not occupy one solid rectangle.', $marker),
          'The engine rejects sparse, cross-shaped, and disconnected event markers when the map loads.'
        );
      }
    }

    return $issues;
  }

  /**
   * Checks that doors lead somewhere.
   *
   * @param ProjectMap $map The map.
   * @param string[] $mapIds Every map in the project.
   * @return Issue[] The issues found.
   */
  protected function checkDoors(ProjectMap $map, array $mapIds): array
  {
    $issues = [];

    foreach ((array) ($map->data['events'] ?? []) as $marker => $event) {
      if (! is_array($event)) {
        continue;
      }

      $class = strval($event['class'] ?? '');
      $destination = trim(strval($event['data']['destinationMap'] ?? ''));

      if ($destination === '') {
        if (str_contains($class, self::TRANSFER_TRIGGER)) {
          $issues[] = Issue::error(
            $map->mapId,
            sprintf('Marker "%s" transfers the player but names no destinationMap.', $marker),
            'Add data.destinationMap.'
          );
        }

        continue;
      }

      if (! in_array($destination, $mapIds, true)) {
        $issues[] = Issue::error(
          $map->mapId,
          sprintf('Marker "%s" leads to "%s", which is not a map in this project.', $marker, $destination),
          'Walking through this door crashes the game. Check the map id.'
        );
      }
    }

    return $issues;
  }

  /**
   * Checks that a map's encounters can actually happen.
   *
   * @param ProjectMap $map The map.
   * @param string[] $troops The troop names the project defines.
   * @return Issue[] The issues found.
   */
  protected function checkEncounters(ProjectMap $map, array $troops): array
  {
    $encounters = $map->data['encounters'] ?? null;

    if ($encounters === null) {
      return [];
    }

    if (! is_array($encounters) || $encounters === []) {
      return [Issue::warning(
        $map->mapId,
        'The map declares encounters but the list is empty.',
        'Remove the key, or fill it in.'
      )];
    }

    $declared = (array) ($encounters['troops'] ?? []);

    if ($declared === []) {
      return [Issue::error(
        $map->mapId,
        'The map declares encounters but names no troops the engine can read.',
        "Expected ['troops' => ['Troop Name' => weight, ...], 'rate' => steps]."
      )];
    }

    $issues = [];

    foreach ($declared as $troop => $weight) {
      if ($troops !== [] && ! in_array(strval($troop), $troops, true)) {
        $issues[] = Issue::error(
          $map->mapId,
          sprintf('Encounters name the troop "%s", which the project does not define.', $troop),
          'Check assets/Data/troops.php.'
        );
      }

      if (! is_numeric($weight) || intval($weight) < 1) {
        $issues[] = Issue::warning(
          $map->mapId,
          sprintf('The troop "%s" has a weight of %s, so it can never be picked.', $troop, var_export($weight, true)),
          'Weights are whole numbers of 1 or more.'
        );
      }
    }

    if (intval($encounters['rate'] ?? 0) < 1) {
      $issues[] = Issue::warning(
        $map->mapId,
        'Encounters have no rate, so the engine uses its default of 15 steps.',
        "Set 'rate' to the average number of steps between fights."
      );
    }

    return $issues;
  }

  /**
   * Checks stable NPC identities used by authored movement routes.
   *
   * NPC ids remain optional for existing definitions, but every non-empty id
   * must be unique within its map.
   *
   * @return Issue[] The issues found.
   */
  protected function checkNpcIdentities(ProjectMap $map): array
  {
    $seen = [];
    $issues = [];

    foreach ((array) ($map->data['npcs'] ?? []) as $index => $npc) {
      if (! is_array($npc)) {
        continue;
      }

      $id = trim(strval($npc['id'] ?? ''));

      if ($id === '') {
        continue;
      }

      if (isset($seen[$id])) {
        $issues[] = Issue::error(
          $map->mapId,
          sprintf('NPC id "%s" is used more than once (entries %d and %d).', $id, $seen[$id] + 1, $index + 1),
          'NPC route targets must be unique within a map.'
        );
        continue;
      }

      $seen[$id] = $index;
    }

    return $issues;
  }

  /**
   * Checks for a key written twice in a map's data file.
   *
   * PHP keeps the last of a repeated key and says nothing, so an author can
   * write a block, write it again differently further down, and have the first
   * one silently discarded. That is exactly how a project ends up with
   * encounters that never fire.
   *
   * @param ProjectMap $map The map.
   * @return Issue[] The issues found.
   */
  protected function checkDuplicateKeys(ProjectMap $map): array
  {
    if (! is_file($map->dataPath)) {
      return [];
    }

    $source = file_get_contents($map->dataPath);

    if ($source === false) {
      return [];
    }

    $issues = [];
    $depth = 0;
    $topLevel = [];
    $tokens = token_get_all($source);

    foreach ($tokens as $index => $token) {
      if (! is_array($token)) {
        $depth += match ($token) {
          '[' => 1,
          ']' => -1,
          default => 0,
        };

        continue;
      }

      if ($token[0] === T_ARRAY) {
        continue;
      }

      // Only the outermost array of the returned map data is checked: the
      // repeated keys that bite are the top-level blocks. A string is a key
      // only when an arrow follows it; otherwise it is a value that happens to
      // read like one.
      if ($depth === 1 && $token[0] === T_CONSTANT_ENCAPSED_STRING && $this->isFollowedByArrow($tokens, $index)) {
        $key = trim($token[1], "'\"");
        $topLevel[$key][] = $token[2];
      }
    }

    foreach ($topLevel as $key => $lines) {
      if (count($lines) > 1) {
        $issues[] = Issue::error(
          $map->mapId,
          sprintf('"%s" is declared %d times (lines %s).', $key, count($lines), implode(', ', $lines)),
          'PHP keeps the last one and discards the rest without a word.'
        );
      }
    }

    return $issues;
  }

  /**
   * Determines whether a token is followed by a double arrow.
   *
   * @param array<int, array|string> $tokens The file's tokens.
   * @param int $index The token to look after.
   * @return bool True when the next meaningful token is `=>`.
   */
  protected function isFollowedByArrow(array $tokens, int $index): bool
  {
    for ($next = $index + 1; $next < count($tokens); $next++) {
      $token = $tokens[$next];

      if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
        continue;
      }

      return is_array($token) && $token[0] === T_DOUBLE_ARROW;
    }

    return false;
  }

  /**
   * Checks that quests ask for things that exist.
   *
   * @param ProjectWorkspace $workspace The project.
   * @return Issue[] The issues found.
   */
  protected function checkQuests(ProjectWorkspace $workspace): array
  {
    $quests = $workspace->questDatabase->getQuests();

    if ($quests === []) {
      return [];
    }

    $issues = [];
    $items = $this->labelsOf($workspace, 'items');
    $foes = [...$this->labelsOf($workspace, 'troops'), ...$this->labelsOf($workspace, 'enemies')];
    $questIds = array_map(static fn(ProjectQuest $quest): string => $quest->getId(), $quests);
    $known = ['quests' => $questIds, 'inventory' => $items];

    foreach ($quests as $quest) {
      $questId = $quest->getId();
      $where = sprintf('quest %s', $questId !== '' ? $questId : '(unnamed)');

      if ($questId === '') {
        $issues[] = Issue::error($where, 'The quest has no id.', 'Every quest needs one; it is what triggers grant.');
      }

      $issues = [
        ...$issues,
        ...$this->checkObjectives($quest, $where, $workspace->mapIds, $items, $foes),
        ...$this->checkConditions($quest->getPrerequisites(), $where, $known, true),
      ];
    }

    return $issues;
  }

  /**
   * Checks one quest's objectives.
   *
   * @param ProjectQuest $quest The quest.
   * @param string $where What to call it in a message.
   * @param string[] $mapIds Every map in the project.
   * @param string[] $items Every item.
   * @param string[] $foes Every troop and enemy.
   * @return Issue[] The issues found.
   */
  protected function checkObjectives(ProjectQuest $quest, string $where, array $mapIds, array $items, array $foes): array
  {
    $objectives = $quest->getObjectives();
    $issues = [];

    if ($objectives === []) {
      return [Issue::error($where, 'The quest has no objectives.', 'It could never be completed.')];
    }

    foreach ($objectives as $objective) {
      $type = strval($objective['type'] ?? '');
      $target = trim(strval($objective['target'] ?? ''));

      if ($target === '') {
        $issues[] = Issue::error($where, sprintf('A "%s" objective names no target.', $type), 'It can never advance.');
        continue;
      }

      $missing = match ($type) {
        'reach_map' => ! in_array($target, $mapIds, true) ? 'map' : null,
        'collect' => $items !== [] && ! in_array($target, $items, true) ? 'item' : null,
        'defeat' => $foes !== [] && ! in_array($target, $foes, true) ? 'enemy or troop' : null,
        default => null,
      };

      if ($missing !== null) {
        $issues[] = Issue::error(
          $where,
          sprintf('The objective wants the %s "%s", which the project does not define.', $missing, $target),
          'The objective can never be satisfied.'
        );
      }
    }

    return $issues;
  }

  /**
   * Returns the names a database category defines.
   *
   * @param ProjectWorkspace $workspace The project.
   * @param string $category The category key.
   * @return string[] The names.
   */
  protected function labelsOf(ProjectWorkspace $workspace, string $category): array
  {
    $database = $workspace->getRecordDatabase($category);

    if ($database === null) {
      return [];
    }

    $names = [];

    foreach ($database->getRecords() as $record) {
      $name = trim(strval($record->get('name') ?? ''));

      if ($name !== '') {
        $names[] = $name;
      }
    }

    return $names;
  }

  /**
   * Checks everything that names another resource.
   *
   * The editor's pickers keep new references honest, but a project also
   * holds hand-written condition lines, scripts authored in an editor of the
   * author's choosing, and data that predates the picker. A name that
   * matches nothing fails silently at runtime -- a skit that never plays, a
   * command that gives no item -- so it is worth saying here.
   *
   * @param ProjectWorkspace $workspace The project.
   * @return Issue[] The issues found.
   */
  protected function checkReferences(ProjectWorkspace $workspace): array
  {
    $catalog = new ReferenceCatalog($workspace);
    $known = [
      'quests' => $catalog->valuesFor('quests'),
      'maps' => $catalog->valuesFor('maps'),
      'troops' => $catalog->valuesFor('troops'),
      'inventory' => $catalog->valuesFor('inventory'),
      'bgm' => $catalog->valuesFor('bgm'),
      'sfx' => $catalog->valuesFor('sfx'),
      'common_events' => $catalog->valuesFor('common_events'),
    ];

    return [
      ...$this->checkSkitReferences($workspace, $known),
      ...$this->checkScriptReferences($workspace, $known),
      ...$this->checkMapReferences($workspace, $known),
      ...$this->checkAchievementReferences($workspace, $known),
    ];
  }

  /**
   * Checks manually authored achievement condition records.
   *
   * Achievements are not an editor Database category, but their conditions
   * use the same runtime evaluator and must receive the same validation.
   *
   * @param ProjectWorkspace $workspace The project.
   * @param array<string, string[]> $known What the project defines.
   * @return Issue[] The issues found.
   */
  protected function checkAchievementReferences(ProjectWorkspace $workspace, array $known): array
  {
    $path = $workspace->projectRoot . '/assets/Data/achievements.php';
    $file = ProjectDirectoryContext::run(
      $workspace->projectRoot,
      static fn(): PhpDataFile => PhpDataFile::load($path),
    );

    if (! is_array($file->payload)) {
      return [];
    }

    $issues = [];

    foreach ($file->payload as $achievement) {
      if (! is_array($achievement)) {
        continue;
      }

      $where = sprintf('achievement %s', strval($achievement['id'] ?? '(unnamed)'));
      $issues = [
        ...$issues,
        ...$this->checkConditions((array) ($achievement['conditions'] ?? []), $where, $known),
      ];
    }

    return $issues;
  }

  /**
   * Checks the skits' map and conditions.
   *
   * @param ProjectWorkspace $workspace The project.
   * @param array<string, string[]> $known What the project defines.
   * @return Issue[] The issues found.
   */
  protected function checkSkitReferences(ProjectWorkspace $workspace, array $known): array
  {
    $database = $workspace->getRecordDatabase('skits');

    if (! $database instanceof ProjectRecordDatabase) {
      return [];
    }

    $issues = [];

    foreach ($database->getRecords() as $record) {
      $skit = (array) $record->toArray();
      $where = sprintf('skit %s', strval($skit['id'] ?? '(unnamed)'));
      $map = trim(strval($skit['where'] ?? ''));

      if ($map !== '' && ! in_array($map, $known['maps'], true)) {
        $issues[] = Issue::error(
          $where,
          sprintf('It plays on the map "%s", which does not exist.', $map),
          'The skit can never trigger. Check the map id.'
        );
      }

      $issues = [...$issues, ...$this->checkConditions((array) ($skit['conditions'] ?? []), $where, $known)];
    }

    return $issues;
  }

  /**
   * Checks the event scripts' commands.
   *
   * @param ProjectWorkspace $workspace The project.
   * @param array<string, string[]> $known What the project defines.
   * @return Issue[] The issues found.
   */
  protected function checkScriptReferences(ProjectWorkspace $workspace, array $known): array
  {
    $database = $workspace->getRecordDatabase('common_events');

    if (! $database instanceof ProjectRecordDatabase) {
      return [];
    }

    $issues = [];

    foreach ($database->getRecords() as $record) {
      $script = (array) $record->toArray();
      $where = sprintf('event script %s', strval($script['__scriptId'] ?? '(unnamed)'));

      $issues = [...$issues, ...$this->checkCommands((array) ($script['commands'] ?? []), $where, $known)];
    }

    return $issues;
  }

  /**
   * Checks a command list, following the arms a command branches into.
   *
   * @param array<int, mixed> $commands The commands.
   * @param string $where Where they live.
   * @param array<string, string[]> $known What the project defines.
   * @return Issue[] The issues found.
   */
  protected function checkCommands(
    array $commands,
    string $where,
    array $known,
    ?array $npcIds = null,
    ?string $mapId = null,
    array $npcIdsByMap = [],
  ): array
  {
    $issues = [];

    foreach ($commands as $command) {
      if (! is_array($command)) {
        continue;
      }

      $type = strval($command['type'] ?? '');

      if (! in_array($type, EventInterpreter::COMMAND_TYPES, true)) {
        $issues[] = Issue::error(
          $where,
          sprintf('It uses the unknown event command type "%s".', $type !== '' ? $type : '(empty)'),
          'Choose a command supported by the runtime EventInterpreter.'
        );
      }

      $named = match ($type) {
        'give_item' => ['inventory', 'item', 'item'],
        'play_music' => ['bgm', 'music', 'track'],
        'play_sound' => ['sfx', 'sound', 'sound'],
        'accept_quest' => ['quests', 'id', 'quest'],
        'transfer' => ['maps', 'map', 'map'],
        'start_battle' => ['troops', 'troop', 'troop'],
        default => null,
      };

      if (is_array($named)) {
        [$category, $key, $noun] = $named;
        $issues = [
          ...$issues,
          ...$this->checkReference(strval($command[$key] ?? ''), $category, $noun, $where, $known),
        ];
      }

      if ($type === 'move_route') {
        $issues = [
          ...$issues,
          ...$this->checkMovementRoute($command, $where, $npcIds, $mapId),
        ];
      }

      if ($type === 'start_battle') {
        $issues = [
          ...$issues,
          ...$this->checkBattleContinuation($command, $where),
        ];
      }

      $issues = [...$issues, ...$this->checkConditions((array) ($command['conditions'] ?? []), $where, $known)];

      // A choice hides its commands one level down, per option.
      foreach ((array) ($command['options'] ?? []) as $option) {
        if (is_array($option)) {
          $issues = [...$issues, ...$this->checkCommands(
            (array) ($option['then'] ?? []),
            $where,
            $known,
            $npcIds,
            $mapId,
            $npcIdsByMap,
          )];
        }
      }

      foreach (['then', 'else'] as $arm) {
        $issues = [...$issues, ...$this->checkCommands(
          (array) ($command[$arm] ?? []),
          $where,
          $known,
          $npcIds,
          $mapId,
          $npcIdsByMap,
        )];
      }

      if ($type === 'transfer') {
        $destination = trim(strval($command['map'] ?? ''));

        if ($destination !== '' && array_key_exists($destination, $npcIdsByMap)) {
          $mapId = $destination;
          $npcIds = $npcIdsByMap[$destination];
        } else {
          $mapId = $destination !== '' ? $destination : $mapId;
          $npcIds = null;
        }
      }
    }

    return $issues;
  }

  /**
   * Checks one deterministic, awaited movement route.
   *
   * @param array<string, mixed> $command The route command.
   * @param string[]|null $npcIds Current-map NPC ids, or null without map context.
   * @return Issue[] The issues found.
   */
  protected function checkMovementRoute(array $command, string $where, ?array $npcIds, ?string $mapId): array
  {
    $issues = [];
    $subject = strtolower(trim(strval($command['subject'] ?? 'player')));

    if (! in_array($subject, ['player', 'npc'], true)) {
      $issues[] = Issue::error(
        $where,
        sprintf('A movement route uses unsupported subject "%s".', $subject !== '' ? $subject : '(empty)'),
        'Choose player or npc.'
      );
    }

    $npcId = trim(strval($command['npcId'] ?? ''));

    if ($subject === 'npc' && $npcId === '') {
      $issues[] = Issue::error($where, 'An NPC movement route has no npcId.', 'Choose a stable current-map NPC id.');
    } elseif ($subject === 'npc' && $npcIds !== null && ! in_array($npcId, $npcIds, true)) {
      $issues[] = Issue::error(
        $where,
        sprintf('The route targets NPC id "%s", which is not on map "%s".', $npcId, $mapId ?? '(unknown)'),
        'Add that stable id to the map NPC or choose an id that exists.'
      );
    }

    $wait = $command['wait'] ?? true;

    if (! is_bool($wait) || ! $wait) {
      $issues[] = Issue::error(
        $where,
        'A movement route has an invalid wait value.',
        'Parallel routes are not resumable in this extension; routes must be awaited.'
      );
    }

    foreach (['secondsPerStep' => false, 'speed' => true] as $key => $mustBePositive) {
      if (! array_key_exists($key, $command)) {
        continue;
      }

      $value = $command[$key];
      $valid = is_numeric($value) && ($mustBePositive ? floatval($value) > 0.0 : floatval($value) >= 0.0);

      if (! $valid) {
        $issues[] = Issue::error(
          $where,
          sprintf('Movement-route %s must be %s.', $key, $mustBePositive ? 'greater than zero' : 'zero or greater'),
          'Use a valid numeric route timing value.'
        );
      }
    }

    $steps = $command['steps'] ?? null;

    if (! is_array($steps) || $steps === []) {
      $issues[] = Issue::error($where, 'A movement route has no steps.', 'Add at least one structured route step.');

      return $issues;
    }

    foreach ($steps as $index => $step) {
      if (! is_array($step)) {
        $issues[] = Issue::error($where, sprintf('Movement-route step %d is malformed.', $index + 1), 'Each step must be an array.');
        continue;
      }

      $direction = strtolower(trim(strval($step['direction'] ?? '')));

      if (! in_array($direction, MovementRouteRunner::DIRECTIONS, true)) {
        $issues[] = Issue::error(
          $where,
          sprintf('Movement-route step %d uses unsupported direction "%s".', $index + 1, $direction !== '' ? $direction : '(empty)'),
          'Choose up, down, left, or right.'
        );
      }

      $count = $step['count'] ?? 1;

      if (! is_numeric($count) || intval($count) < 0 || floatval($count) !== floatval(intval($count))) {
        $issues[] = Issue::error(
          $where,
          sprintf('Movement-route step %d has an invalid count.', $index + 1),
          'Step counts must be whole numbers of zero or more.'
        );
      }

      if (array_key_exists('seconds', $step) && (! is_numeric($step['seconds']) || floatval($step['seconds']) < 0.0)) {
        $issues[] = Issue::error(
          $where,
          sprintf('Movement-route step %d has invalid timing.', $index + 1),
          'Per-step seconds must be zero or greater.'
        );
      }

      if (array_key_exists('faceOnly', $step) && ! is_bool($step['faceOnly'])) {
        $issues[] = Issue::error(
          $where,
          sprintf('Movement-route step %d has an invalid faceOnly value.', $index + 1),
          'Facing-only must be a boolean value.'
        );
      }
    }

    return $issues;
  }

  /** @return Issue[] Invalid optional start_battle continuation fields. */
  protected function checkBattleContinuation(array $command, string $where): array
  {
    $issues = [];

    if (trim(strval($command['troop'] ?? '')) === '') {
      $issues[] = Issue::error($where, 'A start_battle command names no troop.', 'Choose a configured troop.');
    }

    if (
      array_key_exists('resultVariable', $command)
      && (! is_string($command['resultVariable']) || trim($command['resultVariable']) === '')
    ) {
      $issues[] = Issue::error(
        $where,
        'A start_battle resultVariable is empty or malformed.',
        'Remove the optional field or name the world variable that receives the result.'
      );
    }

    $defeatPolicy = strval($command['defeatPolicy'] ?? 'game_over');

    if (! in_array($defeatPolicy, ['game_over', 'continue'], true)) {
      $issues[] = Issue::error(
        $where,
        sprintf('A start_battle command uses invalid defeatPolicy "%s".', $defeatPolicy),
        'Choose game_over or continue.'
      );
    }

    if (array_key_exists('escapePolicy', $command)) {
      $escapePolicy = $command['escapePolicy'];

      if (! is_string($escapePolicy) || ! in_array(strtolower(trim($escapePolicy)), ['allowed', 'forbidden'], true)) {
        $issues[] = Issue::error(
          $where,
          sprintf('A start_battle command uses invalid escapePolicy "%s".', is_scalar($escapePolicy) ? strval($escapePolicy) : get_debug_type($escapePolicy)),
          'Choose allowed or forbidden, or remove the override to inherit the troop and default policy.'
        );
      }
    }

    return $issues;
  }

  /**
   * Checks the maps' audio, conditions and shop stock.
   *
   * @param ProjectWorkspace $workspace The project.
   * @param array<string, string[]> $known What the project defines.
   * @return Issue[] The issues found.
   */
  protected function checkMapReferences(ProjectWorkspace $workspace, array $known): array
  {
    $issues = [];
    $npcIdsByMap = $this->npcIdsByMap($workspace);
    $eventScripts = $this->eventScriptsById($workspace);

    foreach ($workspace->maps as $map) {
      $issues = [
        ...$issues,
        ...$this->checkReference(strval($map->data['bgm'] ?? ''), 'bgm', 'track', $map->mapId, $known),
      ];

      foreach ((array) ($map->data['events'] ?? []) as $marker => $definition) {
        if (! is_array($definition)) {
          continue;
        }

        $where = sprintf('%s event %s', $map->mapId, strval($marker));
        $data = (array) ($definition['data'] ?? []);
        $class = strval($definition['class'] ?? '');
        $currentNpcIds = $npcIdsByMap[$map->mapId] ?? [];

        $issues = [
          ...$issues,
          ...$this->checkConditions((array) ($definition['conditions'] ?? []), $where, $known),
          ...$this->checkDialogueVariants((array) ($data['dialogue'] ?? []), $where, $known),
          ...$this->checkCommands(
            (array) ($data['script'] ?? []),
            $where,
            $known,
            $currentNpcIds,
            $map->mapId,
            $npcIdsByMap,
          ),
          ...$this->checkReference(strval($data['bgm'] ?? ''), 'bgm', 'track', $where, $known),
          ...$this->checkReference(strval($data['sfx'] ?? ''), 'sfx', 'sound', $where, $known),
        ];

        if (str_contains($class, self::SCRIPT_TRIGGER)) {
          $issues = [
            ...$issues,
            ...$this->checkScriptTrigger(
              $definition,
              $where,
              $known,
              $eventScripts,
              $currentNpcIds,
              $map->mapId,
              $npcIdsByMap,
            ),
          ];
        }

        foreach ((array) ($data['items'] ?? []) as $stock) {
          if (is_array($stock)) {
            $issues = [
              ...$issues,
              ...$this->checkReference(strval($stock['item'] ?? ''), 'inventory', 'item', $where, $known),
            ];
          }
        }
      }

      foreach ((array) ($map->data['npcs'] ?? []) as $npc) {
        if (! is_array($npc)) {
          continue;
        }

        $where = sprintf('%s NPC %s', $map->mapId, strval($npc['name'] ?? '(unnamed)'));
        $issues = [
          ...$issues,
          ...$this->checkConditions((array) ($npc['conditions'] ?? []), $where, $known),
          ...$this->checkDialogueVariants((array) ($npc['dialogue'] ?? []), $where, $known),
          ...$this->checkCommands(
            (array) ($npc['script'] ?? []),
            $where,
            $known,
            $npcIdsByMap[$map->mapId] ?? [],
            $map->mapId,
            $npcIdsByMap,
          ),
        ];
      }
    }

    return $issues;
  }

  /**
   * Checks ScriptEventTrigger's runtime-recognized fields and script picker.
   *
   * @param array<string, mixed> $definition The map event definition.
   * @param array<string, string[]> $known Known project references.
   * @param array<string, array<int, mixed>> $eventScripts Script payloads by id.
   * @param string[] $npcIds Current-map NPC ids.
   * @param array<string, string[]> $npcIdsByMap NPC ids by map.
   * @return Issue[] The issues found.
   */
  protected function checkScriptTrigger(
    array $definition,
    string $where,
    array $known,
    array $eventScripts,
    array $npcIds,
    string $mapId,
    array $npcIdsByMap,
  ): array {
    $issues = [];
    $allowedRootFields = ['class', 'data', 'conditions', 'sets', 'whenBlocked'];

    foreach (array_diff(array_keys($definition), $allowedRootFields) as $field) {
      $issues[] = Issue::error(
        $where,
        sprintf('ScriptEventTrigger uses unsupported root field "%s".', $field),
        'Use class, data, conditions, sets, or whenBlocked.'
      );
    }

    $data = $definition['data'] ?? null;

    if (! is_array($data)) {
      return [...$issues, Issue::error($where, 'ScriptEventTrigger data is malformed.', 'Store its settings in a data array.')];
    }

    $allowedDataFields = ['scriptId', 'script', 'mode', 'reusable'];

    foreach (array_diff(array_keys($data), $allowedDataFields) as $field) {
      $issues[] = Issue::error(
        $where,
        sprintf('ScriptEventTrigger uses unsupported data field "%s".', $field),
        'Use scriptId, script, mode, or reusable.'
      );
    }

    $scriptId = trim(strval($data['scriptId'] ?? ''));
    $inlineScript = $data['script'] ?? [];

    if ($scriptId === '' && (! is_array($inlineScript) || $inlineScript === [])) {
      $issues[] = Issue::error(
        $where,
        'ScriptEventTrigger names no scriptId and has no inline script.',
        'Choose an event script from the script-id picker.'
      );
    }

    if ($scriptId !== '') {
      $issues = [
        ...$issues,
        ...$this->checkReference($scriptId, 'common_events', 'event script', $where, $known),
      ];

      if (isset($eventScripts[$scriptId])) {
        $issues = [
          ...$issues,
          ...$this->checkCommands(
            $eventScripts[$scriptId],
            $where,
            $known,
            $npcIds,
            $mapId,
            $npcIdsByMap,
          ),
        ];
      }
    }

    $mode = strval($data['mode'] ?? 'action');

    if (! in_array($mode, ['action', 'auto'], true)) {
      $issues[] = Issue::error(
        $where,
        sprintf('ScriptEventTrigger uses unsupported mode "%s".', $mode),
        'Choose action or auto.'
      );
    }

    if (array_key_exists('reusable', $data) && ! is_bool($data['reusable'])) {
      $issues[] = Issue::error($where, 'ScriptEventTrigger reusable must be boolean.', 'Choose true or false.');
    }

    $issues = [
      ...$issues,
      ...$this->checkStateWrites((array) ($definition['sets'] ?? []), $where),
    ];

    return $issues;
  }

  /** @return array<string, string[]> Stable non-empty NPC ids by map id. */
  protected function npcIdsByMap(ProjectWorkspace $workspace): array
  {
    $idsByMap = [];

    foreach ($workspace->maps as $map) {
      $idsByMap[$map->mapId] = [];

      foreach ((array) ($map->data['npcs'] ?? []) as $npc) {
        if (! is_array($npc)) {
          continue;
        }

        $id = trim(strval($npc['id'] ?? ''));

        if ($id !== '') {
          $idsByMap[$map->mapId][] = $id;
        }
      }
    }

    return $idsByMap;
  }

  /** @return array<string, array<int, mixed>> Event command payloads by script id. */
  protected function eventScriptsById(ProjectWorkspace $workspace): array
  {
    $database = $workspace->getRecordDatabase('common_events');
    $scripts = [];

    if (! $database instanceof ProjectRecordDatabase) {
      return $scripts;
    }

    foreach ($database->getRecords() as $record) {
      $payload = (array) $record->toArray();
      $id = trim(strval($payload['__scriptId'] ?? ''));

      if ($id !== '') {
        $scripts[$id] = (array) ($payload['commands'] ?? []);
      }
    }

    return $scripts;
  }

  /** @return Issue[] Invalid completion writes. */
  protected function checkStateWrites(array $writes, string $where): array
  {
    $issues = [];

    foreach ($writes as $write) {
      if (! is_array($write)) {
        $issues[] = Issue::error($where, 'A completion write is malformed.', 'Each write must be a structured array.');
        continue;
      }

      $type = strval($write['type'] ?? '');

      if (! in_array($type, ['switch', 'variable', 'event', 'quest'], true)) {
        $issues[] = Issue::error(
          $where,
          sprintf('A completion write uses unsupported type "%s".', $type !== '' ? $type : '(empty)'),
          'Choose switch, variable, event, or quest.'
        );
      }

      if (trim(strval($write['name'] ?? '')) === '') {
        $issues[] = Issue::error($where, 'A completion write names no state.', 'Name the switch, variable, event, or quest.');
      }
    }

    return $issues;
  }

  /**
   * Checks conditional dialogue variants and their event-command scripts.
   *
   * Plain dialogue pages contain neither key and therefore pass through.
   *
   * @param array<int, mixed> $dialogue The authored dialogue entry.
   * @param string $where Where the dialogue lives.
   * @param array<string, string[]> $known What the project defines.
   * @return Issue[] The issues found.
   */
  protected function checkDialogueVariants(array $dialogue, string $where, array $known): array
  {
    $issues = [];

    foreach ($dialogue as $variant) {
      if (! is_array($variant)) {
        continue;
      }

      $issues = [
        ...$issues,
        ...$this->checkConditions((array) ($variant['conditions'] ?? []), $where, $known),
        ...$this->checkCommands((array) ($variant['script'] ?? []), $where, $known),
      ];
    }

    return $issues;
  }

  /**
   * Checks the conditions the engine's evaluator will read.
   *
   * Only the types naming a record are checked: a switch, story event or
   * variable is a name the author invents, and nothing in the project
   * declares it up front.
   *
   * @param array<int, mixed> $conditions The conditions.
   * @param string $where Where they live.
   * @param array<string, string[]> $known What the project defines.
   * @param bool $questPrerequisites Whether quest references are acceptance prerequisites.
   * @return Issue[] The issues found.
   */
  protected function checkConditions(
    array $conditions,
    string $where,
    array $known,
    bool $questPrerequisites = false
  ): array
  {
    $issues = [];

    foreach ($conditions as $condition) {
      if (! is_array($condition)) {
        continue;
      }

      $typeValue = $condition['type'] ?? null;

      if (! is_string($typeValue)) {
        $issues[] = Issue::error(
          $where,
          'It has a malformed condition type.',
          'Use a string from the shared world-condition vocabulary.'
        );
        continue;
      }

      $type = $typeValue;

      if (! WorldConditionType::tryFrom($type) instanceof WorldConditionType) {
        $issues[] = Issue::error(
          $where,
          sprintf('It uses the unknown condition type "%s".', $type !== '' ? $type : '(empty)'),
          'The runtime fails unknown conditions closed, so this guarded content is inaccessible.'
        );
        continue;
      }

      $nameValue = $condition['name'] ?? null;

      if (! is_string($nameValue)) {
        $issues[] = Issue::error(
          $where,
          'It has a malformed condition name.',
          'Use a non-empty string world-state identity.'
        );
        continue;
      }

      $name = $nameValue;

      if ($questPrerequisites && $type === WorldConditionType::QUEST->value) {
        $trimmedName = trim($name);

        if ($trimmedName !== '' && ! in_array($trimmedName, $known['quests'] ?? [], true)) {
          $issues[] = Issue::error(
            $where,
            sprintf('It waits on the quest "%s", which does not exist.', $trimmedName),
            'The quest can never be accepted. Check the id.'
          );
        }

        continue;
      }

      $issues = [...$issues, ...match ($type) {
        'quest' => $this->checkReference($name, 'quests', 'quest', $where, $known),
        'item', 'key_item' => $this->checkReference($name, 'inventory', 'item', $where, $known),
        default => [],
      }];
    }

    return $issues;
  }

  /**
   * Checks one name against what the project defines.
   *
   * @param string $value The name as authored.
   * @param string $category What kind of thing it names.
   * @param string $noun What to call it.
   * @param string $where Where it was written.
   * @param array<string, string[]> $known What the project defines.
   * @return Issue[] The issue, if there is one.
   */
  protected function checkReference(string $value, string $category, string $noun, string $where, array $known): array
  {
    $value = trim($value);

    if ($value === '' || in_array($value, $known[$category] ?? [], true)) {
      return [];
    }

    if (in_array($category, ['bgm', 'sfx'], true)) {
      if (str_contains($value, '/')) {
        // The engine also takes a path relative to assets, which this has no
        // business second-guessing.
        return [];
      }

      return [Issue::warning(
        $where,
        sprintf('It plays the %s "%s", which the project has no file for.', $noun, $value),
        'Nothing will be heard. Check the name, or add the file.'
      )];
    }

    return [Issue::error(
      $where,
      sprintf('It names the %s "%s", which does not exist.', $noun, $value),
      'It will silently do nothing at runtime. Check the name.'
    )];
  }
}
