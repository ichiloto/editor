<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\ProjectDirectoryContext;

/**
 * Enemies' terminal sprites as the terminal battle draws them: each read
 * the way the game reads it (`Graphics/Enemies/<imagePath>.txt`), never from
 * outside that folder. Shared by every editor surface that shows or places
 * an enemy on the terminal field.
 */
final readonly class EnemySprites
{
    public function __construct(private string $projectRoot)
    {
    }

    /**
     * One sprite's rows, or why there are none.
     *
     * @return array{lines?: list<string>, issue?: string}
     */
    public function readSprite(mixed $imagePath): array
    {
        if (! is_string($imagePath) || trim($imagePath) === '') {
            return ['issue' => 'No sprite is chosen.'];
        }
        $folder = realpath($this->projectRoot . '/assets/Graphics/Enemies');
        $file = realpath($this->projectRoot . '/assets/Graphics/Enemies/' . $imagePath . '.txt');
        if ($folder === false || $file === false || ! str_starts_with($file, $folder . DIRECTORY_SEPARATOR)) {
            return ['issue' => sprintf('Graphics/Enemies/%s.txt is not a sprite in this project.', $imagePath)];
        }

        return ['lines' => array_values(array_map(strval(...), (array) ProjectDirectoryContext::run(
            $this->projectRoot,
            static fn(): array => (array) graphics('Enemies/' . $imagePath),
        )))];
    }

    /**
     * Every enemy's sprite rows by the name a troop member names it by; an
     * enemy whose sprite cannot be read has none.
     *
     * @return array<string, list<string>>
     */
    public function readByEnemy(?ProjectRecordDatabase $enemies): array
    {
        $sprites = [];
        foreach ($enemies?->getRecords() ?? [] as $record) {
            $sprites[trim(strval($record->get('name')))] = $this->readSprite($record->get('imagePath'))['lines'] ?? [];
        }

        return $sprites;
    }
}
