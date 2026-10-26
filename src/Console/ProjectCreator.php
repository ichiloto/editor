<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Console;

use RuntimeException;

/**
 * Creates a project the way `ichiloto new` does, by running it: the console
 * owns what a new project holds, so an editor that offers New Project asks
 * the console rather than scaffolding one of its own.
 */
final readonly class ProjectCreator
{
    /** The battle engines a new project may start with, as `ichiloto new` names them. */
    public const array BATTLE_ENGINES = ['traditional', 'active_time'];

    public function __construct(private ConsoleBinary $console)
    {
    }

    /**
     * Creates a project in a folder that does not exist yet, and returns its root.
     *
     * @param string $title The game's title.
     * @param string $directory The project's folder, which `ichiloto new` creates.
     * @param string|null $hero The first party member's name; the console's default when null.
     * @param string|null $battleEngine One of {@see BATTLE_ENGINES}; the console's default when null.
     * @param bool $install Whether to install the engine with Composer, which playing the game needs.
     * @return string The project root.
     * @throws RuntimeException When the request is refused, or the console cannot create the project, with its words.
     */
    public function createProject(string $title, string $directory, ?string $hero = null, ?string $battleEngine = null, bool $install = false): string
    {
        $title = trim($title);
        $directory = rtrim(trim($directory), DIRECTORY_SEPARATOR);

        if ($title === '') {
            throw new RuntimeException('Give the game a title.');
        }
        if ($directory === '' || ! str_starts_with($directory, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Choose where the project goes.');
        }
        if (! is_dir(dirname($directory))) {
            throw new RuntimeException(sprintf('%s does not exist.', dirname($directory)));
        }
        if (file_exists($directory)) {
            throw new RuntimeException(sprintf('%s already exists; choose a new folder for the project.', $directory));
        }
        if ($battleEngine !== null && ! in_array($battleEngine, self::BATTLE_ENGINES, true)) {
            throw new RuntimeException(sprintf('The battle engine is %s, not %s.', implode(' or ', self::BATTLE_ENGINES), $battleEngine));
        }

        $command = [PHP_BINARY, $this->console->path, 'new', $title, '--directory', $directory, '--no-interaction',
            $install ? '--install' : '--no-install'];
        if ($hero !== null && trim($hero) !== '') {
            $command = [...$command, '--hero', trim($hero)];
        }
        if ($battleEngine !== null) {
            $command = [...$command, '--battle-engine', $battleEngine];
        }

        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('The console could not be started to create the project.');
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        if ($status !== 0 || ! is_file($directory . DIRECTORY_SEPARATOR . 'ichiloto.json')) {
            $message = trim(strip_tags((string) $output));
            throw new RuntimeException($message === '' ? 'The console could not create the project.' : $message);
        }

        return realpath($directory) ?: $directory;
    }
}
