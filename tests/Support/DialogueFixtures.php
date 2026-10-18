<?php

declare(strict_types=1);

use Ichiloto\Editor\Dialogue\DialogueCatalogFile;
use Ichiloto\Editor\Session\EditorSession;

/** A synthetic catalogue: one actor, one non-actor character, and the labels lines use for them. */
function syntheticDialogueCatalogue(): string
{
    return <<<'PHP'
<?php

// Synthetic dialogue artwork for editor tests.
return [
  'schema' => 'ichiloto.dialogue/1',
  'actors' => [
    'Kaelion' => [
      'portrait' => 'Graphics/Faces/Hero.png',
      'emotions' => [
        'Angry' => ['portrait' => 'Graphics/Faces/HeroAngry.png', 'bust' => 'Graphics/Faces/HeroAngry.png'],
      ],
    ],
  ],
  'resources' => [
    'Mira' => [
      'portrait' => 'Graphics/Faces/Mira.png', // The innkeeper.
      'emotions' => ['Worried' => 'Graphics/Faces/MiraWorried.png'],
    ],
  ],
  'speakers' => [
    'Kaelion' => 'Kaelion',
    'Mom' => 'Mira',
  ],
];
PHP;
}

/** A project holding a dialogue catalogue and its artwork, opened in a session. */
function createDialogueProject(?string $catalogue = null): array
{
    $root = makeTemporaryProject('editor-dialogue-portraits-');
    $file = $root . '/' . DialogueCatalogFile::RELATIVE_PATH;
    mkdir(dirname($file), 0o777, true);
    file_put_contents($file, $catalogue ?? syntheticDialogueCatalogue());
    foreach (['Hero', 'HeroAngry', 'HeroSad', 'Mira', 'MiraWorried'] as $face) {
        writeTilesetTestPng($root . '/assets/Graphics/Faces/' . $face . '.png', 8, 8);
    }

    return [$root, $file, EditorSession::open($root)];
}
