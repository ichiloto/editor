<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\Cutscenes\Preview\PreviewRendererRelay;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\RendererPresentation;
use Ichiloto\Engine\Rendering\Presentation\SceneFrameComposer;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererEventType;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;

/**
 * The editing canvas of one open map, drawn by the editor window as the field
 * draws it. Its own renderer client talks to the window through a relay, as a
 * preview field's does: each exchange takes the window's answers (READY,
 * frame acknowledgements, text measurements), composes the map as it stands
 * in memory through the Engine's frame composer, and returns what the window
 * should apply, frames and text requests alike, in order. Signs keep their
 * prepared labels across edits while their placements are unchanged. The host
 * lives while its map is open at this size; closing it releases everything.
 */
final class AuthoringCanvasHost
{
    /** The most cells the canvas draws across and down: the renderer grid's own limits. */
    public const int MAX_COLUMNS = 512;
    public const int MAX_ROWS = 256;

    private readonly PreviewRendererRelay $relay;
    private readonly RendererClient $client;
    private readonly RendererPresentation $presentation;
    private readonly SceneFrameComposer $composer;
    private readonly AuthoringSignPlaybacks $signs;
    public readonly RendererGridConfig $grid;
    private bool $ready = false;
    private ?AuthoringMapSource $source = null;

    public function __construct(private readonly ProjectMap $map, public readonly string $sessionId)
    {
        $this->grid = self::getGrid($map);
        $this->relay = new PreviewRendererRelay();
        $this->client = new RendererClient($this->relay);
        $this->client->start(new RendererSessionConfig('Authoring canvas', $map->getAssetRoot(), $this->grid, RendererProtocolVersion::V2));
        $this->presentation = new RendererPresentation($this->client, $this->grid);
        $this->composer = new SceneFrameComposer($map->getAssetRoot());
        $this->signs = new AuthoringSignPlaybacks($map->getAssetRoot());
    }

    /**
     * Whether this canvas draws a map as it stands: that reading of it, at
     * its size. A map read afresh (a file set written at once reloads the
     * project) or resized needs a canvas of its own.
     */
    public function drawsMap(ProjectMap $map): bool
    {
        return $this->map === $map && $this->grid == self::getGrid($map);
    }

    /** The grid a map's canvas draws: one renderer cell per map cell, within the renderer's limits. */
    public static function getGrid(ProjectMap $map): RendererGridConfig
    {
        return new RendererGridConfig(min(self::MAX_COLUMNS, $map->getWidth()), min(self::MAX_ROWS, $map->getHeight()),
            FieldViewport::TILE_SIZE, FieldViewport::TILE_SIZE);
    }

    /**
     * One exchange with the window, presenting the map at a variant selection and preview time.
     *
     * @param list<string> $events The window's answers, as renderer event lines.
     * @param array<string, string> $variants
     * @return list<array{type: string, payload: array<string, mixed>}>
     * @throws RendererTransportException When the window reports an error.
     */
    public function exchange(array $events, array $variants, float $seconds): array
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
                throw new RendererTransportException('Editing canvas error: ' . $event->message, $this->relay->getDiagnostics());
            }
        }
        foreach ($this->client->drainFrameAcknowledgements() as $acknowledgement) {
            $this->presentation->acknowledge($acknowledgement->generation, $acknowledgement->presented);
        }
        if ($invalidate) {
            $this->presentation->invalidate(expectedGeneration: $expectedGeneration);
        }
        if ($this->ready) {
            $this->source = AuthoringMapSource::compose($this->map, $variants, $seconds, $this->signs, $this->client,
                $this->grid->columns, $this->grid->rows, $this->source);
            // The canvas draws its glyphs itself; the composer's Terminal text is the empty picture of the grid.
            $composition = $this->composer->composeFrame($this->source, $this->grid, $this->client->supports(...),
                fn(array $excluded, array $worldLayers) => Console::capturePresentation($this->grid->columns, $this->grid->rows,
                    static function (): void {}, $excluded, $worldLayers, true));
            $this->presentation->presentFrame($composition->prepareFrame($this->presentation), $composition->screenOverlay);
        }

        return $this->relay->collect();
    }

    /** What the last composed picture depends on and could not draw, once one has been composed. */
    public function getSource(): ?AuthoringMapSource
    {
        return $this->source;
    }

    /** Releases the signs' prepared labels and closes the renderer client, as closing the map does. */
    public function dispose(): void
    {
        $this->signs->releaseAll();
        $this->client->shutdown();
    }
}
