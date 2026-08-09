<?php

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\ProjectQuest;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;

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
    ];

    usort(
      $issues,
      static fn(Issue $a, Issue $b): int => [$a->severity->value, $a->where] <=> [$b->severity->value, $b->where]
    );

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

    foreach ($quests as $quest) {
      $questId = $quest->getId();
      $where = sprintf('quest %s', $questId !== '' ? $questId : '(unnamed)');

      if ($questId === '') {
        $issues[] = Issue::error($where, 'The quest has no id.', 'Every quest needs one; it is what triggers grant.');
      }

      $issues = [
        ...$issues,
        ...$this->checkObjectives($quest, $where, $workspace->mapIds, $items, $foes),
      ];

      foreach ($quest->getPrerequisites() as $prerequisite) {
        $name = trim(strval($prerequisite['name'] ?? ''));

        if (strval($prerequisite['type'] ?? '') === 'quest' && $name !== '' && ! in_array($name, $questIds, true)) {
          $issues[] = Issue::error(
            $where,
            sprintf('It waits on the quest "%s", which does not exist.', $name),
            'The quest can never be accepted. Check the id.'
          );
        }
      }
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
}
