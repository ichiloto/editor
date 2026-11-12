<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Assets;

use InvalidArgumentException;

/**
 * What one kind of asset must be to come into a project ({@see AssetKind}):
 * which files of that kind the game uses, where under the assets they go,
 * and the value a written file must read back as, so a copy that is not
 * the file that was checked is never kept. {@see AssetImport} runs the
 * same plan, transaction, undo and path safety for every kind.
 */
interface AssetAdmission
{
    public function getKind(): AssetKind;

    /** The largest file of this kind brought in, in bytes. */
    public function getMaxFileBytes(): int;

    /** Why a file larger than {@see getMaxFileBytes()} is not brought in. */
    public function describeTooLarge(string $label): string;

    /**
     * Checks a file's bytes are one the game uses.
     *
     * @param string $label How the file is named in a refusal.
     * @param string $name The name it is given in the assets, whose extension says what it must be.
     * @throws InvalidArgumentException When it is not, saying why.
     */
    public function admit(string $bytes, string $label, string $name): AdmittedAsset;

    /**
     * Checks an asset-relative path is a place a file of this kind goes.
     *
     * @throws InvalidArgumentException When it is not, saying where it goes instead.
     */
    public function checkPath(string $path): void;

    /**
     * What a file at a path is, in the receipt, beyond its path: for a
     * sound, the role and name a field stores.
     *
     * @return array<string, mixed>
     */
    public function describePlace(string $path): array;

    /**
     * The other files under the assets a file at this path would be
     * confused with by the game, by asset-relative path.
     *
     * @return list<string>
     */
    public function findNameClashes(string $assets, string $path): array;

    /** Why a file at this path cannot come in beside one it would be confused with. */
    public function describeNameClash(string $path, string $clash): string;

    /** The value a file's bytes read as, compared before and after a write. */
    public function readValue(string $bytes, string $path): string;

    /** The value a staged file reads back as, by its path on disk. */
    public function readStagedValue(string $staged, string $path): string;
}
