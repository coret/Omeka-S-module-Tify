<?php
namespace Tify;

return [
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'view_helpers' => [
        'invokables' => [
            'tify' => View\Helper\Tify::class,
            'tifyManifestUrl' => View\Helper\TifyManifestUrl::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\ConfigForm::class => Form\ConfigForm::class,
            Form\SettingsFieldset::class => Form\SettingsFieldset::class,
            Form\SiteSettingsFieldset::class => Form\SiteSettingsFieldset::class,
        ],
    ],
    'block_layouts' => [
        'invokables' => [
            'tify' => Site\BlockLayout\Tify::class,
        ],
    ],
    'resource_page_block_layouts' => [
        'invokables' => [
            'tify' => Site\ResourcePageBlockLayout\Tify::class,
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'tify' => [
        // Main settings. Every one of them except the admin toggle can be
        // overridden per site; see the site_settings below.
        'settings' => [
            'tify_height' => '640px',
            'tify_color_mode' => 'auto',
            'tify_views' => 'text, thumbnails, toc, info, export, collection',
            'tify_view' => '',
            'tify_url_query_key' => '',
            'tify_config' => '',
            'tify_admin_item_show' => true,
        ],
        // Site settings. An empty value means "use the main setting", so the
        // defaults are empty except for the placement, which is site-only.
        'site_settings' => [
            'tify_height' => '',
            'tify_color_mode' => '',
            'tify_views' => '',
            'tify_view' => '',
            'tify_url_query_key' => '',
            'tify_config' => '',
            'tify_placement' => ['after/items'],
        ],
    ],
];
