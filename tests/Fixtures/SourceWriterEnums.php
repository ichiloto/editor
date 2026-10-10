<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Tests\Fixtures;

enum SourceWriterBacked: string
{
    case FIRST = 'first';
    case SECOND = 'second';

    public const ALIAS = self::FIRST;
}

enum SourceWriterUnit
{
    case FIRST;
    case SECOND;

    public const ALIAS = self::FIRST;
}

final class SourceWriterConstants
{
    public const FIRST = SourceWriterBacked::FIRST;
    public const CASE_ALIAS = SourceWriterBacked::FIRST;
}
