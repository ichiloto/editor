<?php

declare(strict_types=1);

namespace Ichiloto\Editor\IO;

/**
 * Non-blocking terminal input decoder.
 *
 * Drains everything currently buffered on STDIN each poll, tokenizes the
 * bytes into discrete logical events (escape sequences, SGR mouse reports,
 * UTF-8 graphemes, control bytes) and queues them so no keypress is ever
 * dropped — a key-repeat burst yields one token per repeat instead of a
 * single coalesced string.
 *
 * Incomplete escape sequences at the buffer tail are retained between polls
 * so a sequence split across reads is reassembled without ever sleeping. A
 * lone ESC is emitted only after it has survived one full poll with no
 * continuation bytes, which keeps the Escape key responsive (~one frame of
 * latency) without misreading arrow keys as ESC presses.
 */
final class InputDecoder
{
  /**
   * The maximum number of tokens surfaced per poll, as a runaway guard.
   */
  private const int MAX_TOKENS_PER_POLL = 128;

  /**
   * Bytes read from the terminal but not yet tokenized.
   */
  private string $buffer = '';

  /**
   * Whether the current buffer tail already survived a poll unchanged.
   */
  private bool $pendingEscapeHeld = false;

  /**
   * Switches STDIN to non-blocking mode. Call once at boot, after the
   * terminal has been placed in raw/cbreak mode.
   *
   * @return void
   */
  public function attach(): void
  {
    stream_set_blocking(STDIN, false);
  }

  /**
   * Drains STDIN and returns every complete input token available.
   *
   * @return string[] The decoded tokens, oldest first.
   */
  public function poll(): array
  {
    $this->feed((string) stream_get_contents(STDIN));

    return $this->drain();
  }

  /**
   * Appends raw bytes to the decode buffer.
   *
   * @param string $bytes The raw terminal bytes.
   * @return void
   */
  public function feed(string $bytes): void
  {
    if ($bytes === '') {
      return;
    }

    $this->buffer .= $bytes;
    $this->pendingEscapeHeld = false;
  }

  /**
   * Tokenizes the buffered bytes and returns every complete token.
   *
   * @return string[] The decoded tokens, oldest first.
   */
  public function drain(): array
  {
    if ($this->buffer === '') {
      return [];
    }

    $tokens = [];

    while ($this->buffer !== '' && count($tokens) < self::MAX_TOKENS_PER_POLL) {
      $token = $this->extractToken();

      if ($token === null) {
        break;
      }

      $tokens[] = $token;
    }

    return $tokens;
  }

  /**
   * Extracts one complete token from the head of the buffer.
   *
   * @return string|null The token, or null when the buffer holds only an
   *   incomplete sequence that should wait for more bytes.
   */
  private function extractToken(): ?string
  {
    if (! str_starts_with($this->buffer, "\033")) {
      return $this->extractPlainToken();
    }

    // SGR mouse report: \033[<b;x;yM or \033[<b;x;ym
    if (preg_match('/^\033\[<\d+;\d+;\d+[mM]/', $this->buffer, $match) === 1) {
      return $this->consume($match[0]);
    }

    // CSI sequence: \033[ params final-byte (arrows, Del, Shift+Tab, F-keys…)
    if (preg_match('/^\033\[[0-9;?<]*[~A-Za-z]/', $this->buffer, $match) === 1) {
      return $this->consume($match[0]);
    }

    // SS3 sequence: \033O final-byte (application-mode arrows, F1-F4)
    if (preg_match('/^\033O[A-Za-z]/', $this->buffer, $match) === 1) {
      return $this->consume($match[0]);
    }

    // Alt+key: ESC immediately followed by a printable byte that cannot
    // start a sequence.
    if (strlen($this->buffer) >= 2 && ! in_array($this->buffer[1], ['[', 'O'], true)) {
      return $this->consume(substr($this->buffer, 0, 2));
    }

    // The tail is a bare ESC or an incomplete sequence prefix. Hold it for
    // one poll; if no continuation bytes arrive by the next poll, emit the
    // ESC on its own.
    if ($this->pendingEscapeHeld) {
      $this->pendingEscapeHeld = false;
      return $this->consume("\033");
    }

    $this->pendingEscapeHeld = true;

    return null;
  }

  /**
   * Extracts a non-escape token: one UTF-8 grapheme or one control byte.
   *
   * @return string|null The token, or null for an incomplete UTF-8 tail.
   */
  private function extractPlainToken(): ?string
  {
    $byte = $this->buffer[0];

    // Control bytes (Tab, Enter, Ctrl+X, Backspace…) are single tokens.
    if (ord($byte) < 0x20 || $byte === "\x7f") {
      return $this->consume($byte);
    }

    if (preg_match('/^\X/u', $this->buffer, $match) === 1) {
      return $this->consume($match[0]);
    }

    // Incomplete multi-byte UTF-8 tail: wait for the rest, unless it has
    // already waited a poll (then it is junk — drop the byte).
    if ($this->pendingEscapeHeld) {
      $this->pendingEscapeHeld = false;
      $this->consume($byte);
      return $this->extractToken();
    }

    $this->pendingEscapeHeld = true;

    return null;
  }

  /**
   * Removes the given prefix from the buffer and returns it.
   *
   * @param string $token The matched token.
   * @return string The token.
   */
  private function consume(string $token): string
  {
    $this->buffer = substr($this->buffer, strlen($token));

    return $token;
  }
}
