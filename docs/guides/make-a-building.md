# Make a Building

This guide takes a building from its art to a door the player walks through:
the art brought into a tileset, the building's piece defined over it, the piece
placed on a town with its collision, and an entrance at its door. It uses the
GUI editor (`ichiloto edit --gui`), which shows the art; the terminal parts work
in the TUI too. The manual's [Pieces](../manual.md#pieces) and
[Map Graphics](../manual.md#map-graphics) sections are the reference.

A building is a stamped tileset piece: its tiles on a tile layer, its terminal
glyphs on a gameplay layer, its physical footprint and the doors its art
paints. The terminal and the graphical field show the same building; collision
belongs to the map, never to the picture.

## Bring In The Art

Art for a building usually arrives as an image of its own, cut into 48-pixel
cells like an RPG Maker B sheet.

1. Open the Database (`F3`), choose **Tilesets** and the tileset of the maps
   the building goes on.
2. In the tileset view, choose **Add art**, then **Choose image…**. If the image
   is not in the project yet, **Import image…** brings it in: pick the file,
   then where under `assets/` it goes.
3. Drag across the building's cells in the image.
4. Choose the sheet tab and click the cell where the building's top-left
   goes. Cells with art are shaded:
   only free cells take the copy, and the rest of the sheet stays as it was.

The copy is written at once; Undo puts the sheet back. The copied cells are
selected under **Define piece**, ready for the next step. A tileset with a free
upper sheet can take the image as that sheet instead: choose it in the
inspector's Sheet row.

## Define The Piece

1. Under **Define piece**, choose **New piece from these cells**. Name it,
   choose the gameplay layer its glyphs go on (usually `buildings`) and the
   tile layer its tiles go on, then **Create piece**.
2. **Glyphs**: click each cell and type the character the terminal shows there,
   in the brush colour, then Enter. Walls, roofs and the door each get their
   own; a blank cell leaves the map's glyph as it is.
3. **Collision**: choose **Add physical footprint**, pick a collision in the
   brush and click the cells the building stands on. Leave the roof's cells
   unchanged so the player can walk behind it.
4. **Doors**: choose **Entered from the front** for a door painted on a wall,
   or **Walked onto** for an open archway, and click the door's cell.
5. Under **Above characters**, drag across the roof's tiles so they draw over
   the player walking behind the building.
6. Save the tileset.

## Place It

1. Open the town, choose the Tiles tool (`⌘2`) and the palette's Pieces tab,
   and take the building.
2. Keep **With its collision** checked, so its footprint comes with it. The map
   must have its own collision; the layer inspector's **Separate collision**
   gives an older map one.
3. Click where its top-left goes. The Game and Terminal views (`F5`) show it
   both ways.

Click any of its cells to see it in the inspector. **Move** picks it up, and a
click puts it down elsewhere with its tiles, glyphs, collision and entrances;
**Remove** takes it away. Both are one undo step.

## Give The Door Its Entrance

1. In the inspector's **Placed piece** section, choose **Add entrance**. The
   entrance goes on the cell in front of the door.
2. In the entrance's inspector, choose the interior as its **Destination Map**,
   then the cell the player arrives on as its **Spawn Point** and the way they
   face as its **Spawn Facing**.
3. On the interior, place the way back out the same way, arriving in front of
   the door.

The canvas marks each door and its entrance cell, red until an entrance stands
there. **Check this map** in the map inspector lists any door still without an
entrance in plain words, with **Show** to go to it.

## See It As The Game Does

Save the map. On a project with a save manifest, saving first asks where saved
games standing on cells the building now makes solid should go. The toolbar's review button writes a review of the map,
both ways side by side, to a folder you choose and opens it;
`ichiloto map:render` writes the same review from the command line, with
`--player X,Y` to stand the player at the door.
