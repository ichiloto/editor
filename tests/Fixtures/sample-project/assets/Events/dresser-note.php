<?php

// A small demo cutscene: reading the note on the dresser.
return [
  ['type' => 'text', 'name' => '', 'text' => 'A note is tucked under the lamp.'],
  ['type' => 'record_event', 'name' => 'read_moms_note'],
  ['type' => 'give_gold', 'amount' => 50],
  [
    'type' => 'branch',
    'conditions' => [['type' => 'item', 'name' => 'S-Potion', 'quantity' => 1]],
    'then' => [
      ['type' => 'text', 'name' => '', 'text' => 'A shiny coin!'],
    ],
  ],
];
