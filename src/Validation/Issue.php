<?php

namespace Ichiloto\Editor\Validation;

/**
 * Something wrong with a project's content.
 *
 * @package Ichiloto\Editor\Validation
 */
final readonly class Issue
{
  /**
   * @param Severity $severity How much it matters.
   * @param string $where What it is about: a map id, a data file, a quest.
   * @param string $message What is wrong.
   * @param string $hint What to do about it.
   * @param string|null $code Optional stable identity for consumers; never derived from message prose.
   * @param string|null $map The id of the map it is about, when it is about one, so a check of some maps
   *     can select its issues without reading $where.
   * @param list<array{0: int, 1: int}> $cells The map cells it names, when it names any, so an editor can show them.
   */
  public function __construct(
    public Severity $severity,
    public string $where,
    public string $message,
    public string $hint = '',
    public ?string $code = null,
    public ?string $map = null,
    public array $cells = [],
  )
  {
  }

  /**
   * Attributes issues to the map they were found on, keeping a map an issue
   * already names.
   *
   * @param list<self> $issues The issues found on the map.
   * @param string $mapId The map's id.
   * @return list<self> The same issues, each naming its map.
   */
  public static function attributeToMap(array $issues, string $mapId): array
  {
    return array_map(static fn(self $issue): self => $issue->map !== null ? $issue
      : new self($issue->severity, $issue->where, $issue->message, $issue->hint, $issue->code, $mapId, $issue->cells), array_values($issues));
  }

  /**
   * Describes the issue for a client of the editor session.
   *
   * @return array{severity: string, where: string, message: string, hint: string, code: ?string, map: ?string, cells: list<array{0: int, 1: int}>}
   */
  public function describe(): array
  {
    return ['severity' => $this->severity->value, 'where' => $this->where, 'message' => $this->message,
      'hint' => $this->hint, 'code' => $this->code, 'map' => $this->map, 'cells' => $this->cells];
  }

  /**
   * Builds an error.
   *
   * @param string $where What it is about.
   * @param string $message What is wrong.
   * @param string $hint What to do about it.
   * @param string|null $code Optional stable identity for consumers.
   * @return self The issue.
   */
  public static function error(string $where, string $message, string $hint = '', ?string $code = null): self
  {
    return new self(Severity::ERROR, $where, $message, $hint, $code);
  }

  /**
   * Builds a warning.
   *
   * @param string $where What it is about.
   * @param string $message What is wrong.
   * @param string $hint What to do about it.
   * @return self The issue.
   */
  public static function warning(string $where, string $message, string $hint = ''): self
  {
    return new self(Severity::WARNING, $where, $message, $hint);
  }
}
