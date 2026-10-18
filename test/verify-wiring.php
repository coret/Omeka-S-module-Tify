<?php
/**
 * Checks that this module's Omeka-facing parts are wired the way the rest of
 * it assumes.
 *
 * This script exercises the real classes against the real Omeka S the module
 * is installed into — configuration, forms, settings validation, block
 * layouts, manifest resolution and the rendered viewer markup — and needs no
 * database and no HTTP server, so it can run as a release gate. Omeka's
 * representations and the few view helpers that need a database are replaced
 * by small stand-ins.
 *
 *   php test/verify-wiring.php
 *   OMEKA_PATH=/path/to/omeka-s php test/verify-wiring.php
 *
 * Exits non-zero when anything failed.
 *
 * @copyright Bob Coret, 2026
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

// ---------------------------------------------------------------- locating Omeka

$modulePath = dirname(__DIR__);
$moduleVersion = parse_ini_file($modulePath . '/config/module.ini')['version'] ?? '';
$omekaPath = getenv('OMEKA_PATH') ?: null;
if (!$omekaPath) {
    $candidate = dirname($modulePath, 2);
    if (is_file($candidate . '/application/Module.php')) {
        $omekaPath = $candidate;
    }
}
if (!$omekaPath || !is_file($omekaPath . '/application/Module.php')) {
    fwrite(STDERR, "Cannot find the Omeka S installation this module belongs to.\n"
        . "Run it as: OMEKA_PATH=/path/to/omeka-s php test/verify-wiring.php\n");
    exit(2);
}

define('OMEKA_PATH', $omekaPath);
require OMEKA_PATH . '/vendor/autoload.php';
require OMEKA_PATH . '/application/Module.php';

spl_autoload_register(function ($class) use ($modulePath) {
    if (0 === strpos($class, 'Tify\\')) {
        $file = $modulePath . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
require $modulePath . '/Module.php';

// ------------------------------------------------------------------- harness

class Verify
{
    public $passed = 0;
    public $failed = 0;

    public function section($title)
    {
        printf("\n%s\n", $title);
    }

    public function check($label, $ok, $detail = '')
    {
        $ok ? $this->passed++ : $this->failed++;
        printf("  %-5s %s%s\n", $ok ? 'ok' : 'FAIL', $label, '' === $detail ? '' : "  ($detail)");
    }

    public function summary()
    {
        printf("\n%d passed, %d failed\n", $this->passed, $this->failed);
        return $this->failed ? 1 : 0;
    }
}

// ---------------------------------------------------------------- stand-ins

/**
 * A settings store, readable like Omeka's settings view helpers.
 */
class FakeSettings
{
    public $values;

    public function __construct(array $values = [])
    {
        $this->values = $values;
    }

    public function __invoke($name, $default = null)
    {
        return $this->get($name, $default);
    }

    public function get($name, $default = null)
    {
        return array_key_exists($name, $this->values) ? $this->values[$name] : $default;
    }

    public function set($name, $value)
    {
        $this->values[$name] = $value;
    }
}

class FakeStatus
{
    public $site = true;

    public function __invoke()
    {
        return $this;
    }

    public function isSiteRequest()
    {
        return $this->site;
    }
}

class FakeValue
{
    protected $type;
    protected $content;
    protected $linked;

    public function __construct($type, $content, $linked = null)
    {
        [$this->type, $this->content, $this->linked] = [$type, $content, $linked];
    }

    public function type()
    {
        return $this->type;
    }

    public function uri()
    {
        return $this->content;
    }

    public function value()
    {
        return $this->content;
    }

    public function valueResource()
    {
        return $this->linked;
    }
}

/**
 * Representations without an API adapter behind them. Built without their
 * constructor; only the methods the module calls are answered.
 */
class FakeItem extends Omeka\Api\Representation\ItemRepresentation
{
    public $fakeId;
    public $fakeMedia = [];
    public $fakeValues = [];

    public function resourceName()
    {
        return 'items';
    }

    public function id()
    {
        return $this->fakeId;
    }

    public function media()
    {
        return $this->fakeMedia;
    }

    public function value($term, array $options = [])
    {
        return $this->fakeValues[$term] ?? [];
    }

    public function values()
    {
        $values = [];
        foreach ($this->fakeValues as $term => $list) {
            $values[$term] = ['values' => $list];
        }
        return $values;
    }
}

class FakeMedia extends Omeka\Api\Representation\MediaRepresentation
{
    public $fakeId;
    public $fakeItem;
    public $fakeMediaType;
    public $fakeRenderer = 'file';

    public function resourceName()
    {
        return 'media';
    }

    public function id()
    {
        return $this->fakeId;
    }

    public function item()
    {
        return $this->fakeItem;
    }

    public function mediaType()
    {
        return $this->fakeMediaType;
    }

    public function renderer()
    {
        return $this->fakeRenderer;
    }
}

function fakeItem(int $id, array $media = [], array $values = []): FakeItem
{
    $item = (new ReflectionClass(FakeItem::class))->newInstanceWithoutConstructor();
    $item->fakeId = $id;
    $item->fakeValues = $values;
    foreach ($media as $i => $mediaType) {
        $m = (new ReflectionClass(FakeMedia::class))->newInstanceWithoutConstructor();
        $m->fakeId = $id * 100 + $i;
        $m->fakeItem = $item;
        $m->fakeMediaType = $mediaType;
        $item->fakeMedia[] = $m;
    }
    return $item;
}

/**
 * A PhpRenderer with the real Laminas helpers and the module's own, plus
 * stand-ins for the helpers that need Omeka's services.
 */
function makeView(FakeSettings $settings, FakeSettings $siteSettings, FakeStatus $status, $iiifServer = true)
{
    global $modulePath;

    $view = new Laminas\View\Renderer\PhpRenderer();
    $resolver = new Laminas\View\Resolver\TemplatePathStack();
    $resolver->addPath($modulePath . '/view');
    $view->setResolver($resolver);

    $helpers = $view->getHelperPluginManager();
    $helpers->setService('setting', $settings);
    $helpers->setService('siteSetting', $siteSettings);
    $helpers->setService('status', $status);
    $helpers->setService('identity', function () {
        return null;
    });
    $helpers->setService('userSetting', function ($name, $default = null) {
        return $default;
    });
    $helpers->setService('translate', function ($text) {
        return $text;
    });
    // Omeka versions module assets by the module version.
    $moduleVersion = $GLOBALS['moduleVersion'];
    $helpers->setService('assetUrl', function ($file, $module = null, $override = false, $versioned = true) use ($moduleVersion) {
        return '/modules/' . $module . '/asset/' . $file . ($versioned ? '?v=' . $moduleVersion : '');
    });
    if ($iiifServer) {
        $helpers->setService('iiifUrl', function ($resource) {
            if (is_array($resource)) {
                return 'https://omeka.example/iiif/set/' . implode(',', array_map(fn ($r) => $r->id(), $resource));
            }
            return 'https://omeka.example/iiif/3/' . $resource->id() . '/manifest';
        });
    }
    $helpers->setInvokableClass('tify', Tify\View\Helper\Tify::class);
    $helpers->setInvokableClass('tifyManifestUrl', Tify\View\Helper\TifyManifestUrl::class);
    return $view;
}

function optionsOf(string $html): ?array
{
    if (!preg_match('/data-tify-options="([^"]*)"/', $html, $m)) {
        return null;
    }
    return json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true);
}

$v = new Verify();
$config = require $modulePath . '/config/module.config.php';

// ------------------------------------------------------------- configuration

$v->section('Configuration');

$ini = parse_ini_file($modulePath . '/config/module.ini');
$v->check('module.ini names the module Tify', 'Tify' === ($ini['name'] ?? null));
$v->check('module.ini has a version', (bool) preg_match('/^\d+\.\d+\.\d+$/', $ini['version'] ?? ''));
$v->check('module.ini declares the licence', 'AGPL-3.0-or-later' === ($ini['license'] ?? null));
$v->check('the composer licence matches', 'AGPL-3.0-or-later' === json_decode(file_get_contents($modulePath . '/composer.json'), true)['license']);
$v->check('the view helper is registered', ($config['view_helpers']['invokables']['tify'] ?? null) === Tify\View\Helper\Tify::class);
$v->check('the site page block is registered', ($config['block_layouts']['invokables']['tify'] ?? null) === Tify\Site\BlockLayout\Tify::class);
$v->check('the resource page block is registered', ($config['resource_page_block_layouts']['invokables']['tify'] ?? null) === Tify\Site\ResourcePageBlockLayout\Tify::class);
foreach (['tify.js', 'tify.css', 'LICENSE', 'VERSION', 'translations/nl.json'] as $file) {
    $v->check("TIFY ships $file", is_file($modulePath . '/asset/vendor/tify/' . $file));
}
$version = trim((string) @file_get_contents($modulePath . '/asset/vendor/tify/VERSION'));
$v->check('the bundled tify.js is the version in VERSION', false !== strpos((string) file_get_contents($modulePath . '/asset/vendor/tify/tify.js', false, null, 0, 200), 'TIFY v' . $version), $version);

// -------------------------------------------------------------------- forms

$v->section('The settings forms build, in a group of their own');

$main = new Tify\Form\SettingsFieldset();
$main->init();
$site = new Tify\Form\SiteSettingsFieldset();
$site->init();
$names = fn ($fieldset) => array_map(fn ($e) => $e->getName(), array_values($fieldset->getElements()));

$v->check('every main setting has an element', [] === array_diff(array_keys($config['tify']['settings']), $names($main)), implode(', ', $names($main)));
$v->check('every site setting has an element', [] === array_diff(array_keys($config['tify']['site_settings']), $names($site)), implode(', ', $names($site)));
$v->check('the admin toggle is a main setting only', !$site->has('tify_admin_item_show'));
$v->check('the placement is a site setting only', !$main->has('tify_placement'));
$v->check('all elements are in the module group', [] === array_filter($main->getElements(), fn ($e) => 'tify' !== $e->getOption('element_group')));
$v->check('the site selects offer "use the main setting"', 'Use the main setting' === $site->get('tify_color_mode')->getOption('empty_option'));
$v->check('the site start panel can still be set to none', isset($site->get('tify_view')->getValueOptions()['none']));
$v->check('the main start panel has none as its empty option', !isset($main->get('tify_view')->getValueOptions()['none']));

$v->check('the external manifest setting is the one shared with IIIF Server',
    'iiifserver_manifest_external_property' === Tify\Module::EXTERNAL_MANIFEST_SETTING);

$v->section('The settings are validated on save');

$module = new Tify\Module();
$form = new Laminas\Form\Form();
foreach ($main->getElements() as $element) {
    $form->add($element);
}
$module->handleSettingsFilters(new Laminas\EventManager\Event('form.add_input_filters', $form, ['inputFilter' => $form->getInputFilter()]));

function validates($form, array $data)
{
    $form->setData($data);
    return $form->isValid();
}

$valid = [
    'tify_height' => '80vh', 'tify_color_mode' => 'dark', 'tify_views' => 'info, thumbnails',
    'tify_view' => 'info', 'tify_url_query_key' => 'tify', 'tify_config' => '{"zoom": 1.2}',
    'tify_admin_item_show' => '1',
];
$v->check('a complete valid form passes', validates($form, $valid), json_encode($form->getMessages()));
$v->check('empty values pass: everything is optional', validates($form, ['tify_height' => '', 'tify_color_mode' => 'auto', 'tify_config' => '']), json_encode($form->getMessages()));
$v->check('a plain number is a valid height', validates($form, ['tify_height' => '500'] + $valid));
$v->check('a height that is not a CSS length is refused', !validates($form, ['tify_height' => '100; color: red'] + $valid));
$v->check('a query key with other characters is refused', !validates($form, ['tify_url_query_key' => 'ti fy'] + $valid));
$v->check('invalid JSON is refused', !validates($form, ['tify_config' => '{"zoom": }'] + $valid));
$v->check('a JSON array is refused', !validates($form, ['tify_config' => '[1, 2]'] + $valid));
$v->check('an empty JSON object is fine', validates($form, ['tify_config' => '{}'] + $valid));
$v->check('an unknown color mode is refused', !validates($form, ['tify_color_mode' => 'sepia'] + $valid));

// ------------------------------------------------------------- view helper

$v->section('The view helper builds the TIFY options in the documented order');

$settings = new FakeSettings($config['tify']['settings'] + [
    'iiifserver_manifest_external_property' => 'dcterms:hasFormat',
    'locale' => 'en_US',
]);
$siteSettings = new FakeSettings($config['tify']['site_settings'] + ['locale' => 'nl']);
$status = new FakeStatus();
$view = makeView($settings, $siteSettings, $status);
$helper = $view->plugin('tify');

$item = fakeItem(9, ['image/jpeg', 'application/pdf', 'image/tiff']);
$html = $view->tify($item);
$options = optionsOf($html);

$v->check('an item with files gets a viewer', '' !== $html && is_array($options), substr($html, 0, 80));
$v->check('its manifest comes from IIIF Server', 'https://omeka.example/iiif/3/9/manifest' === ($options['manifestUrl'] ?? null));
$v->check('the language follows the site locale', 'nl' === ($options['language'] ?? null));
$v->check('the color mode defaults to auto', 'auto' === ($options['colorMode'] ?? null));
$v->check('the translations are served from the module', '/modules/Tify/asset/vendor/tify/translations' === ($options['translationsDirUrl'] ?? null));
$v->check('the default height is used', false !== strpos($html, 'height: 640px;'));
$v->check('the noscript fallback links the manifest', false !== strpos($html, '<noscript>') && false !== strpos(html_entity_decode($html, ENT_QUOTES | ENT_HTML5), 'iiif/3/9/manifest'));
$v->check('no URL query key unless configured', !array_key_exists('urlQueryKey', $options));
$v->check('the default views are passed in order', ['text', 'thumbnails', 'toc', 'info', 'export', 'collection'] === ($options['views'] ?? null));

$head = html_entity_decode((string) $view->headScript(), ENT_QUOTES | ENT_HTML5);
$v->check('TIFY is loaded as an ES module', (bool) preg_match('~<script type="module" src="/modules/Tify/asset/vendor/tify/tify.js\?v=[0-9a-f]{8}"~', $head), trim($head));
$v->check('the starter script follows it', strpos($head, 'vendor/tify/tify.js') < strpos($head, 'asset/js/tify.js'));
$v->check('assets are versioned by their file, not by the module version', false === strpos($head . (string) $view->headLink(), '?v=' . $moduleVersion));
$v->check('the stylesheets are added', 2 === substr_count((string) $view->headLink(), 'stylesheet'));

$siteSettings->set('tify_height', '75vh');
$siteSettings->set('tify_color_mode', 'dark');
$siteSettings->set('tify_views', 'info, bogus, thumbnails, info');
$siteSettings->set('tify_view', 'info');
$settings->set('tify_url_query_key', 'tify');
$settings->set('tify_config', '{"pageLabelFormat": "P / T", "zoom": 2}');
$siteSettings->set('tify_config', '{"zoom": 3}');
$html = $view->tify($item, ['tify' => ['pan' => ['x' => 0.5]]]);
$options = optionsOf($html);

$v->check('a site height replaces the main one', false !== strpos($html, 'height: 75vh;'));
$v->check('a site color mode replaces the main one', 'dark' === $options['colorMode']);
$v->check('the panel list keeps its order, without unknown or repeated names', ['info', 'thumbnails'] === $options['views'], json_encode($options['views']));
$v->check('the search panel is accepted', ['search', 'text'] === $view->plugin('tify')->parseViews('search, text'));
$v->check('the start panel is passed', 'info' === $options['view']);
$v->check('the main JSON config is applied', 'P / T' === ($options['pageLabelFormat'] ?? null));
$v->check('the site JSON config wins over the main one', 3 === ($options['zoom'] ?? null));
$v->check('the options of the call are applied last', ['x' => 0.5] === ($options['pan'] ?? null));
$v->check('a URL query key from the main settings is used', 'tify' === ($options['urlQueryKey'] ?? null));

$secondHtml = $view->tify($item);
$thirdHtml = $view->tify($item);
$second = optionsOf($secondHtml);
$third = optionsOf($thirdHtml);
$v->check('a second viewer on the page gets its own query key', 'tify-2' === ($second['urlQueryKey'] ?? null), $second['urlQueryKey'] ?? 'none');
$v->check('and a third one too', 'tify-3' === ($third['urlQueryKey'] ?? null));
preg_match_all('/id="(tify-\d+)"/', $html . $secondHtml . $thirdHtml, $ids);
$v->check('each viewer gets its own element id', 3 === count(array_unique($ids[1])), implode(', ', $ids[1]));

$siteSettings->set('tify_config', '{"urlQueryKey": null}');
$v->check('the site JSON can switch the query key off', !array_key_exists('urlQueryKey', optionsOf($view->tify($item))));
$siteSettings->set('tify_view', 'none');
$v->check('a site start panel of "none" overrides the main one', !array_key_exists('view', optionsOf($view->tify($item))));
$siteSettings->set('tify_config', '{oops');
$v->check('a broken JSON setting is ignored instead of breaking the page', '' !== $view->tify($item));

$v->section('Manifest resolution');

$v->check('a media opens on its page among the images of the item', [2] === (optionsOf($view->tify($item->media()[2]))['pages'] ?? null),
    json_encode(optionsOf($view->tify($item->media()[2]))['pages'] ?? null));
$v->check('a media is shown through the manifest of its item', 'https://omeka.example/iiif/3/9/manifest' === optionsOf($view->tify($item->media()[0]))['manifestUrl']);
$v->check('a non-image media gets no start page', !isset(optionsOf($view->tify($item->media()[1]))['pages']));

$external = fakeItem(10, ['image/jpeg'], ['dcterms:hasFormat' => [
    new FakeValue('resource:item', 'https://omeka.example/s/x/item/3', fakeItem(3)),
    new FakeValue('literal', 'not a url'),
    new FakeValue('uri', 'https://iiif.example/manifest.json'),
]]);
$v->check('an external manifest wins over IIIF Server', 'https://iiif.example/manifest.json' === optionsOf($view->tify($external))['manifestUrl']);
$literal = fakeItem(11, [], ['dcterms:hasFormat' => [new FakeValue('literal', ' https://iiif.example/m2.json ')]]);
$v->check('a URL as a literal value is accepted, even without files', 'https://iiif.example/m2.json' === (optionsOf($view->tify($literal))['manifestUrl'] ?? null));
$v->check('an item without files and without a manifest shows nothing', '' === $view->tify(fakeItem(12)));
$v->check('several items become a IIIF Server collection', 'https://omeka.example/iiif/set/9,10' === optionsOf($view->tify([$item, $item->media()[0], $external]))['manifestUrl']);
$v->check('a list that reduces to one item is that item', 'https://omeka.example/iiif/3/9/manifest' === optionsOf($view->tify([$item, $item->media()[0]]))['manifestUrl']);
$v->check('a manifest URL can be given directly', 'https://x.example/m.json' === optionsOf($view->tify('https://x.example/m.json'))['manifestUrl']);
$v->check('a string that is not an http(s) URL shows nothing', '' === $view->tify('javascript:alert(1)'));
$v->check('nothing shows nothing', '' === $view->tify(null) && '' === $view->tify([]));

$bare = makeView($settings, $siteSettings, new FakeStatus(), false);
$v->check('without IIIF Server an item without an external manifest shows nothing', '' === $bare->tify($item));
$v->check('without IIIF Server an external manifest still shows', '' !== $bare->tify($external));
$v->check('without IIIF Server several items show nothing', '' === $bare->tify([$item, $external]));

$v->section('Escaping and admin pages');

$hostile = $view->tify('https://x.example/m.json?a="><script>alert(1)</script>');
$v->check('a manifest URL cannot break out of the attribute', false === strpos($hostile, '<script>') && false === strpos($hostile, '"><'));
$v->check('an unsafe height option falls back', false !== strpos($view->tify($item, ['height' => '1px;background:red']), 'height: 75vh;'));

$adminStatus = new FakeStatus();
$adminStatus->site = false;
$adminView = makeView($settings, new FakeSettings(['tify_height' => '10px', 'locale' => 'nl']), $adminStatus);
$adminHtml = $adminView->tify($item);
$v->check('the admin ignores site settings', false === strpos($adminHtml, 'height: 10px;'));
$v->check('the admin language follows the main locale', 'en' === optionsOf($adminHtml)['language']);

$v->section('Languages');

foreach (['nl_NL' => 'nl', 'de-DE' => 'de', 'FR' => 'fr', 'en_GB' => 'en', 'pt_BR' => 'en', '' => 'en', null => 'en', '../x' => 'en'] as $locale => $expected) {
    $v->check(sprintf('locale "%s" gives TIFY language "%s"', $locale, $expected), $expected === $helper->language($locale === '' ? null : (string) $locale));
}

// ------------------------------------------------------------- block layouts

$v->section('The site page block validates what it is given');

$layout = new Tify\Site\BlockLayout\Tify();
$v->check('the block has a label', '' !== (string) $layout->getLabel());
$v->check('the block can be templated', $layout instanceof Omeka\Site\BlockLayout\TemplateableBlockLayoutInterface);

function hydrate($layout, array $data)
{
    $block = new Omeka\Entity\SitePageBlock();
    $block->setData($data);
    $errors = new Omeka\Stdlib\ErrorStore();
    $layout->onHydrate($block, $errors);
    return [$block->getData(), $errors->getErrors()];
}

[$data, $errors] = hydrate($layout, ['manifest_url' => ' https://x.example/m.json ', 'height' => '500', 'config' => '{"view": "info"}']);
$v->check('valid data passes', [] === $errors, json_encode($errors));
$v->check('and is trimmed', 'https://x.example/m.json' === $data['manifest_url']);
[, $errors] = hydrate($layout, ['manifest_url' => 'ftp://x.example/m.json']);
$v->check('a manifest URL that is not http(s) is refused', [] !== $errors);
[, $errors] = hydrate($layout, ['height' => 'tall']);
$v->check('a height that is not a CSS length is refused', [] !== $errors);
[, $errors] = hydrate($layout, ['config' => '["view"]']);
$v->check('options that are not a JSON object are refused', [] !== $errors);
[, $errors] = hydrate($layout, []);
$v->check('an empty block is fine: it shows its attachments', [] === $errors);

$v->section('The resource page block');

$resourceBlock = new Tify\Site\ResourcePageBlockLayout\Tify();
$v->check('it is offered for items, media and item sets', ['items', 'media', 'item_sets'] === $resourceBlock->getCompatibleResourceNames());
$siteSettings->set('tify_config', '');
$v->check('it renders the viewer', false !== strpos($resourceBlock->render($view, $item), 'class="tify-viewer"'));

exit($v->summary());
