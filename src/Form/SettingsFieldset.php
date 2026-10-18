<?php
namespace Tify\Form;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;

/**
 * The viewer settings of the main settings form.
 *
 * Omeka S 4 shows settings in "element groups" rather than fieldsets, so the
 * module adds these elements to the settings form one by one (see
 * Module::addSettingsElements()); this class only defines them.
 */
class SettingsFieldset extends Fieldset
{
    const ELEMENT_GROUP = 'tify';

    /**
     * Label of the first option of the selects. Empty in the main settings,
     * "use the main setting" in the site settings.
     *
     * @var string|null
     */
    protected $inheritLabel = null;

    public function init(): void
    {
        $this->setOption('element_groups', [
            self::ELEMENT_GROUP => 'TIFY viewer', // @translate
        ]);

        $this
            ->add([
                'name' => 'tify_height',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => self::ELEMENT_GROUP,
                    'label' => 'Height', // @translate
                    'info' => 'CSS height of the viewer, for example "640px", "80vh" or "40em". A number alone is read as pixels.', // @translate
                ],
                'attributes' => [
                    'id' => 'tify_height',
                    'placeholder' => '640px',
                ],
            ])
            ->add([
                'name' => 'tify_color_mode',
                'type' => Element\Select::class,
                'options' => [
                    'element_group' => self::ELEMENT_GROUP,
                    'label' => 'Color mode', // @translate
                    'info' => '"Automatic" follows the color-scheme of the theme, "System" the preference of the browser.', // @translate
                    'empty_option' => $this->inheritLabel,
                    'value_options' => [
                        'auto' => 'Automatic', // @translate
                        'light' => 'Light', // @translate
                        'dark' => 'Dark', // @translate
                        'system' => 'System', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'tify_color_mode',
                ],
            ])
            ->add([
                'name' => 'tify_views',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => self::ELEMENT_GROUP,
                    'label' => 'Panels', // @translate
                    'info' => 'The panels next to the image, in this order, separated by commas. Available: text, search, thumbnails, toc, info, export, collection. Panels the manifest has no content for are left out by TIFY. "search" needs a TIFY build with a search view.', // @translate
                ],
                'attributes' => [
                    'id' => 'tify_views',
                    'placeholder' => 'text, thumbnails, toc, info, export, collection',
                ],
            ])
            ->add([
                'name' => 'tify_view',
                'type' => Element\Select::class,
                'options' => [
                    'element_group' => self::ELEMENT_GROUP,
                    'label' => 'Panel opened at start', // @translate
                    'info' => 'On wide screens the image is always visible next to the panel.', // @translate
                    'empty_option' => $this->inheritLabel ?? 'None: only the image', // @translate
                    'value_options' => $this->viewOptions(),
                ],
                'attributes' => [
                    'id' => 'tify_view',
                ],
            ])
            ->add([
                'name' => 'tify_url_query_key',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => self::ELEMENT_GROUP,
                    'label' => 'URL query key', // @translate
                    'info' => 'When set, for example to "tify", the page, zoom, pan, rotation and open panel are kept in the URL of the page, so a link opens the viewer in the same state. Only letters, digits, "-" and "_". Leave empty to disable.', // @translate
                ],
                'attributes' => [
                    'id' => 'tify_url_query_key',
                    'placeholder' => 'tify',
                    'pattern' => '[A-Za-z0-9_\-]*',
                ],
            ])
            ->add([
                'name' => 'tify_config',
                'type' => Element\Textarea::class,
                'options' => [
                    'element_group' => self::ELEMENT_GROUP,
                    'label' => 'Additional TIFY options (JSON)', // @translate
                    'info' => 'A JSON object with any TIFY option. It is merged over the settings above; each key replaces the whole value, nested objects are not merged.', // @translate
                    'documentation' => 'https://github.com/coret/Omeka-S-module-Tify#additional-tify-options-json',
                ],
                'attributes' => [
                    'id' => 'tify_config',
                    'rows' => 6,
                    'placeholder' => '{"pageLabelFormat": "P (L) / T"}',
                ],
            ])
        ;

        $this->addSpecificElements();
    }

    /**
     * Elements that only exist in the main settings.
     */
    protected function addSpecificElements(): void
    {
        $this->add([
            'name' => 'tify_admin_item_show',
            'type' => Element\Checkbox::class,
            'options' => [
                'element_group' => self::ELEMENT_GROUP,
                'label' => 'Show the viewer on the admin item page', // @translate
                'info' => 'Adds a "IIIF viewer" tab to the item page in the admin.', // @translate
            ],
            'attributes' => [
                'id' => 'tify_admin_item_show',
            ],
        ]);
    }

    protected function viewOptions(): array
    {
        // In the site settings the empty option means "use the main setting",
        // so "none" needs its own value there.
        $none = $this->inheritLabel === null ? [] : ['none' => 'None: only the image']; // @translate
        return $none + [
            'text' => 'Full text', // @translate
            'search' => 'Search', // @translate
            'thumbnails' => 'Pages', // @translate
            'toc' => 'Contents', // @translate
            'info' => 'Info', // @translate
            'export' => 'Export', // @translate
            'collection' => 'Collection', // @translate
        ];
    }
}
