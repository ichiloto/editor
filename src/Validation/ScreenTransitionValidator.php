<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Rendering\ScreenTransitionCatalog;
use Throwable;

/**
 * Checks the project's screen transitions exactly as the Engine reads them,
 * including treatments no scene selects yet.
 */
final class ScreenTransitionValidator
{
    private const string WHERE = 'assets/' . ScreenTransitionCatalog::FILE;

    /** What happens at runtime while a transition cannot be used. */
    private const string FALLBACK = 'Until this is fixed, graphical renderers enter the scene with a direct cut; the terminal intro is unaffected.';

    /** @return Issue[] */
    public function validate(ProjectWorkspace $workspace): array
    {
        $assetRoot = $workspace->projectRoot . DIRECTORY_SEPARATOR . 'assets';

        try {
            $catalog = ScreenTransitionCatalog::load($assetRoot);
        } catch (Throwable $failure) {
            return [Issue::error(
                self::WHERE,
                sprintf('The transitions could not be read: %s', $failure->getMessage()),
                'Return a ScreenTransitionCatalog whose keys match their treatment ids and whose battle names one of them. ' . self::FALLBACK,
            )];
        }

        if ($catalog === null) {
            return [];
        }

        try {
            $catalog->validateAssets($assetRoot);
        } catch (Throwable $failure) {
            return [Issue::error(
                self::WHERE,
                $failure->getMessage(),
                'Point each image at a PNG inside assets/ that the renderers can load. ' . self::FALLBACK,
            )];
        }

        return [];
    }
}
