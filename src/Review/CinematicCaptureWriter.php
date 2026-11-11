<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Review;

use Ichiloto\Editor\Cutscenes\Preview\CinematicCapture;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use RuntimeException;

/**
 * Writes a captured cinematic into a folder as numbered PNG frames,
 * `frame-000000.png` upwards at the capture's frame rate, drawn by the
 * scene frame raster ({@see SceneFrameRaster}), with `capture.json` beside
 * them: the rate, each frame's capture and scene times and what waited on
 * the player, the waits acknowledged, whether the run finished, the
 * session's own log and what the frames could not draw. A video encoder
 * reads the frames in order at that rate, for example
 * `ffmpeg -framerate <fps> -i frame-%06d.png`.
 *
 * The folder must be empty or new, so a capture never mixes with another.
 */
final class CinematicCaptureWriter
{
    public const string MANIFEST = 'capture.json';

    public function __construct(private readonly string $directory, private readonly SceneFrameRaster $raster)
    {
    }

    /**
     * Records the capture to its end and writes it.
     *
     * @return array{frames: int, manifest: string, finished: bool, diagnostics: list<string>}
     * @throws RuntimeException When the folder holds files or a frame cannot be written.
     */
    public function write(CinematicCapture $capture, RendererGridConfig $grid): array
    {
        $this->prepareDirectory();
        $frames = [];
        foreach ($capture->record() as $frame) {
            $path = sprintf('%s/frame-%06d.png', $this->directory, $frame->index);
            $image = $this->raster->rasterize($frame->composition, $grid);
            if (! imagepng($image, $path)) {
                throw new RuntimeException(sprintf('Frame %d could not be written to %s.', $frame->index, $path));
            }
            $frames[] = ['index' => $frame->index, 'file' => basename($path), 'captureSeconds' => round($frame->captureSeconds, 6),
                'sceneSeconds' => round($frame->sceneSeconds, 6), 'waiting' => $frame->waitKind];
        }
        $session = $capture->session;
        $manifest = [
            'cinematic' => $session->definition()->id,
            'fps' => $capture->fps,
            'readingSeconds' => $capture->readingSeconds,
            'size' => ['width' => $grid->columns * $grid->cellWidth, 'height' => $grid->rows * $grid->cellHeight],
            'status' => $session->status(),
            'finished' => $session->isFinished() && ! $capture->gaveUp,
            'failure' => $session->failure(),
            'acknowledged' => $capture->acknowledged,
            'frames' => $frames,
            'log' => $session->log(),
            'diagnostics' => [...$session->getDiagnostics(), ...$this->raster->diagnostics],
        ];
        $file = $this->directory . '/' . self::MANIFEST;
        if (file_put_contents($file, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            | JSON_THROW_ON_ERROR) . "\n") === false) {
            throw new RuntimeException(sprintf('The capture manifest could not be written to %s.', $file));
        }

        return ['frames' => count($frames), 'manifest' => $file, 'finished' => $manifest['finished'], 'diagnostics' => $manifest['diagnostics']];
    }

    private function prepareDirectory(): void
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0o777, true) && ! is_dir($this->directory)) {
            throw new RuntimeException(sprintf('The capture folder %s could not be made.', $this->directory));
        }
        if (array_diff(scandir($this->directory) ?: [], ['.', '..']) !== []) {
            throw new RuntimeException(sprintf('The capture folder %s holds files; choose an empty or new folder.', $this->directory));
        }
    }
}
