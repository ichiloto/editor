<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Actors;

use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\Cutscenes\Source\SourceNode;
use Ichiloto\Editor\Cutscenes\Source\SourcePreservationRefusal;
use Ichiloto\Editor\Cutscenes\Source\SourceUnreadable;
use Ichiloto\Editor\Database\PhpSourceDocument;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use ReflectionClass;
use ReflectionParameter;

/** Surgical literal edits; never a text-wide replacement of character names. */
final class ActorReferenceSource
{
    /** @param mixed $payload The evaluated file; its classes name the constructors a returned catalog is read with. */
    public static function rewrite(string $source, array $changes, mixed $payload = null): string
    {
        try { $document = PhpArraySourceDocument::parse($source); }
        catch (SourceUnreadable) {
            return self::rewriteReturnedConstructor($source, $changes, ActorReferenceInventory::getComparableValue($payload));
        }
        return self::rewriteArray($document, $source, $changes);
    }

    private static function rewriteArray(PhpArraySourceDocument $document, string $source, array $changes): string
    {
        $edits = [];
        foreach ($changes as $change) {
            $path = $change['path'];
            $entry = $document->entryAt($path);
            if ($entry === null || $entry->keyIsOpaque) {
                throw new SourcePreservationRefusal('Actor reference at ' . implode('.', $path) . ' is not an editable literal.');
            }
            if ($change['kind'] === 'key' || $change['kind'] === 'speaker') {
                $tokens = \PhpToken::tokenize('<?php ' . substr($source, $entry->start, $entry->value->start - $entry->start));
                $keyToken = null;
                foreach ($tokens as $token) {
                    if ($token->is([T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) { continue; }
                    $keyToken = $token;
                    break;
                }
                if ($keyToken === null || ! $keyToken->is(T_CONSTANT_ENCAPSED_STRING)) {
                    throw new SourcePreservationRefusal('Actor reference key is not a string literal.');
                }
                $start = $entry->start + $keyToken->pos - strlen('<?php ');
                $edits[] = [$start, $start + strlen($keyToken->text), var_export($change['kind'] === 'speaker' ? 'actor' : $change['target'], true)];
            }
            if ($change['kind'] !== 'key') {
                if ($entry->value->kind !== SourceNode::SCALAR) {
                    throw new SourcePreservationRefusal('Actor reference at ' . implode('.', $path) . ' is an expression or variable; refusing to flatten it.');
                }
                $edits[] = [$entry->value->start, $entry->value->end, var_export($change['target'], true)];
            }
        }
        return $document->withEdits($edits)->source;
    }

    /** Presentation constructors retain imports, comments, argument order and unrelated expressions. */
    private static function rewriteReturnedConstructor(string $source, array $changes, mixed $value): string
    {
        $depth = 0;
        $start = $end = null;
        foreach (\PhpToken::tokenize($source) as $token) {
            if ($token->is(T_RETURN) && $depth === 0) {
                $start = $token->pos + strlen($token->text);
                continue;
            }
            if ($token->text === ';' && $depth === 0 && $start !== null) { $end = $token->pos; break; }
            if (in_array($token->text, ['(', '[', '{'], true)) { $depth++; }
            if (in_array($token->text, [')', ']', '}'], true)) { $depth--; }
        }
        if ($start === null || $end === null) { throw new SourceUnreadable('Cannot locate the returned presentation constructor.'); }
        $expression = substr($source, $start, $end - $start);
        $rewritten = self::rewriteConstructor($expression, $changes, $value);
        // Preserve whitespace/comments surrounding the constructor itself.
        $original = PhpSourceDocument::parse('<?php return [' . $expression . '];')->entrySource(0);
        if ($original === null) { throw new SourceUnreadable('Presentation must return a supported constructor literal.'); }
        $offset = strpos($expression, $original);
        return substr($source, 0, $start) . substr_replace($expression, $rewritten, $offset, strlen($original)) . substr($source, $end);
    }

    /** Arguments are matched against the Engine constructor, so new catalog fields need no copied signature here. */
    private static function rewriteConstructor(string $expression, array $changes, mixed $value): string
    {
        $document = PhpSourceDocument::parse('<?php return [' . $expression . '];');
        $class = is_array($value) ? ($value['__class'] ?? null) : null;
        $written = $document->entryClasses()[0] ?? '';
        if (! is_string($class) || ! class_exists($class)
            || strcasecmp(self::getShortName($written), self::getShortName($class)) !== 0) {
            throw new SourcePreservationRefusal('Unsupported presentation constructor; refusing to guess its arguments.');
        }
        $parameters = array_map(static fn(ReflectionParameter $parameter): string => $parameter->getName(),
            new ReflectionClass($class)->getConstructor()?->getParameters() ?? []);
        // The menu theme derives every property from its single data argument.
        $isMenu = $class === MenuPresentationCatalog::class;
        $groups = [];
        foreach ($changes as $change) {
            if ($isMenu) { array_unshift($change['path'], 'data'); }
            $argument = array_shift($change['path']);
            $groups[$argument][] = $change;
        }
        foreach ($groups as $argument => $edits) {
            if (! in_array($argument, $parameters, true)) {
                throw new SourcePreservationRefusal("{$class} has no constructor argument {$argument} holding actor references.");
            }
            $literal = $document->getConstructorArgumentSource(0, $argument, $parameters);
            if ($literal === null) { throw new SourcePreservationRefusal('Missing actor reference argument ' . $argument . '.'); }
            $updated = self::rewriteArgument($literal, $edits, $isMenu ? null : ($value[$argument] ?? null), $argument);
            $document = $document->getWithConstructorArgument(0, $argument, $updated, $parameters);
        }
        return $document->entrySource(0) ?? throw new SourcePreservationRefusal('Cannot preserve the presentation constructor.');
    }

    /** A nested constructor, a single string literal or an array literal; never a variable or computed value. */
    private static function rewriteArgument(string $literal, array $edits, mixed $value, string $argument): string
    {
        $tokens = array_values(array_filter(\PhpToken::tokenize('<?php ' . $literal),
            static fn(\PhpToken $token): bool => ! $token->is([T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])));
        if (($tokens[0] ?? null)?->is(T_NEW)) { return self::rewriteConstructor($literal, $edits, $value); }
        $refusal = "Actor references in {$argument} are an expression or variable; refusing to flatten them.";
        if (count($edits) === 1 && $edits[0]['path'] === []) {
            if (count($tokens) !== 1 || ! $tokens[0]->is(T_CONSTANT_ENCAPSED_STRING)) {
                throw new SourcePreservationRefusal($refusal);
            }
            $start = $tokens[0]->pos - strlen('<?php ');
            return substr_replace($literal, var_export($edits[0]['target'], true), $start, strlen($tokens[0]->text));
        }
        $wrapper = '<?php return ';
        try { $document = PhpArraySourceDocument::parse($wrapper . $literal . ';'); }
        catch (SourceUnreadable) { throw new SourcePreservationRefusal($refusal); }
        return substr(self::rewriteArray($document, $wrapper . $literal . ';', $edits), strlen($wrapper), -1);
    }

    private static function getShortName(string $class): string
    {
        return basename(str_replace('\\', '/', $class));
    }

    /** Expected value after the same bounded reference edits, for staged readback. */
    public static function getUpdatedValue(mixed $payload, array $changes): mixed
    {
        $value = ActorReferenceInventory::getComparableValue($payload);
        foreach ($changes as $change) {
            $path = $change['path'];
            $key = array_pop($path);
            $parent =& $value;
            foreach ($path as $part) { $parent =& $parent[$part]; }
            if ($change['kind'] === 'key' || $change['kind'] === 'speaker') {
                $newKey = $change['kind'] === 'speaker' ? 'actor' : $change['target'];
                if ($newKey !== $key && array_key_exists($newKey, $parent)) {
                    throw new SourcePreservationRefusal('Actor reference migration would overwrite key ' . $newKey . '.');
                }
                $copy = [];
                foreach ($parent as $oldKey => $oldValue) {
                    $copy[$oldKey === $key ? $newKey : $oldKey] = $oldKey === $key && $change['kind'] === 'speaker' ? $change['target'] : $oldValue;
                }
                $parent = $copy;
            } else { $parent[$key] = $change['target']; }
            unset($parent);
        }
        return $value;
    }
}
