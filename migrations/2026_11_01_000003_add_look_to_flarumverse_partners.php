<?php

use Flarum\Database\Migration;

// How a paired forum looks, from its own about document: favicon, logo and
// brand colour, so its discussions on the Flarumverse page match its site.
return Migration::addColumns('flarumverse_partners', [
    'icon' => ['string', 'length' => 400, 'nullable' => true],
    'logo' => ['string', 'length' => 400, 'nullable' => true],
    'color' => ['string', 'length' => 7, 'nullable' => true],
    'look_checked_at' => ['dateTime', 'nullable' => true],
]);
