<?php

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\Database\InventoryCatalog;
use Ichiloto\Editor\Database\KnowledgeCommandShape;
use Ichiloto\Editor\Database\PhpDataFile;
use Ichiloto\Editor\Database\ProjectRecord;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Field\ProjectNpc;
use Ichiloto\Editor\ActorStatPreview;
use Ichiloto\Editor\EquipmentOptimizationPolicy;
use Ichiloto\Editor\PermanentGrowthCatalog;
use Ichiloto\Editor\ProjectDirectoryContext;
use Ichiloto\Editor\ProjectQuest;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Core\WorldConditionType;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Events\Interpreter\MovementRouteRunner;
use Ichiloto\Engine\IO\Console\TerminalText;
use InvalidArgumentException;
use Throwable;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

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
  /**
   * @var InventoryCatalog|null What an inventory reference means in this
   * project, read once per validation run.
   */
  protected ?InventoryCatalog $inventoryCatalog = null;

  /**
   * @var array<string, mixed>|null The project's knowledge catalogue, read
   * once per validation run so every command check shares one reading.
   */
  protected ?array $knowledgeCatalogData = null;

  /**
   * @var string|null The project root, for the catalogue a command names.
   */
  protected ?string $projectRootForKnowledge = null;

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
    $this->projectRootForKnowledge = $workspace->projectRoot;
    $this->knowledgeCatalogData = null;

    try {
      $this->inventoryCatalog = InventoryCatalog::fromWorkspace($workspace);
    } catch (Throwable $throwable) {
      return [Issue::error(
        'assets/Data/items.php',
        sprintf('The inventory could not be read: %s', $throwable->getMessage()),
        'Until this is fixed the project has no items, weapons or armors at all.'
      )];
    }

    $unreadableInventory = $this->describeUnreadableInventory($workspace);

    if ($unreadableInventory !== null) {
      // Every reference to an item would now fail for the same reason, and
      // a page of "does not exist" would bury it. One accurate error beats
      // the cascade it causes.
      return [$unreadableInventory];
    }

    $issues = [
      ...$this->checkMaps($workspace),
      ...$this->checkQuests($workspace),
      ...$this->checkTroops($workspace),
      ...$this->checkSummons($workspace),
      ...$this->checkReferences($workspace),
      ...$this->checkDefinitionIdentities($workspace),
      ...$this->checkActorDefinitions($workspace),
      ...$this->checkKnowledgeCatalog($workspace),
      ...$this->checkSpecialProperties($workspace),
      ...$this->checkPermanentGrowth($workspace),
      ...$this->checkOptimizationPolicy($workspace),
      ...new SaveCompatibilityValidator()->validate($workspace),
    ];

    usort(
      $issues,
      static fn(Issue $a, Issue $b): int => [$a->severity->value, $a->where] <=> [$b->severity->value, $b->where]
    );

    return $issues;
  }

  /**
   * Returns the one error to report when a project has an inventory file
   * the runtime cannot read, or null when it can.
   *
   * The engine's own constructors enforce a definition's bounds -- a sell
   * rate outside 0 through 10000, a modifier outside -100 through 100, an
   * affinity that is not a factor -- by refusing to build it, which takes
   * the whole file with it. The catalogue is then empty, and every
   * reference to an item in the project fails for a reason that has
   * nothing to do with that reference.
   *
   * @param ProjectWorkspace $workspace The project.
   * @return Issue|null The error, or null.
   */
  protected function describeUnreadableInventory(ProjectWorkspace $workspace): ?Issue
  {
    $path = rtrim($workspace->projectRoot, '/') . '/assets/Data/items.php';

    // Whether the file loaded at all, which a catalogue whose every
    // definition is contested still did.
    if (! is_file($path) || $this->inventoryCatalog->allDefinitions() !== []) {
      return null;
    }

    try {
      $payload = ProjectDirectoryContext::run($workspace->projectRoot, static fn(): mixed => require $path);
    } catch (Throwable $throwable) {
      return Issue::error(
        'assets/Data/items.php',
        sprintf('The inventory could not be read: %s', $throwable->getMessage()),
        'Until this is fixed the project has no items, weapons or armors at all.'
      );
    }

    if (is_array($payload) && $payload !== []) {
      return Issue::error(
        'assets/Data/items.php',
        'The inventory holds entries the project could not read as definitions.',
        'Until this is fixed the project has no items, weapons or armors at all.'
      );
    }

    return null;
  }

  /**
   * Checks that every inventory definition can be resolved to exactly one
   * thing, and that its policy numbers are ones the runtime accepts.
   *
   * The catalogue records what it could not resolve rather than throwing,
   * so an author can open a project that is already broken; this is where
   * that record becomes something they can read.
   *
   * @param ProjectWorkspace $workspace The project.
   * @return Issue[] The issues found.
   */
  protected function checkDefinitionIdentities(ProjectWorkspace $workspace): array
  {
    $issues = [];

    foreach ($this->inventoryCatalog->conflicts() as $reference => $claimants) {
      $issues[] = Issue::error(
        'assets/Data/items.php',
        sprintf('"%s" is claimed by %s.', $reference, implode(' and ', $claimants)),
        'A name or alias must resolve to one definition. Rename one, or drop the duplicate alias.'
      );
    }

    foreach (['items', 'weapons', 'armors'] as $category) {
      $database = $workspace->getRecordDatabase($category);

      if (! $database instanceof ProjectRecordDatabase) {
        continue;
      }

      // Every bound a definition has -- the sell rate, the accuracy and
      // critical modifiers, the affinity factors -- is enforced by the
      // engine's own constructors, which refuse to build a definition that
      // breaks one. Re-checking them here would be a second opinion about
      // the same rule; what the editor adds is that a name must resolve to
      // one definition, which nothing else checks.
      unset($database);
    }

    return $issues;
  }

  /**
   * Checks the identities and natures a project's actors declare.
   *
   * @param ProjectWorkspace $workspace The project.
   * @return Issue[] The issues found.
   */
  protected function checkActorDefinitions(ProjectWorkspace $workspace): array
  {
    $issues = [];
    $seen = [];
    $statKeys = ActorStatPreview::statKeys();

    foreach ($workspace->actorDatabase->getActors() as $actor) {
      $where = sprintf('actor %s', $actor->getName());
      $id = $actor->getDefinitionId();

      if (isset($seen[$id])) {
        $issues[] = Issue::error(
          $where,
          sprintf('Two actors resolve to the identity "%s".', $id),
          'A save restores an actor by this identity. Give each one its own.'
        );
      }

      $seen[$id] = true;

      // An actor without its own id is resolved by display name, which the
      // runtime supports deliberately. That is a convention worth adopting
      // rather than a defect, so the Inspector says so on the row where it
      // can be acted on; a validator reporting it on every legacy actor
      // would be noise in front of the findings that are defects.

      $variants = $actor->getNaturalVariants();
      $default = $actor->getDefaultNaturalVariantId();

      if ($variants !== [] && ($default === null || ! isset($variants[$default]))) {
        $issues[] = Issue::error(
          $where,
          $default === null
            ? 'It declares natural variants but no default.'
            : sprintf('Its default natural variant "%s" is not one it declares.', $default),
          'The runtime refuses to build an actor whose default variant it cannot find.'
        );
      }

      if ($variants === [] && $default !== null) {
        $issues[] = Issue::error(
          $where,
          sprintf('It names the default natural variant "%s" but declares no variants.', $default),
          'Declare the variant, or remove the default.'
        );
      }

      $sets = ['actorNaturalAdjustments' => $actor->getActorNaturalAdjustments()];

      foreach ($variants as $variantId => $adjustments) {
        $sets[sprintf('variant %s', $variantId)] = $adjustments;
      }

      foreach ($sets as $label => $adjustments) {
        foreach (array_keys($adjustments) as $key) {
          if (! in_array($key, $statKeys, true)) {
            $issues[] = Issue::error(
              $where,
              sprintf('Its %s adjusts "%s", which is not a stat the runtime resolves.', $label, $key),
              sprintf('Use one of: %s.', implode(', ', $statKeys))
            );
          }
        }
      }
    }

    return $issues;
  }

  /**
   * Checks that a knowledge catalogue points only at things it declares.
   *
   * @param ProjectWorkspace $workspace The project.
   * @return Issue[] The issues found.
   */
  /**
   * Checks the special properties a project's equipment declares.
   *
   * The runtime carries a special property as a shape it does not interpret,
   * and Optimize weighs it by the `type` inside it. A property with no type
   * is therefore weighed by nothing and read by nothing, and one that is not
   * a set of keys and values is not the shape at all.
   *
   * @param ProjectWorkspace $workspace The project.
   * @return Issue[] What is wrong with them.
   */
  protected function checkSpecialProperties(ProjectWorkspace $workspace): array
  {
    $issues = [];

    foreach (InventoryCatalog::CATEGORIES as $category) {
      $database = $workspace->getRecordDatabase($category);

      if (! $database instanceof ProjectRecordDatabase) {
        continue;
      }

      foreach ($database->getRecords() as $record) {
        $property = $record->get('specialProperty');

        if ($property === null) {
          continue;
        }

        $name = trim(strval($record->get('name') ?? '')) ?: 'an unnamed definition';

        if (! is_array($property)) {
          $issues[] = Issue::error(
            'assets/Data/items.php',
            sprintf('The special property on %s is not a set of keys and values.', $name)
          );

          continue;
        }

        if (trim(strval($property['type'] ?? '')) === '') {
          $issues[] = Issue::error(
            'assets/Data/items.php',
            sprintf('The special property on %s names no type.', $name),
            'Optimize weighs a special property by its type; one without is weighed by nothing.'
          );
        }

        if (array_key_exists('parameters', $property) && ! is_array($property['parameters'])) {
          $issues[] = Issue::error(
            'assets/Data/items.php',
            sprintf('The special property parameters on %s are not a set of keys and values.', $name)
          );
        }
      }
    }

    return $issues;
  }

  /**
   * Checks the permanent growth a project defines.
   *
   * Every rule is the engine's own, run rather than restated: a definition
   * is built through `PermanentStatModifier` and granted into a real
   * `PermanentGrowthLedger`, so an empty identity, a stat the runtime does
   * not have, an amount that is not an integer, missing provenance, and an
   * id two definitions disagree over all fail here exactly where they would
   * fail in the game, and are reported in the engine's words.
   *
   * @param ProjectWorkspace $workspace The project.
   * @return Issue[] What is wrong with it.
   */
  protected function checkPermanentGrowth(ProjectWorkspace $workspace): array
  {
    $where = PermanentGrowthCatalog::RELATIVE_PATH;
    $path = rtrim($workspace->projectRoot, '/') . '/' . $where;

    if (! is_file($path)) {
      return [];
    }

    try {
      $payload = (static fn(): mixed => require $path)();
    } catch (Throwable $throwable) {
      return [Issue::error($where, 'The definitions could not be loaded: ' . $throwable->getMessage())];
    }

    if (! is_array($payload)) {
      return [Issue::error($where, 'The file must return a list of definitions.')];
    }

    $issues = [];

    foreach (array_values($payload) as $index => $entry) {
      if (is_array($entry) && ! is_array($entry['metadata'] ?? [])) {
        $issues[] = Issue::error(
          $where,
          sprintf('Definition %d carries metadata that is not a set of keys and values.', $index),
          'Project-owned metadata rides along with a grant; anything else the runtime will not carry.'
        );
      }
    }

    foreach (array_values($payload) as $index => $entry) {
      foreach (is_array($entry) && is_array($entry['metadata'] ?? null) ? $entry['metadata'] : [] as $key => $value) {
        if (! is_string($key) || trim($key) === '') {
          $issues[] = Issue::error(
            $where,
            sprintf('Definition %d has metadata under a key that is not a name.', $index)
          );
        }
      }
    }

    $catalog = PermanentGrowthCatalog::fromPayload($payload);

    foreach ($catalog->faults() as $fault) {
      $issues[] = Issue::error(
        $where,
        sprintf(
          '%s: %s',
          $fault['id'] === '' ? sprintf('Definition %d', $fault['index']) : $fault['id'],
          $fault['message']
        ),
        'The runtime raises this itself the moment the grant is made.'
      );
    }

    foreach ($catalog->repeats() as $id) {
      $issues[] = Issue::warning(
        $where,
        sprintf('"%s" is defined more than once with identical content.', $id),
        'The runtime grants it once and treats the repeat as already done, so the second definition does nothing.'
      );
    }

    return $issues;
  }

  /**
   * Checks the Optimize policy a project declares.
   *
   * The scoring rules are the engine's, so the policy is handed to the
   * engine's own constructor and whatever it refuses is reported in its own
   * words. What is checked here instead is the part the engine cannot know:
   * whether a weight, an exclusion or an outcome names something this
   * project actually has, because one that does not is silently never looked
   * up and reads exactly like a policy that is working.
   *
   * @param ProjectWorkspace $workspace The project.
   * @return Issue[] What is wrong with it.
   */
  protected function checkOptimizationPolicy(ProjectWorkspace $workspace): array
  {
    $where = EquipmentOptimizationPolicy::RELATIVE_PATH;
    $path = rtrim($workspace->projectRoot, '/') . '/' . $where;

    if (! is_file($path)) {
      return [];
    }

    try {
      $policy = (static fn(): mixed => require $path)();
    } catch (Throwable $throwable) {
      return [Issue::error($where, 'The policy could not be loaded: ' . $throwable->getMessage())];
    }

    if (! is_array($policy)) {
      return [Issue::error($where, 'The file must return the policy as an array.')];
    }

    $issues = [];

    foreach (array_keys($policy) as $key) {
      if (! in_array(strval($key), EquipmentOptimizationPolicy::KEYS, true)) {
        $issues[] = Issue::warning(
          $where,
          sprintf('"%s" is not part of the policy the runtime reads.', strval($key)),
          'Nothing reads it, so whatever it says has no effect on what Optimize chooses.'
        );
      }
    }

    if (EquipmentOptimizationPolicy::isAvailable() && EquipmentOptimizationPolicy::enginePolicyFor($workspace->projectRoot) === null) {
      // The engine refuses a weight key it does not have and a weight that
      // is not an integer, by throwing rather than by ignoring them.
      $issues[] = Issue::error(
        $where,
        $this->describePolicyRefusal($workspace->projectRoot),
        'Until this is fixed the game falls back to the legacy equal-weight policy.'
      );
    }

    $references = new ReferenceCatalog($workspace);
    $roles = array_map(mb_strtolower(...), $references->valuesFor('classes'));
    $slots = EquipmentOptimizationPolicy::slotKeys();

    foreach (['roleStatWeights' => $roles, 'slotStatWeights' => $slots] as $key => $known) {
      foreach (array_keys(is_array($policy[$key] ?? null) ? $policy[$key] : []) as $scope) {
        if (! in_array(mb_strtolower(strval($scope)), $known, true)) {
          $issues[] = Issue::warning(
            $where,
            sprintf('%s narrows to "%s", which this project does not have.', $key, strval($scope)),
            'No character or slot ever matches it, so those weights are never applied.'
          );
        }
      }
    }

    foreach (is_array($policy['roleSlotStatWeights'] ?? null) ? $policy['roleSlotStatWeights'] : [] as $role => $scoped) {
      if (! in_array(mb_strtolower(strval($role)), $roles, true)) {
        $issues[] = Issue::warning(
          $where,
          sprintf('roleSlotStatWeights narrows to the role "%s", which this project does not have.', strval($role)),
          'No character ever matches it, so those weights are never applied.'
        );
      }

      foreach (array_keys(is_array($scoped) ? $scoped : []) as $slot) {
        if (! in_array(mb_strtolower(strval($slot)), $slots, true)) {
          $issues[] = Issue::warning(
            $where,
            sprintf('roleSlotStatWeights narrows to the slot "%s", which is not a kind of slot.', strval($slot)),
            'No slot ever matches it, so those weights are never applied.'
          );
        }
      }
    }

    $elements = array_map(mb_strtolower(...), $references->valuesFor('elements'));

    foreach (array_keys(is_array($policy['elementOutcomeWeights'] ?? null) ? $policy['elementOutcomeWeights'] : []) as $name) {
      $complaint = $this->describeOutcomeName(strval($name), $elements);

      if ($complaint !== null) {
        $issues[] = Issue::warning($where, $complaint, 'The runtime never composes that name, so the weight is never looked up.');
      }
    }

    $properties = array_map(mb_strtolower(...), $references->valuesFor('equipment_special_properties'));

    foreach (array_keys(is_array($policy['specialPropertyWeights'] ?? null) ? $policy['specialPropertyWeights'] : []) as $property) {
      if (! in_array(mb_strtolower(strval($property)), $properties, true)) {
        $issues[] = Issue::warning(
          $where,
          sprintf('No equipment carries the special property "%s".', strval($property)),
          'Nothing scores it, so the weight has no effect on what Optimize chooses.'
        );
      }
    }

    return [...$issues, ...$this->checkOptimizationExclusions($workspace, $policy, $references)];
  }

  /**
   * Checks that each exclusion names something automatic selection could
   * otherwise have taken.
   *
   * @param ProjectWorkspace $workspace The project.
   * @param array<string, mixed> $policy The authored policy.
   * @param ReferenceCatalog $references What the project has.
   * @return Issue[] What is wrong with it.
   */
  protected function checkOptimizationExclusions(
    ProjectWorkspace $workspace,
    array $policy,
    ReferenceCatalog $references
  ): array
  {
    $where = EquipmentOptimizationPolicy::RELATIVE_PATH;
    $vocabularies = [
      'excludedDefinitionIds' => ['an item', $references->valuesFor('inventory')],
      'excludedAvailabilities' => ['an availability', $references->valuesFor('equipment_availabilities')],
      'excludedAcquisitionPolicies' => ['an acquisition policy', $references->valuesFor('equipment_acquisition_policies')],
    ];
    $issues = [];

    foreach ($vocabularies as $key => [$noun, $known]) {
      $known = array_map(mb_strtolower(...), $known);

      foreach (is_array($policy[$key] ?? null) ? $policy[$key] : [] as $value) {
        $value = is_scalar($value) ? trim(strval($value)) : '';

        if ($value === '') {
          $issues[] = Issue::warning(
            $where,
            sprintf('%s holds an exclusion that names nothing.', $key),
            'It excludes nothing; finish naming it or remove it.'
          );

          continue;
        }

        if (! in_array(mb_strtolower($value), $known, true)) {
          $issues[] = Issue::warning(
            $where,
            sprintf('"%s" is excluded, but this project has no such %s.', $value, $noun),
            'Nothing matches it, so the exclusion has no effect.'
          );
        }
      }
    }

    return $issues;
  }

  /**
   * Returns why the engine refused to build the declared policy, in its own
   * words.
   *
   * @param string $projectRoot The project root.
   * @return string The message.
   */
  protected function describePolicyRefusal(string $projectRoot): string
  {
    $declared = EquipmentOptimizationPolicy::declaredIn($projectRoot) ?? [];

    try {
      new \Ichiloto\Engine\Entities\EquipmentOptimization\DeclaredEquipmentOptimizationPolicy(
        statWeights: is_array($declared['statWeights'] ?? null) ? $declared['statWeights'] : [],
        roleStatWeights: is_array($declared['roleStatWeights'] ?? null) ? $declared['roleStatWeights'] : [],
        slotStatWeights: is_array($declared['slotStatWeights'] ?? null) ? $declared['slotStatWeights'] : [],
        roleSlotStatWeights: is_array($declared['roleSlotStatWeights'] ?? null) ? $declared['roleSlotStatWeights'] : [],
        elementOutcomeWeights: is_array($declared['elementOutcomeWeights'] ?? null) ? $declared['elementOutcomeWeights'] : [],
        specialPropertyWeights: is_array($declared['specialPropertyWeights'] ?? null) ? $declared['specialPropertyWeights'] : [],
      );
    } catch (Throwable $throwable) {
      return sprintf('The runtime refuses this policy: %s', $throwable->getMessage());
    }

    return 'The runtime refuses this policy.';
  }

  /**
   * Returns why a composed outcome name will never be looked up, or null
   * when it will be.
   *
   * @param string $name The authored name.
   * @param string[] $elements The project's elements, lowercased.
   * @return string|null The complaint.
   */
  protected function describeOutcomeName(string $name, array $elements): ?string
  {
    $parts = explode(':', mb_strtolower($name));
    $element = $parts[1] ?? '';
    $namesAnElement = $element === EquipmentOptimizationPolicy::ANY_ELEMENT
      || $elements === []
      || in_array($element, $elements, true);

    if (count($parts) === 2 && $parts[0] === 'offence') {
      return $namesAnElement
        ? null
        : sprintf('"%s" weighs dealing "%s", which this project does not have as an element.', $name, $element);
    }

    if (count($parts) === 3 && $parts[0] === 'defence') {
      if (! in_array($parts[2], EquipmentOptimizationPolicy::OUTCOMES, true)) {
        return sprintf(
          '"%s" weighs the outcome "%s", which is not one an affinity comes to (%s).',
          $name,
          $parts[2],
          implode(', ', EquipmentOptimizationPolicy::OUTCOMES)
        );
      }

      return $namesAnElement
        ? null
        : sprintf('"%s" weighs "%s", which this project does not have as an element.', $name, $element);
    }

    return sprintf(
      '"%s" is not a name the runtime composes (offence:<element> or defence:<element>:<outcome>).',
      $name
    );
  }

  protected function checkKnowledgeCatalog(ProjectWorkspace $workspace): array
  {
    $path = rtrim($workspace->projectRoot, '/') . '/assets/Data/knowledge.php';

    if (! is_file($path)) {
      return [];
    }

    try {
      $catalog = (static fn(): mixed => require $path)();
    } catch (Throwable $throwable) {
      return [Issue::error('assets/Data/knowledge.php', 'The catalogue could not be loaded: ' . $throwable->getMessage())];
    }

    if (! is_array($catalog)) {
      return [Issue::error('assets/Data/knowledge.php', 'The catalogue must return an array.')];
    }

    $where = 'assets/Data/knowledge.php';
    $issues = [];

    // Author truth is for the private repository, not for a file the
    // runtime ships and a player's save can read.
    foreach (['private', 'privateTruth', 'authorTruth', 'spoilerTruth'] as $forbidden) {
      if (array_key_exists($forbidden, $catalog)) {
        $issues[] = Issue::error(
          $where,
          sprintf('It carries a "%s" section.', $forbidden),
          'The runtime refuses to load it, and it would ship what only the author should know.'
        );
      }
    }

    $recordTypes = array_map(strval(...), (array) ($catalog['recordTypes'] ?? []));
    $subjectIds = [];

    foreach ((array) ($catalog['subjects'] ?? []) as $index => $subject) {
      if (! is_array($subject)) {
        $issues[] = Issue::error($where, sprintf('Subject %s is not a structured record.', strval($index)));
        continue;
      }

      $id = trim(strval($subject['id'] ?? ''));
      $label = $id === '' ? sprintf('subject %s', strval($index)) : $id;

      if ($id === '') {
        $issues[] = Issue::error($where, sprintf('Subject %s has no id.', strval($index)), 'Every subject is resolved by a stable id.');
      } elseif (isset($subjectIds[$id])) {
        $issues[] = Issue::error($where, sprintf('Subject id "%s" is declared more than once.', $id));
      } else {
        $subjectIds[$id] = true;
      }

      $type = trim(strval($subject['recordType'] ?? ''));

      if ($type === '' || ! in_array($type, $recordTypes, true)) {
        $issues[] = Issue::error(
          $where,
          sprintf('%s has the record type "%s", which the catalogue does not declare.', $label, $type),
          'Add it to recordTypes, or choose one that is there.'
        );
      }

      if (trim(strval($subject['quickCard'] ?? '')) === '') {
        $issues[] = Issue::error($where, sprintf('%s has no quick card.', $label), 'A record shows its quick card first.');
      }
    }

    foreach ((array) ($catalog['subjects'] ?? []) as $subject) {
      if (! is_array($subject)) {
        continue;
      }

      $label = trim(strval($subject['id'] ?? '(unnamed)'));

      foreach ((array) ($subject['relationships'] ?? []) as $relationship) {
        $related = is_array($relationship) ? trim(strval($relationship['subject'] ?? '')) : '';

        if ($related === '' || ! isset($subjectIds[$related])) {
          $issues[] = Issue::error(
            $where,
            sprintf('%s is related to "%s", which the catalogue does not declare.', $label, $related),
            'The runtime refuses a relationship it cannot resolve.'
          );
        }
      }
    }

    foreach ((array) ($catalog['enemyMappings'] ?? []) as $enemy => $subjectId) {
      if (! isset($subjectIds[trim(strval($subjectId))])) {
        $issues[] = Issue::error(
          $where,
          sprintf('The enemy "%s" maps to the subject "%s", which the catalogue does not declare.', strval($enemy), strval($subjectId)),
          'Defeating it would record knowledge of nothing.'
        );
      }
    }

    $reportIds = [];

    foreach ((array) ($catalog['reports'] ?? []) as $index => $report) {
      if (! is_array($report)) {
        $issues[] = Issue::error($where, sprintf('Report %s is not a structured record.', strval($index)));
        continue;
      }

      $id = trim(strval($report['id'] ?? ''));
      $label = $id === '' ? sprintf('report %s', strval($index)) : $id;

      if ($id === '') {
        $issues[] = Issue::error($where, sprintf('Report %s has no id.', strval($index)));
      } elseif (isset($reportIds[$id])) {
        $issues[] = Issue::error($where, sprintf('Report id "%s" is declared more than once.', $id));
      } else {
        $reportIds[$id] = true;
      }

      $subjectId = trim(strval($report['subject'] ?? ''));

      if (! isset($subjectIds[$subjectId])) {
        $issues[] = Issue::error(
          $where,
          sprintf('%s concerns the subject "%s", which the catalogue does not declare.', $label, $subjectId),
          'A report is unlocked against a subject the party can know.'
        );
      }
    }

    foreach ((array) ($catalog['reports'] ?? []) as $report) {
      foreach (is_array($report) ? (array) ($report['disagreesWith'] ?? []) : [] as $other) {
        if (! isset($reportIds[trim(strval($other))])) {
          $issues[] = Issue::warning(
            $where,
            sprintf('Report "%s" disagrees with "%s", which the catalogue does not declare.', trim(strval($report['id'] ?? '')), strval($other)),
            'The disagreement will never be shown.'
          );
        }
      }
    }

    return [...$issues, ...$this->checkKnowledgeIdentities($catalog, $where)];
  }

  /**
   * Checks one knowledge command against what the runtime does with it.
   *
   * `KnowledgeProgressService::apply()` reads a different set of the command
   * for each operation and raises on what it cannot use: an unknown
   * operation, an id that is not stable, a confidence outside zero to one, a
   * report that is not the subject's, an observation the subject does not
   * author, or a report superseded by itself. Each of those is a crash in
   * play, so each is reported here instead.
   *
   * @param array<string, mixed> $command The authored command.
   * @param string $where The file being checked.
   * @return Issue[] What is wrong with it.
   */
  protected function checkKnowledgeCommand(array $command, string $where): array
  {
    $operation = trim(strval($command['operation'] ?? ''));

    if (! in_array($operation, KnowledgeCommandShape::operations(), true)) {
      return [Issue::error(
        $where,
        sprintf('Its knowledge operation "%s" is not one the runtime performs.', $operation !== '' ? $operation : '(empty)'),
        sprintf('The runtime performs: %s.', implode(', ', KnowledgeCommandShape::operations()))
      )];
    }

    $issues = [];
    $catalog = $this->knowledgeCatalogData();
    $fields = KnowledgeCommandShape::fieldNamesFor($operation);
    $subjectId = trim(strval($command['subject'] ?? ''));

    foreach ($fields as $field) {
      // Source and confidence both have runtime defaults, so an absent one
      // is authored, not missing.
      if (in_array($field, ['source', 'confidence'], true)) {
        continue;
      }

      if (trim(strval($command[$field] ?? '')) === '') {
        $issues[] = Issue::error(
          $where,
          sprintf('Its knowledge %s command names no %s.', $operation, $field),
          sprintf('%s reads %s.', $operation, implode(', ', $fields))
        );
      }
    }

    if (isset($command['confidence']) && in_array('confidence', $fields, true)) {
      $confidence = $command['confidence'];

      if (! is_numeric($confidence) || floatval($confidence) < 0.0 || floatval($confidence) > 1.0) {
        $issues[] = Issue::error(
          $where,
          sprintf('Its knowledge confidence "%s" is not between 0 and 1.', strval(is_scalar($confidence) ? $confidence : '')),
          'The runtime raises on anything else.'
        );
      }
    }

    if ($catalog === []) {
      // Nothing to check references against; the catalogue's own checks
      // report why it could not be read.
      return $issues;
    }

    $subjects = $this->knowledgeIdsIn($catalog, 'subjects');
    $reports = $this->knowledgeReportSubjects($catalog);

    if ($subjectId !== '' && ! isset($subjects[$subjectId])) {
      $issues[] = Issue::error(
        $where,
        sprintf('Its knowledge command names the subject "%s", which the catalogue does not declare.', $subjectId)
      );
    }

    foreach (['report', 'replacement'] as $field) {
      $reportId = in_array($field, $fields, true) ? trim(strval($command[$field] ?? '')) : '';

      if ($reportId === '') {
        continue;
      }

      if (! array_key_exists($reportId, $reports)) {
        $issues[] = Issue::error(
          $where,
          sprintf('Its knowledge command names the report "%s", which the catalogue does not declare.', $reportId)
        );

        continue;
      }

      if ($subjectId !== '' && $reports[$reportId] !== $subjectId) {
        $issues[] = Issue::error(
          $where,
          sprintf('The report "%s" belongs to "%s", not to "%s".', $reportId, $reports[$reportId], $subjectId),
          'The runtime refuses a report that is not the subject\'s.'
        );
      }
    }

    if ($operation === 'supersede_report'
      && trim(strval($command['report'] ?? '')) !== ''
      && trim(strval($command['report'] ?? '')) === trim(strval($command['replacement'] ?? ''))) {
      $issues[] = Issue::error(
        $where,
        'Its knowledge command supersedes a report with itself.',
        'The runtime requires a different report.'
      );
    }

    if ($operation === 'observe' && $subjectId !== '') {
      $observation = trim(strval($command['observation'] ?? ''));
      $authored = $subjects[$subjectId] ?? [];

      if ($observation !== '' && $authored !== [] && ! in_array($observation, $authored, true)) {
        $issues[] = Issue::error(
          $where,
          sprintf('The observation "%s" is not authored for "%s".', $observation, $subjectId),
          'The runtime refuses an observation the subject does not declare.'
        );
      }
    }

    return $issues;
  }

  /**
   * Returns the project's knowledge catalogue, read once per validation run.
   *
   * @return array<string, mixed> The catalogue.
   */
  protected function knowledgeCatalogData(): array
  {
    if ($this->knowledgeCatalogData !== null) {
      return $this->knowledgeCatalogData;
    }

    $path = rtrim($this->projectRootForKnowledge ?? '', '/') . '/assets/Data/knowledge.php';

    if ($this->projectRootForKnowledge === null || ! is_file($path)) {
      return $this->knowledgeCatalogData = [];
    }

    try {
      $catalog = (static fn(): mixed => require $path)();
    } catch (Throwable) {
      return $this->knowledgeCatalogData = [];
    }

    return $this->knowledgeCatalogData = is_array($catalog) ? $catalog : [];
  }

  /**
   * Returns a catalogue section's ids, each with the observations it
   * authors.
   *
   * @param array<string, mixed> $catalog The catalogue.
   * @param string $section The section.
   * @return array<string, string[]> Observations by id.
   */
  protected function knowledgeIdsIn(array $catalog, string $section): array
  {
    $ids = [];

    foreach ((array) ($catalog[$section] ?? []) as $entry) {
      if (! is_array($entry)) {
        continue;
      }

      $id = trim(strval($entry['id'] ?? ''));

      if ($id !== '') {
        $ids[$id] = array_values(array_filter(array_map(
          static fn(mixed $observation): string => is_scalar($observation) ? trim(strval($observation)) : '',
          is_array($entry['observations'] ?? null) ? $entry['observations'] : [],
        ), static fn(string $observation): bool => $observation !== ''));
      }
    }

    return $ids;
  }

  /**
   * Returns which subject each report concerns.
   *
   * @param array<string, mixed> $catalog The catalogue.
   * @return array<string, string> Subject id by report id.
   */
  protected function knowledgeReportSubjects(array $catalog): array
  {
    $reports = [];

    foreach ((array) ($catalog['reports'] ?? []) as $entry) {
      $id = is_array($entry) ? trim(strval($entry['id'] ?? '')) : '';

      if ($id !== '') {
        $reports[$id] = trim(strval($entry['subject'] ?? ''));
      }
    }

    return $reports;
  }

  /**
   * Checks the identities and vocabularies a knowledge catalogue declares.
   *
   * The runtime holds every knowledge id to one shape through
   * `KnowledgeIdentity::require()`, and refuses the whole catalogue when one
   * does not match -- so an id that is merely unusual here is a project that
   * will not load there.
   *
   * @param array<string, mixed> $catalog The catalogue.
   * @param string $where The file being checked.
   * @return Issue[] What is wrong with it.
   */
  protected function checkKnowledgeIdentities(array $catalog, string $where): array
  {
    $issues = [];
    $recordTypes = [];

    foreach ((array) ($catalog['recordTypes'] ?? []) as $index => $type) {
      $type = is_scalar($type) ? trim(strval($type)) : '';

      if ($type === '') {
        $issues[] = Issue::error($where, sprintf('Record type %s is empty.', strval($index)));

        continue;
      }

      if (isset($recordTypes[$type])) {
        $issues[] = Issue::warning(
          $where,
          sprintf('Record type "%s" is declared more than once.', $type),
          'The runtime keeps one of them; the repeat does nothing.'
        );
      }

      $recordTypes[$type] = true;
    }

    foreach ([
      'subjects' => 'subject id',
      'reports' => 'report id',
    ] as $section => $label) {
      foreach ((array) ($catalog[$section] ?? []) as $entry) {
        $id = is_array($entry) ? trim(strval($entry['id'] ?? '')) : '';
        $complaint = $this->describeKnowledgeIdentity($id, $label);

        if ($complaint !== null) {
          $issues[] = Issue::error($where, $complaint, 'The runtime refuses the whole catalogue over one.');
        }

        foreach (is_array($entry) && is_array($entry['observations'] ?? null) ? $entry['observations'] : [] as $observation) {
          $complaint = $this->describeKnowledgeIdentity(
            is_scalar($observation) ? trim(strval($observation)) : '',
            sprintf('observation id on %s', $id === '' ? 'a subject' : $id),
          );

          if ($complaint !== null) {
            $issues[] = Issue::error($where, $complaint);
          }
        }
      }
    }

    foreach ((array) ($catalog['enemyMappings'] ?? []) as $enemy => $subjectId) {
      if (trim(strval($enemy)) === '') {
        $issues[] = Issue::error($where, 'An enemy mapping names no enemy.', 'It can never match a battler.');
      }

      $complaint = $this->describeKnowledgeIdentity(
        is_scalar($subjectId) ? trim(strval($subjectId)) : '',
        sprintf('mapped subject id for "%s"', strval($enemy)),
      );

      if ($complaint !== null) {
        $issues[] = Issue::error($where, $complaint, 'The runtime refuses the whole catalogue over one.');
      }
    }

    return $issues;
  }

  /**
   * Returns why a knowledge id is not one the runtime accepts, or null when
   * it is.
   *
   * @param string $id The authored id.
   * @param string $label What the id is for.
   * @return string|null The complaint.
   */
  protected function describeKnowledgeIdentity(string $id, string $label): ?string
  {
    if ($id === '') {
      return sprintf('A %s is missing.', $label);
    }

    // The runtime's own shape: lowercase segments of letters and numbers,
    // joined by a dot, an underscore or a hyphen.
    if (preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $id) !== 1) {
      return sprintf(
        'The %s "%s" is not a stable id: lowercase letters and numbers, joined by dots, underscores or hyphens.',
        $label,
        $id,
      );
    }

    return null;
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
        ...$this->checkNpcs($map, $workspace),
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
   * Checks a map's NPC block against what the runtime actually does with it.
   *
   * `NpcManager::configure()` skips an entry silently when it is not an
   * array, has no name, or has no coordinates; ignores a wander area or
   * directional map that is not an array; treats any movement but `wander`
   * as fixed; clamps wander dimensions to 1; and throws on a duplicate id.
   * `Player::interact()` talks to the NPC on the faced tile, and
   * `MapManager::canMoveTo()` refuses a tile an NPC stands on. Every check
   * here follows from one of those, with an error where the authored
   * content cannot happen and a warning where it can but probably not as
   * meant.
   *
   * @param ProjectMap $map The map.
   * @param ProjectWorkspace $workspace The project, for spawns and collisions.
   * @return Issue[] The issues found.
   */
  protected function checkNpcs(ProjectMap $map, ProjectWorkspace $workspace): array
  {
    if (! array_key_exists('npcs', $map->data) || $map->data['npcs'] === null) {
      return [];
    }

    if (! is_array($map->data['npcs'])) {
      return [Issue::error(
        $map->mapId,
        sprintf('The npcs block is %s, not a list of NPC entries.', get_debug_type($map->data['npcs'])),
        'The game reads npcs as a list; every NPC on this map is dropped until it is one.'
      )];
    }

    $issues = $this->checkNpcIdentities($map);
    $width = $map->getWidth();
    $height = $map->getHeight();
    $anchors = [];

    foreach ($map->data['npcs'] as $index => $entry) {
      $ordinal = is_int($index) ? $index + 1 : strval($index);

      if (! is_array($entry)) {
        $issues[] = Issue::error(
          $map->mapId,
          sprintf('NPC entry %s is %s, not a structured entry.', $ordinal, get_debug_type($entry)),
          'The game skips it. Write it as an array with at least name, x and y.'
        );
        continue;
      }

      $name = is_scalar($entry['name'] ?? null) ? trim(strval($entry['name'])) : '';
      $where = sprintf('%s NPC %s', $map->mapId, $name !== '' ? $name : sprintf('entry %s', $ordinal));

      if ($name === '') {
        $issues[] = Issue::error(
          $where,
          isset($entry['name']) && ! is_scalar($entry['name'])
            ? 'Its name is not text.'
            : 'It has no name.',
          'The game skips an NPC without a name. Give it one.'
        );
      }

      $issues = [
        ...$issues,
        ...$this->checkNpcPlacement($entry, $where, $width, $height, $anchors, $name, $index),
        ...$this->checkNpcSprite($entry, $where, $width),
        ...$this->checkNpcMovement($entry, $where, $width, $height),
        ...$this->checkNpcDirectionalSprites($entry, $where),
        ...$this->checkNpcInteractionShapes($entry, $where),
        ...$this->checkNpcUnknownFields($entry, $where),
      ];
    }

    return [
      ...$issues,
      ...$this->checkNpcCollisions($map, $workspace, $anchors),
    ];
  }

  /**
   * Checks an NPC's coordinates and records its anchor for the collision
   * pass.
   *
   * @param array<string, mixed> $entry The NPC entry.
   * @param string $where Where it lives.
   * @param int $width The map width.
   * @param int $height The map height.
   * @param array<int, array{x: int, y: int, name: string, where: string, wanders: bool, width: int}> $anchors Anchors seen so far, appended to.
   * @param string $name The NPC's name.
   * @param int|string $index The entry index.
   * @return Issue[] The issues found.
   */
  protected function checkNpcPlacement(array $entry, string $where, int $width, int $height, array &$anchors, string $name, int|string $index): array
  {
    if (! isset($entry['x'], $entry['y'])) {
      return [Issue::error(
        $where,
        'It has no coordinates.',
        'The game skips an NPC without both x and y. Place it on a tile.'
      )];
    }

    foreach (['x', 'y'] as $axis) {
      $value = $entry[$axis];

      if (! is_numeric($value)) {
        return [Issue::error(
          $where,
          sprintf('Its %s is %s, not a number.', $axis, get_debug_type($value)),
          'The game reads it as 0, which is almost never the tile you meant.'
        )];
      }

      if (intval($value) != $value) {
        return [Issue::error(
          $where,
          sprintf('Its %s is %s, not a whole tile.', $axis, strval($value)),
          'Tiles are whole numbers; the game truncates the fraction.'
        )];
      }
    }

    $x = intval($entry['x']);
    $y = intval($entry['y']);
    $sprite = is_scalar($entry['sprite'] ?? null) ? strval($entry['sprite']) : '@';
    $anchors[] = [
      'x' => $x,
      'y' => $y,
      'name' => $name !== '' ? $name : sprintf('entry %s', is_int($index) ? $index + 1 : $index),
      'where' => $where,
      'wanders' => strval($entry['movement'] ?? 'fixed') === 'wander',
      'width' => ProjectNpc::glyphWidth($sprite),
    ];

    if ($x < 0 || $y < 0 || $x >= $width || $y >= $height) {
      return [Issue::error(
        $where,
        sprintf('It stands at (%d, %d), outside the %d x %d map.', $x, $y, $width, $height),
        'The player can never reach it. Move it onto the map, or grow the map first.'
      )];
    }

    return [];
  }

  /**
   * Checks the base sprite: present as text, visible, and inside the map.
   *
   * @param array<string, mixed> $entry The NPC entry.
   * @param string $where Where it lives.
   * @param int $width The map width.
   * @return Issue[] The issues found.
   */
  protected function checkNpcSprite(array $entry, string $where, int $width): array
  {
    if (! array_key_exists('sprite', $entry)) {
      return [];
    }

    if (! is_scalar($entry['sprite'])) {
      return [Issue::error(
        $where,
        sprintf('Its sprite is %s, not text.', get_debug_type($entry['sprite'])),
        'Write the glyph as a string, with optional <fg=...> style tags.'
      )];
    }

    $sprite = strval($entry['sprite']);

    if (trim(ProjectNpc::strippedGlyph($sprite)) === '') {
      return [Issue::warning(
        $where,
        'Its sprite draws nothing.',
        'The NPC is invisible but still blocks its tile and can be spoken to. Give it a glyph.'
      )];
    }

    $columns = ProjectNpc::glyphWidth($sprite);

    if ($columns > 1 && is_numeric($entry['x'] ?? null) && intval($entry['x']) + $columns > $width) {
      return [Issue::warning(
        $where,
        sprintf('Its %d-column sprite overhangs the right edge of the map.', $columns),
        'The game anchors a wide glyph at its tile and lets it spill right; part of it is drawn off the map.'
      )];
    }

    return [];
  }

  /**
   * Checks the movement mode and the wander area against how the game
   * wanders: only into tiles inside the area, never off the map.
   *
   * @param array<string, mixed> $entry The NPC entry.
   * @param string $where Where it lives.
   * @param int $width The map width.
   * @param int $height The map height.
   * @return Issue[] The issues found.
   */
  protected function checkNpcMovement(array $entry, string $where, int $width, int $height): array
  {
    $issues = [];
    $movement = $entry['movement'] ?? null;

    if ($movement !== null && ! is_string($movement)) {
      $issues[] = Issue::error(
        $where,
        sprintf('Its movement is %s, not text.', get_debug_type($movement)),
        'Use fixed or wander.'
      );
      $movement = null;
    } elseif ($movement !== null && ! in_array($movement, ['fixed', 'wander'], true)) {
      $issues[] = Issue::error(
        $where,
        sprintf('Its movement "%s" is not one the game supports.', $movement),
        'The game knows fixed and wander, and treats anything else as fixed. Patrol routes are not supported.'
      );
    }

    $wanders = $movement === 'wander';

    if (! array_key_exists('wanderArea', $entry)) {
      return $issues;
    }

    $area = $entry['wanderArea'];

    if (! is_array($area)) {
      $issues[] = Issue::warning(
        $where,
        sprintf('Its wander area is %s, not an area.', get_debug_type($area)),
        'The game ignores it and wanders unbounded. Write x, y, width and height, or remove it.'
      );

      return $issues;
    }

    if (! $wanders) {
      $issues[] = Issue::warning(
        $where,
        'It has a wander area but does not wander.',
        'The area is ignored while movement is fixed. Set movement to wander, or remove the area.'
      );
    }

    foreach (['x', 'y', 'width', 'height'] as $key) {
      if (array_key_exists($key, $area) && ! is_numeric($area[$key])) {
        $issues[] = Issue::error(
          $where,
          sprintf('Its wander area %s is %s, not a number.', $key, get_debug_type($area[$key])),
          'The game reads it as 0 (or 1 for a size). Write a whole number.'
        );

        return $issues;
      }
    }

    $areaX = intval($area['x'] ?? 0);
    $areaY = intval($area['y'] ?? 0);
    $areaWidth = intval($area['width'] ?? 1);
    $areaHeight = intval($area['height'] ?? 1);

    if ($areaWidth < 1 || $areaHeight < 1) {
      $issues[] = Issue::warning(
        $where,
        sprintf('Its wander area is %d x %d.', $areaWidth, $areaHeight),
        'The game clamps each side to at least 1 tile. Give it a positive size.'
      );
      $areaWidth = max(1, $areaWidth);
      $areaHeight = max(1, $areaHeight);
    }

    if ($areaX < 0 || $areaY < 0 || $areaX + $areaWidth > $width || $areaY + $areaHeight > $height) {
      $issues[] = Issue::warning(
        $where,
        sprintf(
          'Its wander area (%d, %d) %d x %d extends beyond the %d x %d map.',
          $areaX,
          $areaY,
          $areaWidth,
          $areaHeight,
          $width,
          $height
        ),
        'The game never steps off the map, so the area it actually roams is smaller than authored.'
      );
    }

    if ($wanders && is_numeric($entry['x'] ?? null) && is_numeric($entry['y'] ?? null)) {
      $x = intval($entry['x']);
      $y = intval($entry['y']);

      if ($x < $areaX || $y < $areaY || $x >= $areaX + $areaWidth || $y >= $areaY + $areaHeight) {
        $issues[] = Issue::warning(
          $where,
          sprintf('It starts at (%d, %d), outside its wander area (%d, %d) %d x %d.', $x, $y, $areaX, $areaY, $areaWidth, $areaHeight),
          'The game only steps into tiles inside the area, so it stays put unless a neighbouring tile is inside it.'
        );
      }
    }

    return $issues;
  }

  /**
   * Checks the optional directional sprite map.
   *
   * @param array<string, mixed> $entry The NPC entry.
   * @param string $where Where it lives.
   * @return Issue[] The issues found.
   */
  protected function checkNpcDirectionalSprites(array $entry, string $where): array
  {
    if (! array_key_exists('sprites', $entry)) {
      return [];
    }

    $sprites = $entry['sprites'];

    if (! is_array($sprites)) {
      return [Issue::warning(
        $where,
        sprintf('Its directional sprites are %s, not a map of headings to glyphs.', get_debug_type($sprites)),
        'The game ignores them and always draws the base sprite. Write north, south, east and west keys.'
      )];
    }

    $issues = [];

    foreach ($sprites as $heading => $glyph) {
      if (! is_string($heading) || ! in_array(strtolower($heading), ['north', 'south', 'east', 'west'], true)) {
        $issues[] = Issue::warning(
          $where,
          sprintf('Its directional sprite key "%s" is not a heading.', strval($heading)),
          'The game reads north, south, east and west (either case) and ignores anything else.'
        );
        continue;
      }

      if (! is_scalar($glyph)) {
        $issues[] = Issue::error(
          $where,
          sprintf('Its %s sprite is %s, not text.', strtolower($heading), get_debug_type($glyph)),
          'Write the glyph as a string.'
        );
      }
    }

    return $issues;
  }

  /**
   * Checks the shapes of dialogue, script, conditions and sets -- the
   * runtime filters anything that is not an array out of each, so a wrong
   * shape is content that silently never happens.
   *
   * @param array<string, mixed> $entry The NPC entry.
   * @param string $where Where it lives.
   * @return Issue[] The issues found.
   */
  protected function checkNpcInteractionShapes(array $entry, string $where): array
  {
    $issues = [];
    $shapes = [
      'dialogue' => 'The game drops it; the NPC has nothing to say.',
      'script' => 'The game ignores it and falls back to the dialogue.',
      'conditions' => 'The game drops it; the NPC is shown regardless.',
      'sets' => 'The game drops it; nothing is written after the conversation.',
    ];

    foreach ($shapes as $key => $consequence) {
      if (! array_key_exists($key, $entry)) {
        continue;
      }

      $value = $entry[$key];

      if (! is_array($value)) {
        $issues[] = Issue::error(
          $where,
          sprintf('Its %s is %s, not a list.', $key, get_debug_type($value)),
          $consequence
        );
        continue;
      }

      foreach ($value as $position => $item) {
        if (! is_array($item)) {
          $issues[] = Issue::error(
            $where,
            sprintf('%s entry %s is %s, not a structured entry.', ucfirst($key), is_int($position) ? $position + 1 : strval($position), get_debug_type($item)),
            'The game filters it out silently. Write it as an array.'
          );
        }
      }
    }

    $dialogue = is_array($entry['dialogue'] ?? null) ? $entry['dialogue'] : [];
    $script = is_array($entry['script'] ?? null) ? array_filter($entry['script'], 'is_array') : [];

    if ($script !== [] && array_filter($dialogue, 'is_array') !== []) {
      $issues[] = Issue::warning(
        $where,
        'It has both a script and dialogue.',
        'The game runs the script and never shows the dialogue. Remove one, or move the lines into the script.'
      );
    }

    $isVariantList = false;

    foreach ($dialogue as $page) {
      if (is_array($page) && (isset($page['lines']) || isset($page['script']) || isset($page['conditions']))) {
        $isVariantList = true;
        break;
      }
    }

    foreach ($dialogue as $position => $page) {
      if (! is_array($page)) {
        continue;
      }

      $ordinal = is_int($position) ? $position + 1 : strval($position);

      if ($isVariantList) {
        if (! isset($page['lines']) && ! isset($page['script']) && ! isset($page['conditions'])) {
          $issues[] = Issue::error(
            $where,
            sprintf('Dialogue entry %s is a plain page inside a list of variants.', $ordinal),
            'The game reads it as a variant with nothing to say. Move its text into a variant\'s lines.'
          );
          continue;
        }

        foreach (['lines', 'script', 'sets', 'conditions'] as $key) {
          if (array_key_exists($key, $page) && ! is_array($page[$key])) {
            $issues[] = Issue::error(
              $where,
              sprintf('Dialogue variant %s has %s that is %s, not a list.', $ordinal, $key, get_debug_type($page[$key])),
              'The game drops it.'
            );
          }
        }

        foreach ((array) ($page['lines'] ?? []) as $lineIndex => $line) {
          if (! is_array($line)) {
            $issues[] = Issue::error(
              $where,
              sprintf('Dialogue variant %s line %s is not a page.', $ordinal, is_int($lineIndex) ? $lineIndex + 1 : strval($lineIndex)),
              'The game filters it out. Write it as an array with text.'
            );
          } elseif (trim(strval(is_scalar($line['text'] ?? null) ? $line['text'] : '')) === '') {
            $issues[] = Issue::warning(
              $where,
              sprintf('Dialogue variant %s line %s has no text.', $ordinal, is_int($lineIndex) ? $lineIndex + 1 : strval($lineIndex)),
              'The game shows an empty box.'
            );
          }
        }

        $issues = [...$issues, ...$this->checkStateWrites((array) ($page['sets'] ?? []), $where)];
        continue;
      }

      if (trim(strval(is_scalar($page['text'] ?? null) ? $page['text'] : '')) === '') {
        $issues[] = Issue::warning(
          $where,
          sprintf('Dialogue page %s has no text.', $ordinal),
          'The game shows an empty box.'
        );
      }
    }

    return [...$issues, ...$this->checkStateWrites(is_array($entry['sets'] ?? null) ? $entry['sets'] : [], $where)];
  }

  /**
   * Reports fields the runtime does not read. They are preserved by the
   * editor, so this is a warning, not a loss.
   *
   * @param array<string, mixed> $entry The NPC entry.
   * @param string $where Where it lives.
   * @return Issue[] The issue, if there is one.
   */
  protected function checkNpcUnknownFields(array $entry, string $where): array
  {
    $unknown = array_values(array_filter(
      array_keys($entry),
      static fn(string|int $key): bool => ! in_array($key, ProjectNpc::KNOWN_FIELDS, true)
    ));

    if ($unknown === []) {
      return [];
    }

    return [Issue::warning(
      $where,
      sprintf('It carries the field%s %s, which the game does not read.', count($unknown) === 1 ? '' : 's', implode(', ', array_map(strval(...), $unknown))),
      'The editor preserves them untouched. Remove them if they were meant to do something.'
    )];
  }

  /**
   * Checks where NPCs stand against each other, against event tiles and
   * spawn tiles.
   *
   * The game refuses to move onto a tile an NPC stands on, so a fixed NPC
   * on an event tile makes that event unreachable; a wanderer only blocks
   * it while there. Two NPCs on one tile: `npcAt` finds the first, so the
   * second can never be spoken to while both stand still.
   *
   * @param ProjectMap $map The map.
   * @param ProjectWorkspace $workspace The project.
   * @param array<int, array{x: int, y: int, name: string, where: string, wanders: bool, width: int}> $anchors The NPC anchors.
   * @return Issue[] The issues found.
   */
  protected function checkNpcCollisions(ProjectMap $map, ProjectWorkspace $workspace, array $anchors): array
  {
    $issues = [];
    $definitions = (array) ($map->data['events'] ?? []);
    $spawns = $this->spawnTilesOn($map, $workspace);

    foreach ($anchors as $index => $anchor) {
      foreach ($anchors as $otherIndex => $other) {
        if ($otherIndex <= $index) {
          continue;
        }

        if ($anchor['x'] === $other['x'] && $anchor['y'] === $other['y']) {
          $issues[] = $anchor['wanders'] || $other['wanders']
            ? Issue::warning(
              $anchor['where'],
              sprintf('It starts on the same tile (%d, %d) as NPC %s.', $anchor['x'], $anchor['y'], $other['name']),
              'Only one can be spoken to until the wanderer moves off.'
            )
            : Issue::error(
              $anchor['where'],
              sprintf('It shares tile (%d, %d) with NPC %s.', $anchor['x'], $anchor['y'], $other['name']),
              'The game finds the first NPC on a tile; the other can never be spoken to. Move one.'
            );
        } elseif (
          $anchor['width'] > 1
          && $anchor['y'] === $other['y']
          && $other['x'] > $anchor['x']
          && $other['x'] < $anchor['x'] + $anchor['width']
        ) {
          $issues[] = Issue::warning(
            $anchor['where'],
            sprintf('Its %d-column sprite covers the tile of NPC %s at (%d, %d).', $anchor['width'], $other['name'], $other['x'], $other['y']),
            'The wide glyph is drawn over the neighbour. Leave a column between them.'
          );
        }
      }

      $marker = $map->getEventSymbol($anchor['x'], $anchor['y']);

      if (trim($marker) !== '' && isset($definitions[$marker])) {
        $class = is_array($definitions[$marker]) ? strval($definitions[$marker]['class'] ?? '') : '';
        $classLabel = $class !== '' ? sprintf(' (%s)', basename(str_replace('\\', '/', $class))) : '';
        $issues[] = $anchor['wanders']
          ? Issue::warning(
            $anchor['where'],
            sprintf('It starts on event marker %s%s at (%d, %d).', $marker, $classLabel, $anchor['x'], $anchor['y']),
            'The player cannot step onto that tile while the NPC stands there, so the event cannot fire until it wanders off.'
          )
          : Issue::error(
            $anchor['where'],
            sprintf('It stands on event marker %s%s at (%d, %d).', $marker, $classLabel, $anchor['x'], $anchor['y']),
            'The player can never step onto that tile, so the event never fires. Move the NPC or the marker.'
          );
      }

      foreach ($spawns as $spawn) {
        if ($spawn['x'] === $anchor['x'] && $spawn['y'] === $anchor['y']) {
          $issues[] = Issue::warning(
            $anchor['where'],
            sprintf('It stands on %s at (%d, %d).', $spawn['label'], $anchor['x'], $anchor['y']),
            'The player arrives on top of it and cannot speak to it until stepping off. Move one of them.'
          );
        }
      }
    }

    // An NPC on a solid tile is not reported: the game refuses the tile
    // because the NPC stands there, and the player speaks to it from a
    // neighbour, exactly as on a floor tile -- an interactive sign placed
    // over the map's own sign glyph is the ordinary case, not a mistake.
    return $issues;
  }

  /**
   * Returns the tiles on a map where the player is placed: the game's
   * starting position and every transfer that arrives here.
   *
   * @param ProjectMap $map The map.
   * @param ProjectWorkspace $workspace The project.
   * @return array<int, array{x: int, y: int, label: string}> The tiles.
   */
  protected function spawnTilesOn(ProjectMap $map, ProjectWorkspace $workspace): array
  {
    $spawns = [];
    $positions = $workspace->systemDatabase->getField('startingPositions');
    $start = is_array($positions) ? ($positions['player'] ?? null) : null;

    if (
      is_array($start)
      && strval($start['destinationMap'] ?? '') === $map->mapId
      && is_array($start['spawnPoint'] ?? null)
      && is_numeric($start['spawnPoint']['x'] ?? null)
      && is_numeric($start['spawnPoint']['y'] ?? null)
    ) {
      $spawns[] = [
        'x' => intval($start['spawnPoint']['x']),
        'y' => intval($start['spawnPoint']['y']),
        'label' => "the player's starting tile",
      ];
    }

    foreach ($workspace->maps as $source) {
      foreach ((array) ($source->data['events'] ?? []) as $marker => $definition) {
        if (! is_array($definition)) {
          continue;
        }

        $data = (array) ($definition['data'] ?? []);
        $spawnPoint = $data['spawnPoint'] ?? null;

        if (
          strval($data['destinationMap'] ?? '') !== $map->mapId
          || ! is_array($spawnPoint)
          || ! is_numeric($spawnPoint['x'] ?? null)
          || ! is_numeric($spawnPoint['y'] ?? null)
        ) {
          continue;
        }

        $spawns[] = [
          'x' => intval($spawnPoint['x']),
          'y' => intval($spawnPoint['y']),
          'label' => sprintf('the arrival tile of %s event %s', $source->mapId, strval($marker)),
        ];
      }
    }

    return $spawns;
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

      $where = sprintf(
        '%s NPC %s',
        $map->mapId,
        is_scalar($npc['name'] ?? null) && trim(strval($npc['name'])) !== ''
          ? trim(strval($npc['name']))
          : sprintf('entry %s', is_int($index) ? $index + 1 : strval($index))
      );

      if (array_key_exists('id', $npc) && ! is_scalar($npc['id']) && $npc['id'] !== null) {
        $issues[] = Issue::error(
          $where,
          sprintf('Its id is %s, not text.', get_debug_type($npc['id'])),
          'A stable id is a short slug like gate-guard. Recreate it in the editor, which assigns one.'
        );
        continue;
      }

      $id = trim(strval($npc['id'] ?? ''));

      if ($id === '') {
        $issues[] = Issue::warning(
          $where,
          'It has no stable id, so a move_route cannot target it.',
          'Select it in NPC mode and press Enter on the "No stable id" row to assign one from its name.'
        );
        continue;
      }

      if ($id !== strval($npc['id'])) {
        $issues[] = Issue::warning(
          $where,
          sprintf('Its id "%s" has surrounding whitespace.', strval($npc['id'])),
          'The game trims it when matching; write it without the spaces so routes and diagnostics agree.'
        );
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
    // Every inventory definition, not only the consumables: a quest may ask
    // for a weapon, and a prerequisite may name any of them.
    $items = $this->inventoryCatalog?->ids() ?? [];
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
        ...$this->checkObjectives($quest, $where, $workspace->mapIds, $items, $foes, $known),
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
   * @param array<string, string[]> $known Known project references.
   * @return Issue[] The issues found.
   */
  protected function checkObjectives(
    ProjectQuest $quest,
    string $where,
    array $mapIds,
    array $items,
    array $foes,
    array $known,
  ): array
  {
    $objectives = $quest->getObjectives();
    $issues = [];

    if ($objectives === []) {
      return [Issue::error($where, 'The quest has no objectives.', 'It could never be completed.')];
    }

    foreach ($objectives as $objective) {
      $type = strval($objective['type'] ?? '');
      $target = trim(strval($objective['target'] ?? ''));
      $rawRevealedDescription = $objective['revealedDescription'] ?? '';
      $revealedDescription = is_string($rawRevealedDescription) ? trim($rawRevealedDescription) : '';
      $revealConditions = $objective['revealConditions'] ?? [];

      if (! is_string($rawRevealedDescription)) {
        $issues[] = Issue::error($where, 'An objective has malformed revealed text.', 'Use a string for revealedDescription.');
      }

      if (! is_array($revealConditions)) {
        $issues[] = Issue::error($where, 'An objective has malformed reveal conditions.', 'Use a list of world conditions.');
      }

      if ($revealedDescription !== '' && (! is_array($revealConditions) || $revealConditions === [])) {
        $issues[] = Issue::error(
          $where,
          'An objective has revealed text but no reveal conditions.',
          'Add world conditions so the journal knows when that text is safe to show.'
        );
      }

      if (is_array($revealConditions) && $revealConditions !== [] && $revealedDescription === '') {
        $issues[] = Issue::error(
          $where,
          'An objective has reveal conditions but no revealed text.',
          'Add the more specific journal text, or remove the unused conditions.'
        );
      }

      if (is_array($revealConditions)) {
        $issues = [
          ...$issues,
          ...$this->checkConditions($revealConditions, $where . ' objective reveal', $known),
        ];
      }

      if ($target === '') {
        $issues[] = Issue::error($where, sprintf('A "%s" objective names no target.', $type), 'It can never advance.');
        continue;
      }

      $missing = match ($type) {
        'reach_map' => ! in_array($target, $mapIds, true) ? 'map' : null,
        'collect' => $items !== [] && $this->inventoryCatalog?->has($target) === false ? 'item' : null,
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

      if ($type === 'knowledge') {
        $issues = [...$issues, ...$this->checkKnowledgeCommand($command, $where)];
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
          ...$this->checkEventCue($definition['cue'] ?? null, $where, $known),
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

        $issues = [...$issues, ...$this->checkChestLoot($data, $where, $known)];
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
          ...$this->checkStateWrites((array) ($npc['sets'] ?? []), $where),
        ];
      }
    }

    return $issues;
  }

  /**
   * Checks what a chest gives out.
   *
   * A chest names its loot by kind: an inventory definition, a skill, or an
   * amount of gold or experience. An inventory reference that resolves to
   * nothing opens a chest that gives nothing, which looks exactly like a
   * chest an author meant to leave empty.
   *
   * @param array<string, mixed> $data The trigger's data.
   * @param string $where Where it lives.
   * @param array<string, string[]> $known What the project defines.
   * @return Issue[] The issues found.
   */
  protected function checkChestLoot(array $data, string $where, array $known): array
  {
    if (! array_key_exists('lootType', $data) && ! array_key_exists('loot', $data)) {
      return [];
    }

    // A chest may declare its kind as the runtime enum or as its value.
    $rawType = $data['lootType'] ?? '';
    $lootType = strtolower(trim($rawType instanceof \BackedEnum ? strval($rawType->value) : (is_scalar($rawType) ? strval($rawType) : '')));
    $loot = $data['loot'] ?? null;
    $issues = [];
    $kinds = ['item', 'gold', 'experience', 'skill', 'spell', 'weapon', 'armor', 'accessory'];

    if ($lootType !== '' && ! in_array($lootType, $kinds, true)) {
      $issues[] = Issue::error(
        $where,
        sprintf('Its loot type "%s" is not one the game knows.', $lootType),
        'Choose item, gold, experience, skill, spell, weapon, armor, or accessory.'
      );

      return $issues;
    }

    if ($loot === null || (is_string($loot) && trim($loot) === '')) {
      // An unconfigured chest is a chest an author has not finished, not a
      // broken reference.
      return $issues;
    }

    if (in_array($lootType, ['gold', 'experience'], true)) {
      if (! is_numeric($loot)) {
        $issues[] = Issue::error(
          $where,
          sprintf('It gives %s "%s", which is not an amount.', $lootType, strval(is_scalar($loot) ? $loot : get_debug_type($loot))),
          'Write a whole number.'
        );
      }

      return $issues;
    }

    if ($loot instanceof \BackedEnum) {
      $loot = $loot->value;
    }

    if (! is_scalar($loot)) {
      $issues[] = Issue::error(
        $where,
        sprintf('Its loot is %s, not a reference.', get_debug_type($loot)),
        'Choose the loot from the picker.'
      );

      return $issues;
    }

    // Only inventory loot is resolved here. A chest may also give a skill
    // or a spell, and the same check would apply, but skill loot is not this
    // boundary's subject and enabling it now would report existing project
    // content that this work is not permitted to change. The gap is recorded
    // for whoever owns that content rather than left to be rediscovered.
    return [...$issues, ...match ($lootType) {
      'skill', 'spell' => [],
      // No kind declared: the runtime reads the loot as an item.
      default => $this->checkReference(strval($loot), 'inventory', 'item', $where, $known),
    }];
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
    $allowedRootFields = ['class', 'data', 'conditions', 'sets', 'whenBlocked', 'cue'];

    foreach (array_diff(array_keys($definition), $allowedRootFields) as $field) {
      $issues[] = Issue::error(
        $where,
        sprintf('ScriptEventTrigger uses unsupported root field "%s".', $field),
        'Use class, data, conditions, sets, whenBlocked, or cue.'
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

  /**
   * Checks optional player-visible event guidance without conflating it with
   * the event-layer marker used by map data.
   *
   * @return Issue[] The issues found.
   */
  protected function checkEventCue(mixed $cue, string $where, array $known): array
  {
    if ($cue === null) {
      return [];
    }

    if (! is_array($cue)) {
      return [Issue::error($where, 'Its event cue is malformed.', 'Store cue symbol and color in a cue array.')];
    }

    $issues = [];
    foreach (array_diff(array_keys($cue), ['symbol', 'color', 'conditions']) as $field) {
      $issues[] = Issue::error($where, sprintf('Its event cue uses unsupported field "%s".', $field), 'Use symbol, color, or conditions.');
    }

    $issues = [
      ...$issues,
      ...$this->checkConditions((array) ($cue['conditions'] ?? []), $where . ' cue', $known),
    ];

    $rawSymbol = $cue['symbol'] ?? '';
    if (! is_string($rawSymbol)) {
      return [...$issues, Issue::error($where, 'Its event cue symbol is malformed.', 'Use a string containing one terminal cell.')];
    }

    $symbol = trim($rawSymbol);
    if ($symbol === '') {
      return $issues;
    }

    if (TerminalText::symbolCount($symbol) !== 1 || TerminalText::displayWidth($symbol) !== 1) {
      $issues[] = Issue::error($where, 'Its event cue does not occupy exactly one terminal cell.', 'Choose one single-cell symbol.');
    }

    $rawColor = $cue['color'] ?? 'bright-yellow';
    if (! is_string($rawColor)) {
      return [...$issues, Issue::error($where, 'Its event cue color is malformed.', 'Use a Symfony Console color string.')];
    }

    $color = trim($rawColor);
    try {
      new OutputFormatterStyle($color);
    } catch (InvalidArgumentException) {
      $issues[] = Issue::error(
        $where,
        sprintf('Its event cue uses invalid Symfony Console color "%s".', $color),
        'Use a supported Console color name or color value.'
      );
    }

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
        ...$this->checkStateWrites((array) ($variant['sets'] ?? []), $where),
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

    if ($value === '') {
      return [];
    }

    if ($category === 'inventory' && $this->inventoryCatalog instanceof InventoryCatalog) {
      // An inventory reference is whatever the runtime will resolve: a
      // stable id, the current display name, or a declared alias. Only
      // something that names nothing -- or names two things -- is wrong.
      if ($this->inventoryCatalog->has($value)) {
        return [];
      }

      if ($this->inventoryCatalog->isAmbiguous($value)) {
        return [Issue::error(
          $where,
          sprintf('It names the %s "%s", which more than one definition answers to.', $noun, $value),
          'The runtime refuses an ambiguous reference. Give one of them a distinct name or alias.'
        )];
      }

      return [Issue::error(
        $where,
        sprintf('It names the %s "%s", which does not exist.', $noun, $value),
        'It will silently do nothing at runtime. Choose it from the picker, which stores the stable id.'
      )];
    }

    if (in_array($value, $known[$category] ?? [], true)) {
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
