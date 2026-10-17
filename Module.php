<?php
namespace Tify;

use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Form\Fieldset;
use Laminas\Form\Form;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Module\AbstractModule;
use Tify\Form\ConfigForm;
use Tify\Form\SettingsFieldset;
use Tify\Form\SiteSettingsFieldset;

/**
 * Displays IIIF manifests and images with the TIFY viewer.
 *
 * The viewer itself is the view helper $this->tify(). This class wires it into
 * Omeka: the settings forms, the admin item page, and the item and browse pages
 * of themes that predate resource page blocks.
 *
 * @see https://tify.rocks/
 *
 * @copyright Bob Coret, 2026
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */
class Module extends AbstractModule
{
    /**
     * Shared with the modules IIIF Server and Universal Viewer.
     */
    const EXTERNAL_MANIFEST_SETTING = 'iiifserver_manifest_external_property';

    /**
     * The viewer of the admin item page, rendered for the section nav and
     * printed after the page.
     *
     * @var string|null
     */
    protected $adminViewer;

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function install(ServiceLocatorInterface $services)
    {
        $settings = $services->get('Omeka\Settings');
        foreach ($this->getConfig()['tify']['settings'] as $name => $value) {
            if ($settings->get($name) === null) {
                $settings->set($name, $value);
            }
        }
        // Only set when no other module did: the setting is shared, and an
        // existing choice must survive installing this module.
        if ($settings->get(self::EXTERNAL_MANIFEST_SETTING) === null) {
            $settings->set(self::EXTERNAL_MANIFEST_SETTING, 'dcterms:hasFormat');
        }
    }

    public function uninstall(ServiceLocatorInterface $services)
    {
        // The shared external manifest setting is left alone: the other IIIF
        // modules may still use it.
        $connection = $services->get('Omeka\Connection');
        $connection->executeStatement("DELETE FROM `setting` WHERE `id` LIKE 'tify\\_%'");
        $connection->executeStatement("DELETE FROM `site_setting` WHERE `id` LIKE 'tify\\_%'");
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
            \Omeka\Form\SettingForm::class,
            'form.add_elements',
            [$this, 'handleMainSettings']
        );
        $sharedEventManager->attach(
            \Omeka\Form\SettingForm::class,
            'form.add_input_filters',
            [$this, 'handleSettingsFilters']
        );
        $sharedEventManager->attach(
            \Omeka\Form\SiteSettingsForm::class,
            'form.add_elements',
            [$this, 'handleSiteSettings']
        );
        $sharedEventManager->attach(
            \Omeka\Form\SiteSettingsForm::class,
            'form.add_input_filters',
            [$this, 'handleSettingsFilters']
        );

        // Admin item page: a tab of its own.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.show.section_nav',
            [$this, 'handleAdminItemSectionNav']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.show.after',
            [$this, 'handleAdminItemShowAfter']
        );

        // Public pages of themes without resource page blocks.
        $sharedEventManager->attach(
            'Omeka\Controller\Site\Item',
            'view.show.after',
            [$this, 'handleSiteItemShowAfter']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Site\Item',
            'view.browse.after',
            [$this, 'handleSiteItemBrowseAfter']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Site\ItemSet',
            'view.browse.after',
            [$this, 'handleSiteItemSetBrowseAfter']
        );
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $form = $this->getConfigFormInstance();
        $form->setData([
            self::EXTERNAL_MANIFEST_SETTING => $this->getServiceLocator()->get('Omeka\Settings')
                ->get(self::EXTERNAL_MANIFEST_SETTING),
        ]);
        return $renderer->formCollection($form, false);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $form = $this->getConfigFormInstance();
        $form->setData($controller->params()->fromPost());
        if (!$form->isValid()) {
            $controller->messenger()->addFormErrors($form);
            return false;
        }
        $this->getServiceLocator()->get('Omeka\Settings')->set(
            self::EXTERNAL_MANIFEST_SETTING,
            (string) ($form->getData()[self::EXTERNAL_MANIFEST_SETTING] ?? '')
        );
        return true;
    }

    public function handleMainSettings(Event $event): void
    {
        $this->addSettingsElements(
            $event->getTarget(),
            SettingsFieldset::class,
            $this->getServiceLocator()->get('Omeka\Settings'),
            $this->getConfig()['tify']['settings']
        );
    }

    public function handleSiteSettings(Event $event): void
    {
        /** @var \Omeka\Form\SiteSettingsForm $form */
        $form = $event->getTarget();
        $this->addSettingsElements(
            $form,
            SiteSettingsFieldset::class,
            $form->getSiteSettings(),
            $this->getConfig()['tify']['site_settings']
        );
    }

    /**
     * Validates the settings of both forms. Laminas makes selects and
     * multi-checkboxes required by default, so every element is optional.
     */
    public function handleSettingsFilters(Event $event): void
    {
        /** @var \Laminas\InputFilter\InputFilterInterface $inputFilter */
        $inputFilter = $event->getParam('inputFilter');
        $form = $event->getTarget();

        foreach (['tify_color_mode', 'tify_view', 'tify_views', 'tify_placement', 'tify_admin_item_show'] as $name) {
            if ($form->has($name)) {
                $inputFilter->add(['name' => $name, 'required' => false]);
            }
        }

        $inputFilter->add([
            'name' => 'tify_height',
            'required' => false,
            'filters' => [['name' => 'StringTrim']],
            'validators' => [[
                'name' => 'Regex',
                'options' => [
                    'pattern' => '/^(\d+(\.\d+)?(px|em|rem|vh|vw|svh|dvh|%)?)?$/',
                    'messages' => [
                        'regexNotMatch' => 'The height must be a CSS length such as "640px" or "80vh".', // @translate
                    ],
                ],
            ]],
        ]);
        $inputFilter->add([
            'name' => 'tify_url_query_key',
            'required' => false,
            'filters' => [['name' => 'StringTrim']],
            'validators' => [[
                'name' => 'Regex',
                'options' => [
                    'pattern' => '/^[A-Za-z0-9_-]*$/',
                    'messages' => [
                        'regexNotMatch' => 'The URL query key may only contain letters, digits, "-" and "_".', // @translate
                    ],
                ],
            ]],
        ]);
        $inputFilter->add([
            'name' => 'tify_config',
            'required' => false,
            'filters' => [['name' => 'StringTrim']],
            'validators' => [[
                'name' => 'Callback',
                'options' => [
                    'callback' => [$this, 'isJsonObject'],
                    'messages' => [
                        'callbackValue' => 'The additional TIFY options must be a JSON object, for example {"pageLabelFormat": "P (L) / T"}.', // @translate
                    ],
                ],
            ]],
        ]);
    }

    /**
     * True for an empty string or a JSON object; false for anything else,
     * including JSON arrays and scalars.
     */
    public function isJsonObject($value): bool
    {
        $value = trim((string) $value);
        if ($value === '') {
            return true;
        }
        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE && $value[0] === '{';
    }

    public function handleAdminItemSectionNav(Event $event): void
    {
        if (!$this->getServiceLocator()->get('Omeka\Settings')->get('tify_admin_item_show', true)) {
            return;
        }
        $item = $event->getParam('resource');
        if (!$item) {
            return;
        }
        $view = $event->getTarget();
        $this->adminViewer = $view->tify($item);
        if ($this->adminViewer === '') {
            return;
        }
        $sectionNav = $event->getParam('section_nav');
        $sectionNav['tify-section'] = $view->translate('IIIF viewer'); // @translate
        $event->setParam('section_nav', $sectionNav);
    }

    public function handleAdminItemShowAfter(Event $event): void
    {
        if (!$this->adminViewer) {
            return;
        }
        echo '<div id="tify-section" class="section">' . $this->adminViewer . '</div>';
        $this->adminViewer = null;
    }

    public function handleSiteItemShowAfter(Event $event): void
    {
        if (!$this->isPlacementEnabled('after/items')) {
            return;
        }
        $view = $event->getTarget();
        echo $view->tify($view->item);
    }

    public function handleSiteItemBrowseAfter(Event $event): void
    {
        if (!$this->isPlacementEnabled('browse/items')) {
            return;
        }
        $view = $event->getTarget();
        // Items browsed within an item set: show the item set as a whole.
        $itemSetId = $this->getServiceLocator()->get('Application')
            ->getMvcEvent()->getRouteMatch()->getParam('item-set-id');
        if ($itemSetId && isset($view->itemSet)) {
            echo $view->tify($view->itemSet);
        } elseif (!empty($view->items)) {
            echo $view->tify($view->items);
        }
    }

    public function handleSiteItemSetBrowseAfter(Event $event): void
    {
        if (!$this->isPlacementEnabled('browse/item_sets')) {
            return;
        }
        $view = $event->getTarget();
        if (!empty($view->itemSets)) {
            echo $view->tify($view->itemSets);
        }
    }

    /**
     * The automatic placements are for themes without configurable resource
     * page blocks only; other themes place the viewer with the block.
     */
    protected function isPlacementEnabled(string $placement): bool
    {
        $services = $this->getServiceLocator();
        $theme = $services->get('Omeka\Site\ThemeManager')->getCurrentTheme();
        if ($theme && method_exists($theme, 'isConfigurableResourcePageBlocks')
            && $theme->isConfigurableResourcePageBlocks()
        ) {
            return false;
        }
        $placements = $services->get('Omeka\Settings\Site')->get('tify_placement', ['after/items']);
        return in_array($placement, (array) $placements, true);
    }

    /**
     * Copies the elements of a settings fieldset into a settings form, in an
     * element group of their own, with their current values.
     *
     * @param \Omeka\Settings\SettingsInterface $settings
     */
    protected function addSettingsElements(Form $form, string $fieldsetClass, $settings, array $defaults): void
    {
        /** @var Fieldset $fieldset */
        $fieldset = $this->getServiceLocator()->get('FormElementManager')->get($fieldsetClass);

        $form->setOption('element_groups', array_merge(
            $form->getOption('element_groups') ?: [],
            $fieldset->getOption('element_groups') ?: []
        ));

        foreach ($fieldset->getElements() as $element) {
            $name = $element->getName();
            $value = $settings->get($name, $defaults[$name] ?? null);
            if ($element instanceof \Laminas\Form\Element\MultiCheckbox) {
                $element->setOption('use_hidden_element', true);
                $value = array_values(array_filter((array) $value));
            }
            $element->setValue($value);
            $form->add($element);
        }
    }

    protected function getConfigFormInstance(): ConfigForm
    {
        return $this->getServiceLocator()->get('FormElementManager')->get(ConfigForm::class);
    }
}
