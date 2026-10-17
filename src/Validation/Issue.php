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
   */
  public function __construct(
    public Severity $severity,
    public string $where,
    public string $message,
    public string $hint = '',
    public ?string $code = null,
  )
  {
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
