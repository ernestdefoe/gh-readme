<?php

/*
 * This file is part of ernestdefoe/gh-readme.
 *
 * Copyright (c) Ernest Defoe.
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace Ernestdefoe\GhReadme;

use Ernestdefoe\GhReadme\Api\FetchReadmeController;
use Flarum\Extend;

return [
    (new Extend\Frontend('forum'))
        ->css(__DIR__ . '/less/forum.less')
        ->js(__DIR__ . '/js/dist/forum.js'),

    (new Extend\Frontend('admin'))
        ->css(__DIR__ . '/less/admin.less')
        ->js(__DIR__ . '/js/dist/admin.js'),

    new Extend\Locales(__DIR__ . '/locale'),

    /*
     * POST /api/gh-readme/fetch — proxy endpoint the composer paste
     * handler calls. Registered members only; private repos for admins
     * only, and members are rate-limited in the controller (core's flood
     * control does NOT cover this route).
     */
    (new Extend\Routes('api'))
        ->post('/gh-readme/fetch', 'gh-readme.fetch', FetchReadmeController::class),
];
