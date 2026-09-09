<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Inertia
|--------------------------------------------------------------------------
|
| Only the keys this project needs to override. The service provider merges
| its own config underneath, so everything not named here keeps the package
| default and will follow it across upgrades.
|
*/

return [

    'pages' => [

        'ensure_pages_exist' => false,

        'paths' => [

            // Capital P. This project's pages live in resources/js/Pages, and
            // resources/js/app.jsx globs './Pages/**/*.jsx'. Inertia's own
            // default is the lowercase 'js/pages', which still resolves on a
            // case-insensitive filesystem like macOS and does not on Linux —
            // so every assertInertia()->component() assertion passed locally
            // and failed the moment CI ran the suite on Linux.
            resource_path('js/Pages'),

        ],

        // Named alongside `paths` because this whole `pages` key replaces the
        // package's, rather than merging into it.
        'extensions' => [

            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',

        ],

    ],

];
