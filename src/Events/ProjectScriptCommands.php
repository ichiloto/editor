<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Events;

use Ichiloto\Editor\Database\PhpDataFile;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandCatalog;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandReference;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandRegistry;
use Throwable;

/**
 * The script commands a project can author: the Engine's registered commands
 * and the project's own, read from `assets/Data/script-commands.php`.
 *
 * The declarations are read in an isolated process and never load the
 * project's handler classes; whether a handler exists is the runtime's to
 * check when the game starts.
 */
final class ProjectScriptCommands
{
    /** The commands the project can author. */
    public readonly ScriptCommandCatalog $catalog;

    /**
     * @param ScriptCommandCatalog|null $catalog The commands; the Engine's alone when omitted.
     * @param string|null $declarationProblem Why the project's declarations could not be used, when they could not.
     */
    public function __construct(
        ?ScriptCommandCatalog $catalog = null,
        public readonly ?string $declarationProblem = null,
    ) {
        $this->catalog = $catalog ?? ScriptCommandCatalog::createEngineCatalog();
    }

    /**
     * Reads a project's declared commands.
     *
     * A file that cannot be read or declares a malformed command leaves the
     * Engine's commands and records why, for validation to report.
     *
     * @param string $projectRoot The project root.
     * @return self
     */
    public static function fromProject(string $projectRoot): self
    {
        $relative = 'assets/' . ScriptCommandRegistry::PROJECT_FILE;
        $path = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relative;

        if (! file_exists($path) && ! is_link($path)) {
            return new self();
        }

        try {
            return new self(ScriptCommandCatalog::fromDeclarations(
                PhpDataFile::evaluateIsolated($path, $projectRoot),
                $relative,
            ));
        } catch (Throwable $exception) {
            return new self(declarationProblem: $exception->getMessage());
        }
    }

    /**
     * Makes these the commands the Engine's script validation accepts and
     * the command editor offers, replacing any earlier project's.
     *
     * @return void
     */
    public function activate(): void
    {
        ScriptCommandRegistry::configure($this->catalog);
    }

    /**
     * Returns the Editor reference category for a resource a command names.
     *
     * @param ScriptCommandReference $reference The resource.
     * @return string The ReferenceCatalog category.
     */
    public static function getReferenceCategory(ScriptCommandReference $reference): string
    {
        return match ($reference) {
            // The item store resolves every inventory definition.
            ScriptCommandReference::ITEM => 'inventory',
            ScriptCommandReference::MUSIC => 'bgm',
            ScriptCommandReference::SOUND => 'sfx',
            ScriptCommandReference::MAP => 'maps',
            ScriptCommandReference::TROOP => 'troops',
            ScriptCommandReference::QUEST => 'quests',
            ScriptCommandReference::ACTOR => 'actors',
        };
    }
}
