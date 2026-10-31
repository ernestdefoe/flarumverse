<?php

use Flarum\Database\Migration;

return Migration::addColumns('flarumverse_directory', [
    'logo' => ['string', 'length' => 400, 'nullable' => true],
    'icon' => ['string', 'length' => 400, 'nullable' => true],
    // light | dark | #rrggbb: what the logo was drawn to sit on
    'logo_bg' => ['string', 'length' => 7, 'nullable' => true],
]);
