<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

/**
 * What placing a piece authors. A gameplay placement is the piece whole: its
 * glyphs, the tiles that stand for them and, when asked, its collision. A
 * graphical placement is its art alone, laid over a footprint the map already
 * has: glyphs, their colours and collision stay exactly as authored, so the
 * Terminal and the physical map never change for a graphical edit
 * ({@see PieceArtFootprint}).
 */
enum PieceOwnership: string
{
    case GAMEPLAY = 'gameplay';
    case GRAPHICAL = 'graphical';
}
