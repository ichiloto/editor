<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Inspector;

use Ichiloto\Editor\History\GenericCommand;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\Status\StatusLevel;
use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Throwable;

/**
 * A map's kind in the Inspector: the setting it draws (Interior, Exterior,
 * World, Dungeon), one of the project's tilesets, which every tile and piece
 * on the map comes from. The kind is stored as the map data's `tileset`.
 * Changing it on a map that has tiles clears them, on confirmation, since
 * tile ids name places on one tileset's sheets and would show the wrong art
 * on another's. A new map is asked its kind before it is created.
 */
trait MapKindField
{
    /**
     * Asks the kind of the map about to be created; Esc creates nothing. A
     * project without tilesets has no kinds to offer, so its map is created
     * without one.
     */
    private function openNewMapKindPicker(): void
    {
        $kinds = $this->referenceCatalog()->labelsFor('tilesets');
        if ($kinds === []) {
            $this->createNewMap();
            return;
        }
        $this->optionDialogField = ['newMapKind' => true];
        $this->eventOptionDialogMarker = null;
        $this->eventOptionDialogPath = null;
        $this->eventOptionDialogTitle = 'New map kind';
        $this->eventOptionDialogEntries = array_map(static fn(string $id, string $name): array =>
            ['label' => $name, 'value' => $id, 'description' => sprintf('Draws from assets/%s/%s.php.', Tileset::DIRECTORY, $id)],
            array_keys($kinds), $kinds);
        $this->selectedEventOptionIndex = 0;
        $this->dialogFilter->clear();
        $this->isEventOptionDialogOpen = true;
        $this->statusMessage = 'Choose the kind of map to create. Its tiles and pieces come from it.';
        $this->renderSelectionDependentArea();
    }

    /** @return array<string, mixed> The Kind row for the Inspector. */
    private function buildMapKindField(ProjectMap $map): array
    {
        return $this->createMapInspector()->buildMapKindField($map);
    }

    /**
     * Makes the selected map the chosen kind. A map without a kind keeps
     * whatever tiles it has, which now draw from that kind; a map changing
     * from one kind to another with tiles asks first.
     */
    private function chooseMapKind(string $id, string $label): void
    {
        $map = $this->getSelectedMap();
        if (! $map instanceof ProjectMap) {
            return;
        }
        $current = $map->getMapDataField(['tileset']);
        if ($current === $id) {
            $this->setStatus(sprintf('%s\'s kind is already %s.', $map->getDisplayName(), $label));
            $this->renderSelectionDependentArea();
            return;
        }
        $layers = count($map->getTileLayerSources());
        if ($current === null || $layers === 0) {
            $this->changeMapKind($map, $id, $label, false);
            return;
        }

        $currentLabel = $this->referenceCatalog()->labelsFor('tilesets')[(string) $current] ?? (string) $current;
        $this->optionDialogField = ['mapKindChange' => ['id' => $id, 'label' => $label]];
        $this->eventOptionDialogMarker = null;
        $this->eventOptionDialogPath = null;
        $this->eventOptionDialogTitle = sprintf('Change %s\'s kind to %s', $map->getDisplayName(), $label);
        $this->eventOptionDialogEntries = [
            ['label' => 'Cancel', 'value' => 'cancel', 'description' => sprintf('Keep its kind, %s, and its tiles.', $currentLabel)],
            ['label' => sprintf('Clear %d tile %s and change', $layers, $layers === 1 ? 'layer' : 'layers'), 'value' => 'clear',
                'description' => sprintf('Its tiles come from %s and would show the wrong art as %s. Glyphs stay. Ctrl+Z restores them.',
                    $currentLabel, $label)],
        ];
        $this->selectedEventOptionIndex = 0;
        $this->dialogFilter->clear();
        $this->isEventOptionDialogOpen = true;
        $this->statusMessage = sprintf('Confirm the change: it clears the map\'s %s tiles.', $currentLabel);
        $this->renderSelectionDependentArea();
    }

    /** @param array{id: string, label: string} $change */
    private function confirmMapKindChange(array $change, bool $confirmed): void
    {
        $this->closeEventOptionDialog();
        $map = $this->getSelectedMap();
        if (! $confirmed || ! $map instanceof ProjectMap) {
            $this->setStatus('Kind unchanged.');
            return;
        }
        $this->changeMapKind($map, $change['id'], $change['label'], true);
    }

    /**
     * Sets the map's kind, clearing its tile layers and their settings when
     * asked, as one undo step.
     */
    private function changeMapKind(ProjectMap $map, string $id, string $label, bool $clearTiles): void
    {
        $hadKind = $map->hasMapDataField(['tileset']);
        $oldKind = $map->getMapDataField(['tileset']);
        $oldSources = $map->getTileLayerSources();
        $hadSettings = $map->hasMapDataField([MapGraphics::SETTINGS_KEY]);
        $oldSettings = $map->getMapDataField([MapGraphics::SETTINGS_KEY]);
        $apply = static function () use ($map, $id, $clearTiles): void {
            $map->setMapDataField(['tileset'], $id);
            if ($clearTiles) {
                $map->restoreTileLayerSources([]);
                $map->setMapDataField([MapGraphics::SETTINGS_KEY], null);
            }
        };
        try {
            $apply();
        } catch (Throwable $failure) {
            $this->setErrorStatus($failure, 'Kind change');
            $this->renderSelectionDependentArea();
            return;
        }
        $this->recordCommand(new GenericCommand('Kind change', $apply, static function () use ($map, $hadKind, $oldKind, $oldSources, $hadSettings, $oldSettings): void {
            $map->setMapDataField(['tileset'], $hadKind ? $oldKind : null);
            $map->restoreTileLayerSources($oldSources);
            $map->setMapDataField([MapGraphics::SETTINGS_KEY], $hadSettings ? $oldSettings : null);
        }));
        $this->setStatus(sprintf('%s\'s kind is now %s%s; save to keep it.', $map->getDisplayName(), $label,
            $clearTiles ? sprintf(', and its %d tile %s cleared', count($oldSources), count($oldSources) === 1 ? 'layer is' : 'layers are') : ''),
            StatusLevel::INFO);
        $this->renderSelectionDependentArea();
    }
}
