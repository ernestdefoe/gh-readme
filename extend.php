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
     * handler calls. Authenticated callers only (we cap rate to keep
     * GitHub's 60/hour-per-IP unauth limit from saturating).
     *
     * Throttling note: ThrottleApi already covers this route for
     * session-authenticated callers. Token-authenticated callers
     * bypass it (Flarum 2 design — see CLAUDE.md §16) but those are
     * the operator's own API tokens; not a guest surface.
     */
    (new Extend\Routes('api'))
        ->post('/gh-readme/fetch', 'gh-readme.fetch', FetchReadmeController::class),
];
