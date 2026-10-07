<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Closure;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Rendering\Presentation\RendererPresentation;
use Ichiloto\Engine\Rendering\Presentation\RetainedWorldProviderInterface;
use Ichiloto\Engine\Rendering\Presentation\SceneFrameComposer;
use Ichiloto\Engine\Rendering\Presentation\ScenePresentationContext;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererEventType;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;

/**
 * A preview scene drawn by the editor window as the game's field is drawn.
 *
 * The Engine's own pieces do the work, as they do for the game: the scene's
 * presentation context selects the field the composer reads, the composer
 * assembles the frame (the retained world, sprites, viewport, dialogue and
 * covers) with the scene's Terminal text from an isolated Console capture,
 * and the renderer presentation sends it through the relay to the window,
 * one generation at a time, as the window acknowledges each. The host owns
 * only the order of those steps and their cleanup; the clock is the
 * preview's playhead, never another.
 */
final class PreviewSceneHost
{
    private readonly PreviewRendererRelay $relay;
    private readonly RendererClient $client;
    private readonly RendererPresentation $presentation;
    private readonly SceneFrameComposer $composer;
    private bool $ready = false;

    /**
     * @param Closure(): void $renderTerminal Draws the scene's Terminal picture, as the preview's Terminal view draws it.
     * @param Closure(): float $readTime The preview's playhead, in seconds.
     */
    public function __construct(
        private readonly GameScene $scene,
        private readonly PreviewPresentation $dialogue,
        string $assetRoot,
        public readonly RendererGridConfig $grid,
        private readonly Closure $renderTerminal,
        private readonly Closure $readTime,
    ) {
        $this->relay = new PreviewRendererRelay();
        $this->client = new RendererClient($this->relay);
        $this->client->start(new RendererSessionConfig('Cinematic preview', $assetRoot, $grid, RendererProtocolVersion::V2));
        $this->presentation = new RendererPresentation($this->client, $grid);
        $this->composer = new SceneFrameComposer($assetRoot);
    }

    /**
     * One exchange with the window: takes its answers (READY, frame
     * acknowledgements and rejections, as renderer event lines), presents the
     * scene as it is now, and returns what the window should apply, in order.
     *
     * @param list<string> $events
     * @return list<array{type: string, payload: array<string, mixed>}>
     * @throws RendererTransportException When the window reports an error.
     */
    public function exchange(array $events): array
    {
        $this->relay->receive($events);
        $this->client->pump();
        $invalidate = false;
        $expectedGeneration = null;
        foreach ($this->client->drainEvents() as $event) {
            if ($event->type === RendererEventType::READY) {
                $this->ready = true;
            } elseif ($event->type === RendererEventType::FRAME_REJECTED) {
                $invalidate = true;
                $expectedGeneration = max($expectedGeneration ?? 0, $event->expectedGeneration ?? 0);
            } elseif ($event->type === RendererEventType::FRAME_ACK) {
                $this->presentation->acknowledge($event->generation, $event->presented);
            } elseif ($event->type === RendererEventType::ERROR) {
                throw new RendererTransportException('Preview window error: ' . $event->message, $this->relay->getDiagnostics());
            }
        }
        foreach ($this->client->drainFrameAcknowledgements() as $acknowledgement) {
            $this->presentation->acknowledge($acknowledgement->generation, $acknowledgement->presented);
        }
        if ($invalidate) {
            $this->presentation->invalidate(expectedGeneration: $expectedGeneration);
        }
        if ($this->ready) {
            $this->attachContext();
            $composition = $this->composer->composeFrame($this->scene, $this->grid, $this->client->supports(...),
                fn(array $excluded, array $worldLayers) => Console::capturePresentation($this->grid->columns, $this->grid->rows,
                    $this->renderTerminal, $excluded, $worldLayers,
                    $this->scene instanceof RetainedWorldProviderInterface && $this->scene->getPresentationWorld() !== null));
            $this->presentation->presentFrame($composition->prepareFrame($this->presentation), $composition->screenOverlay);
        }

        return $this->relay->collect();
    }

    /** Detaches the scene's presentation context and the dialogue's graphical paging, and closes the relay. */
    public function dispose(): void
    {
        if ($this->scene->getPresentationContext() !== null) {
            $this->scene->setPresentationContext(null);
            if ($this->scene instanceof PreviewGameScene) {
                $this->scene->installFieldEffects(EffectPresentation::TERMINAL);
            }
        }
        $this->dialogue->pageLayout = null;
        $this->client->shutdown();
    }

    /** Selects the graphical field for the composer, with the window's capabilities and the preview's playhead. */
    private function attachContext(): void
    {
        if ($this->scene->getPresentationContext() !== null) {
            return;
        }
        $this->scene->setPresentationContext(new ScenePresentationContext($this->grid, $this->client->supports(...),
            collectPresentations: fn(): array => $this->dialogue->getActivePresentations(), readTime: $this->readTime));
        // The graphical field draws its effects as sprites, as the game's does in a graphical window.
        if ($this->scene instanceof PreviewGameScene) {
            $this->scene->installFieldEffects(EffectPresentation::GRAPHICAL);
        }
        $width = $this->grid->columns * $this->grid->cellWidth;
        $this->dialogue->pageLayout = fn(string $speaker, $context, string $help) => $this->composer->getDialoguePageLayout($speaker, $context, $help, $width);
    }
}
