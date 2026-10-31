<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Session;

use Ichiloto\Editor\Assets\AssetImport;
use Ichiloto\Editor\History\AssetFileCommand;
use InvalidArgumentException;
use RuntimeException;

/**
 * Bringing a file into the project's assets for any picker: an image or a
 * sound ({@see AssetImport}). Each import is one undo step, written at once
 * rather than on Save, as every asset file the Editor writes is
 * ({@see AssetFileCommand}).
 */
trait AssetImportSession
{
    /**
     * Brings a file into the project's assets as one undo step: written at
     * once, not on Save, and removed again by Undo.
     *
     * @param string $destination Where it goes, under the assets: relative to them or absolute. Its extension says its kind.
     * @return array<string, mixed> Its receipt ({@see \Ichiloto\Editor\Assets\AssetImportPlan::describeReceipt()}): kind, asset
     *     path, the facts read from it (an image's width and height; a sound's role, name, format, duration, sample rate
     *     and channels) and whether anything was written. A picker row takes the path, or a sound's name.
     * @throws SessionRefusal When the file cannot be brought in, saying why.
     */
    public function importAsset(string $source, string $destination, bool $replace = false): array
    {
        try {
            $import = AssetImport::plan($this->workspace->projectRoot, $source, $destination, $replace);
        } catch (InvalidArgumentException $error) {
            throw new SessionRefusal($error->getMessage(), previous: $error);
        }
        if ($import->plan !== null) {
            $this->writeAssetPlan(new AssetFileCommand(sprintf('Import %s', $import->path), $import->plan));
        }

        return $import->describeReceipt();
    }

    /** Writes an asset file at once and keeps the write in history, or refuses having written nothing. */
    private function writeAssetPlan(AssetFileCommand $command): void
    {
        try {
            $command->execute();
        } catch (RuntimeException $failure) {
            throw new SessionRefusal($failure->getMessage(), previous: $failure);
        }
        $this->history->record($command);
    }
}
