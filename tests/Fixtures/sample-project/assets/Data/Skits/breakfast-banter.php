<?php

return [
  'id' => 'breakfast-banter',
  'title' => 'Breakfast Banter',
  'where' => 'happyville/town-center',
  'conditions' => [
    ['type' => 'quest', 'name' => 'breakfast-duty', 'status' => 'active'],
  ],
  'beats' => [
    ['speaker' => 'Liora', 'text' => 'An errand for your mom? Really?'],
    ['speaker' => 'Kaelion', 'text' => 'Nobody outruns Mom before breakfast.'],
  ],
];
