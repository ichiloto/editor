<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Ichiloto\Engine\Core\Campaign\CampaignSession;
use Ichiloto\Engine\IO\SaveCompatibility\ContentMigrationResolution;
use Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityManifest;
use Ichiloto\Engine\IO\SaveManager;
use Ichiloto\Engine\Scenes\Game\GameConfig;
use Ichiloto\Engine\Scenes\Game\GameLoader;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ActorStore;
use Ichiloto\Engine\Util\Stores\ItemStore;
use InvalidArgumentException;

/**
 * The saved campaign a preview starts from: the party, world state, quests
 * and the rest of what a save holds, restored for the preview's own Game as
 * the game restores them before its field exists.
 *
 * Nothing here decodes or restores by itself. The save is read through the
 * Engine's save pipeline ({@see SaveManager::loadSaveFile()}) with the
 * project's compatibility manifest, whose migration classes are resolved
 * only when a save needs them, and its facts are restored by the
 * Engine's {@see CampaignSession}, which the game's own field uses. The save
 * file is only read; the preview keeps the campaign for its life and gives
 * the project state back when it is released.
 */
final class PreviewCampaign
{
    private function __construct(
        public readonly CampaignSession $session,
        public readonly GameConfig $config,
    ) {
    }

    /**
     * Restores a save's campaign for a preview Game. The project's root must
     * be the working directory and the project's actors and items installed
     * ({@see PreviewField}), as they are when the game loads a save.
     *
     * @param string $savePath An absolute path to a readable save file.
     */
    public static function restoreFromSave(PreviewGame $game, string $projectRoot, string $savePath): self
    {
        $path = realpath($savePath);
        if ($path === false || ! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException(sprintf('The preview\'s save could not be read: %s', $savePath));
        }
        $actors = ConfigStore::get(ActorStore::class);
        $items = ConfigStore::get(ItemStore::class);
        if (! $actors instanceof ActorStore || ! $items instanceof ItemStore) {
            throw new \LogicException('A preview restores a campaign with the project\'s actors and items installed.');
        }
        $assetRoot = $projectRoot . '/assets';
        $game->bindGameLoader(new GameLoader($game, $actors, $items, $assetRoot));
        // This Game's own save pipeline, never the process-wide one: it reads the one file it is given. Whoever
        // starts the Editor decides whether the project's own classes can be loaded (the Console registers them),
        // so the manifest resolves a migration class only when the save needs that step; where one is needed and
        // cannot be loaded, the Engine refuses the save, naming the class.
        $manifest = SaveCompatibilityManifest::fromProjectRoot($projectRoot,
            migrationResolution: ContentMigrationResolution::WHEN_REQUIRED);
        $config = (new SaveManager($game, compatibilityManifest: $manifest))->loadSaveFile($path)->config;
        $config->party->inventory->bindItemStore($items);

        return new self(CampaignSession::restore($game, $config, $assetRoot), $config);
    }

    /** Gives back the project state the campaign replaced; releasing twice is harmless. */
    public function release(): void
    {
        $this->session->release();
    }
}
