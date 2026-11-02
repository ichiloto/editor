<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Review;

use Ichiloto\Engine\Rendering\Launch\RendererExecutableResolverInterface;
use Ichiloto\Engine\Rendering\Presentation\Text\TextSpec;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Text\RendererTextPreparationRequest;
use Ichiloto\Engine\Rendering\Text\RendererTextPreparationState;
use Ichiloto\Engine\Rendering\Text\TextPreparationProviderInterface;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\ProcessRendererTransport;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use RuntimeException;
use Throwable;

/**
 * Project labels, such as a sign's, measured and drawn for a still by the
 * installed graphical renderer itself, so a still's text is the text the game
 * shows: the renderer's windowless label mode (`--prepare-text`) answers the
 * Engine's own text preparation requests over the renderer protocol and writes
 * each prepared label's density-1 raster, guard included, into a directory
 * this session owns. The mode is confirmed (`--prepare-text --check`) before
 * any hello, which a renderer without it would answer with a window.
 *
 * Requests are answered asynchronously, as in play: a composition that asks
 * for labels is composed again once {@see awaitReplies()} has their replies.
 */
final class StillLabelPreparation implements TextPreparationProviderInterface
{
    /** What the renderer's label mode prints when asked whether it exists. */
    public const string CHECK_REPLY = '{"mode":"prepare_text","protocol":2}';

    /** Seconds the renderer has to answer a check or a batch of requests. */
    private const float TIMEOUT = 10.0;

    /** The raster guard the renderer keeps on every side of a label's raster. */
    public const int RASTER_GUARD = 2;

    /** @var list<RendererTextPreparationRequest> */
    private array $requests = [];

    /** How many requests have been made, so a caller can tell a composition asked for none. */
    public private(set) int $requestCount = 0;

    private function __construct(private readonly RendererClient $client, private readonly string $rasterDirectory)
    {
    }

    /**
     * Starts the installed renderer's label mode for a project's assets.
     *
     * @throws RuntimeException When the renderer is not installed, has no label mode, or does not start.
     */
    public static function start(string $assetRoot, RendererExecutableResolverInterface $renderers): self
    {
        try {
            $executable = $renderers->resolve('gpui');
        } catch (Throwable $error) {
            throw new RuntimeException($error->getMessage(), previous: $error);
        }
        self::check($executable);
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ichiloto-still-labels-' . bin2hex(random_bytes(6));
        if (! mkdir($directory, 0o700)) {
            throw new RuntimeException('Cannot create a directory for the renderer\'s label rasters.');
        }
        $client = new RendererClient(new ProcessRendererTransport(new RendererProcessConfig(
            [$executable, '--prepare-text', $directory], startupTimeout: self::TIMEOUT)));
        try {
            $client->start(new RendererSessionConfig('Map review labels', $assetRoot, protocol: RendererProtocolVersion::V2));
            $client->pump();
            if (! $client->supports(RendererSessionConfig::TEXT_LAYOUT)) {
                throw new RuntimeException('The renderer\'s label mode did not offer text layout.');
            }
        } catch (Throwable $error) {
            self::removeDirectory($directory);
            throw new RuntimeException('The renderer\'s label mode did not start: ' . $error->getMessage(), previous: $error);
        }

        return new self($client, $directory);
    }

    public function getTextPreparationSession(): ?object
    {
        return $this->client->getTextPreparationSession();
    }

    public function requestTextPreparation(TextSpec $spec): ?RendererTextPreparationRequest
    {
        $request = $this->client->requestTextPreparation($spec);
        if ($request !== null) {
            $this->requests[] = $request;
            $this->requestCount++;
        }

        return $request;
    }

    public function releaseTextPreparation(RendererTextPreparationRequest $request): void
    {
        $this->client->releaseTextPreparation($request);
    }

    /**
     * Waits for the renderer to answer every request still pending.
     *
     * @throws RuntimeException When the renderer does not answer in time or fails.
     */
    public function awaitReplies(): void
    {
        $deadline = microtime(true) + self::TIMEOUT;
        while (true) {
            $this->client->pump();
            $this->requests = array_values(array_filter($this->requests,
                static fn(RendererTextPreparationRequest $request): bool => $request->state === RendererTextPreparationState::PENDING));
            if ($this->requests === []) {
                return;
            }
            if (microtime(true) > $deadline) {
                throw new RuntimeException('The renderer did not finish preparing labels in time.');
            }
            usleep(2_000);
        }
    }

    /** The PNG bytes of a prepared label's raster, guard included, or null when the renderer wrote none. */
    public function readRaster(string $layoutId): ?string
    {
        $bytes = @file_get_contents($this->rasterDirectory . DIRECTORY_SEPARATOR . bin2hex($layoutId) . '.png');

        return $bytes === false ? null : $bytes;
    }

    /** Ends the renderer's session and removes its rasters. */
    public function dispose(): void
    {
        try {
            if ($this->client->isRunning()) {
                $this->client->shutdown();
            }
        } finally {
            self::removeDirectory($this->rasterDirectory);
        }
    }

    /**
     * Confirms the renderer has its label mode before a hello is sent.
     *
     * @throws RuntimeException When it has none.
     */
    private static function check(string $executable): void
    {
        if (! is_file($executable) || ! is_executable($executable)) {
            throw new RuntimeException('The installed GPUI renderer cannot be launched.');
        }
        $pipes = [];
        $process = proc_open([$executable, '--prepare-text', '--check'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, null, null, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('The installed GPUI renderer cannot be launched.');
        }
        // A renderer without the mode waits for a hello; closing its input ends it before any window opens.
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        $output = '';
        $deadline = microtime(true) + self::TIMEOUT;
        while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
            $output .= (string) stream_get_contents($pipes[1]);
            usleep(5_000);
        }
        $output .= (string) stream_get_contents($pipes[1]);
        if (proc_get_status($process)['running']) {
            proc_terminate($process);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        if (trim($output) !== self::CHECK_REPLY) {
            throw new RuntimeException('The installed GPUI renderer has no windowless label mode; update it with `ichiloto renderer:update`.');
        }
    }

    private static function removeDirectory(string $directory): void
    {
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.png') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
}
