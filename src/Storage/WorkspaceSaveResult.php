<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Storage;

/** What a Save All wrote, skipped and could not write. */
final class WorkspaceSaveResult
{
    /** How many maps, databases and cutscenes it wrote, as one sentence. */
    public string $summary {
        get => sprintf(
            'Saved %d map%s, %d database%s and %d cutscene%s.',
            $this->savedMaps,
            $this->savedMaps === 1 ? '' : 's',
            $this->savedDatabases,
            $this->savedDatabases === 1 ? '' : 's',
            $this->savedCutscenes,
            $this->savedCutscenes === 1 ? '' : 's',
        );
    }

    /**
     * @param list<string> $skippedRenames Maps left unsaved because saving would move their folder.
     * @param list<string> $failures Each document that could not be written, with why.
     * @param list<string> $warnings What validation noticed in the maps written, which never blocks a save.
     * @param list<string> $backupFailures Files whose backup failed; their save still ran.
     */
    public function __construct(
        public readonly int $savedMaps,
        public readonly int $savedDatabases,
        public readonly int $savedCutscenes,
        public readonly array $skippedRenames,
        public readonly array $failures,
        public readonly array $warnings,
        public readonly array $backupFailures,
    ) {
    }
}
