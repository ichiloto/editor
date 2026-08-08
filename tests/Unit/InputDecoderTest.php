<?php

use Ichiloto\Editor\IO\InputDecoder;

/**
 * Feeds bytes into the decoder and drains tokens without touching STDIN.
 */
function decodeBytes(InputDecoder $decoder, string $bytes): array
{
  $decoder->feed($bytes);

  return $decoder->drain();
}

it('decodes a plain character as one token', function () {
  expect(decodeBytes(new InputDecoder(), 'a'))->toBe(['a']);
});

it('decodes control bytes as single tokens', function () {
  expect(decodeBytes(new InputDecoder(), "\t\x13\x7f"))->toBe(["\t", "\x13", "\x7f"]);
});

it('splits a key-repeat burst into one token per press', function () {
  expect(decodeBytes(new InputDecoder(), "\033[B\033[B\033[B"))
    ->toBe(["\033[B", "\033[B", "\033[B"]);
});

it('splits mixed typed characters into individual tokens', function () {
  expect(decodeBytes(new InputDecoder(), 'jjj'))->toBe(['j', 'j', 'j']);
});

it('decodes shift-modified arrows and shift-tab', function () {
  expect(decodeBytes(new InputDecoder(), "\033[1;2A\033[Z"))
    ->toBe(["\033[1;2A", "\033[Z"]);
});

it('decodes consecutive SGR mouse reports individually', function () {
  expect(decodeBytes(new InputDecoder(), "\033[<32;10;5M\033[<32;11;5M"))
    ->toBe(["\033[<32;10;5M", "\033[<32;11;5M"]);
});

it('decodes the delete key sequence', function () {
  expect(decodeBytes(new InputDecoder(), "\033[3~"))->toBe(["\033[3~"]);
});

it('holds an incomplete escape sequence until more bytes arrive', function () {
  $decoder = new InputDecoder();

  expect(decodeBytes($decoder, "\033["))->toBe([])
    ->and(decodeBytes($decoder, 'B'))->toBe(["\033[B"]);
});

it('emits a lone escape after it survives one poll', function () {
  $decoder = new InputDecoder();

  expect(decodeBytes($decoder, "\033"))->toBe([])
    ->and($decoder->drain())->toBe(["\033"]);
});

it('decodes alt plus key as one token', function () {
  expect(decodeBytes(new InputDecoder(), "\033x"))->toBe(["\033x"]);
});

it('decodes utf-8 graphemes as single tokens', function () {
  expect(decodeBytes(new InputDecoder(), '⚔ab'))->toBe(['⚔', 'a', 'b']);
});

it('keeps tokens ordered across a mixed burst', function () {
  expect(decodeBytes(new InputDecoder(), "x\033[Ay\033[<0;3;4mz"))
    ->toBe(['x', "\033[A", 'y', "\033[<0;3;4m", 'z']);
});
