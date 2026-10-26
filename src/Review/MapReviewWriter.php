<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Review;

use InvalidArgumentException;
use RuntimeException;

/**
 * Writes rendered views into a directory for review: for each view its
 * Terminal picture as `.ans` and as a page of its own, its graphical still as
 * a native-size PNG and, when asked, a scaled one, and one review page that
 * puts every view's Terminal picture and graphical still side by side. The
 * review page carries its stills inside it, so it shows them wherever it is
 * opened or sent on its own.
 */
final class MapReviewWriter
{
    public const string REVIEW_PAGE = 'review.html';

    /** @var list<array{rendering: MapRendering, files: array<string, string>, diagnostics: list<string>}> */
    private array $written = [];

    /**
     * @param float|null $scale The scale of the extra overview still, in (0, 1); null writes the native still alone.
     */
    public function __construct(
        private readonly string $directory,
        private readonly FieldStillRaster $raster,
        private readonly ?float $scale = null,
        private readonly bool $nativeStill = true,
    ) {
        if ($scale !== null && (! is_finite($scale) || $scale <= 0 || $scale >= 1)) {
            throw new InvalidArgumentException('An overview scale is above 0 and below 1, such as 0.5 or 0.25.');
        }
        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('%s cannot be made.', $directory));
        }
    }

    /**
     * Writes one view's pictures.
     *
     * @return array{files: array<string, string>, diagnostics: list<string>} Each file written, by kind (terminal-ans,
     *     terminal-html, png and scaled-png), and what the view could not show.
     */
    public function writeRendering(MapRendering $rendering): array
    {
        $stem = $this->claimStem($rendering->view->createFileStem());
        $files = [
            'terminal-ans' => $stem . '.terminal.ans',
            'terminal-html' => $stem . '.terminal.html',
        ];
        $this->writeFile($files['terminal-ans'], $rendering->terminal->renderAnsi());
        $this->writeFile($files['terminal-html'], $rendering->terminal->renderPage($rendering->view->label));
        $before = count($this->raster->diagnostics);
        if ($this->nativeStill) {
            $files['png'] = $stem . '.png';
            $this->writePng($files['png'], $this->raster->rasterize($rendering->still));
        }
        if ($this->scale !== null) {
            $files['scaled-png'] = sprintf('%s@%sx.png', $stem, rtrim(rtrim(sprintf('%.3F', $this->scale), '0'), '.'));
            $this->writePng($files['scaled-png'], $this->raster->rasterize($rendering->still, $this->scale));
        }
        $diagnostics = [...$rendering->diagnostics, ...array_slice($this->raster->diagnostics, $before)];
        $this->written[] = ['rendering' => $rendering, 'files' => $files, 'diagnostics' => $diagnostics];

        return ['files' => array_map(fn(string $file): string => $this->directory . DIRECTORY_SEPARATOR . $file, $files),
            'diagnostics' => $diagnostics];
    }

    /**
     * Writes the review page: every view written so far, its Terminal picture
     * beside its graphical still, the still shown at the overview scale when
     * there is one and linked to the native one.
     *
     * @return string The page's path.
     */
    public function writeReviewPage(string $title): string
    {
        $escape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $sections = '';
        foreach ($this->written as ['rendering' => $rendering, 'files' => $files, 'diagnostics' => $diagnostics]) {
            $region = $rendering->region;
            $subject = $rendering->view->subject;
            $facts = sprintf('Cells x %d to %d, y %d to %d.', $region['x'], $region['x'] + $region['width'] - 1,
                $region['y'], $region['y'] + $region['height'] - 1);
            if ($subject !== null) {
                $facts .= sprintf(' %s at x %d, y %d facing %s.', $subject->actorId === null ? 'The player' : 'Actor ' . $subject->actorId,
                    $subject->x, $subject->y, strtolower($subject->heading->value));
            }
            $shown = $files['scaled-png'] ?? $files['png'] ?? null;
            $still = $shown === null ? '<p class="note">No graphical still was written.</p>'
                : sprintf('<a href="%s"><img src="%s" alt="%s, graphical"></a>', $escape($files['png'] ?? $shown),
                    $this->createImageSource($shown), $escape($rendering->view->label));
            $notes = $diagnostics === [] ? '' : '<ul class="notes">' . implode('', array_map(
                static fn(string $note): string => '<li>' . $escape($note) . '</li>', $diagnostics)) . '</ul>';
            $sections .= sprintf("<section>\n<h2>%s</h2>\n<p class=\"facts\">%s <a href=\"%s\">Terminal page</a> · <a href=\"%s\">ANSI</a></p>\n%s"
                . "<div class=\"pair\">\n<figure><figcaption>Terminal</figcaption>%s</figure>\n<figure><figcaption>Graphical</figcaption>%s</figure>\n</div>\n</section>\n",
                $escape($rendering->view->label), $escape($facts), $escape($files['terminal-html']), $escape($files['terminal-ans']), $notes,
                $rendering->terminal->renderHtml(), $still);
        }
        $page = "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<title>{$escape($title)}</title>\n<style>"
            . TerminalPicture::renderStyles()
            . 'h2{font-size:15px;margin:24px 0 4px}.facts{margin:0 0 8px;opacity:.8}.facts a{color:inherit}'
            . '.pair{display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start}figure{margin:0;max-width:100%}'
            . 'figcaption{font-size:12px;text-transform:uppercase;letter-spacing:.06em;opacity:.7;margin-bottom:4px}'
            . 'img{display:block;max-width:100%;height:auto;image-rendering:pixelated}.notes{margin:0 0 8px;color:#ffdfa3}'
            . "</style>\n</head>\n<body>\n<h1>{$escape($title)}</h1>\n{$sections}</body>\n</html>\n";
        $this->writeFile(self::REVIEW_PAGE, $page);

        return $this->directory . DIRECTORY_SEPARATOR . self::REVIEW_PAGE;
    }

    /** The still the review page shows, inside the page itself as a PNG data URI. */
    private function createImageSource(string $name): string
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . $name;
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException(sprintf('%s cannot be read back for the review page.', $path));
        }

        return 'data:image/png;base64,' . base64_encode($bytes);
    }

    /** A view's stem, numbered when an earlier view of this writer took it. */
    private function claimStem(string $stem): string
    {
        $taken = array_map(static fn(array $entry): string => basename($entry['files']['terminal-ans'], '.terminal.ans'), $this->written);
        $claimed = $stem;
        for ($number = 2; in_array($claimed, $taken, true); $number++) {
            $claimed = $stem . '-' . $number;
        }

        return $claimed;
    }

    private function writeFile(string $name, string $contents): void
    {
        if (file_put_contents($this->directory . DIRECTORY_SEPARATOR . $name, $contents) === false) {
            throw new RuntimeException(sprintf('%s cannot be written.', $this->directory . DIRECTORY_SEPARATOR . $name));
        }
    }

    private function writePng(string $name, \GdImage $image): void
    {
        if (! imagepng($image, $this->directory . DIRECTORY_SEPARATOR . $name)) {
            throw new RuntimeException(sprintf('%s cannot be written.', $this->directory . DIRECTORY_SEPARATOR . $name));
        }
    }
}
