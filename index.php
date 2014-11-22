<?php
if (file_exists("install") && !file_exists("config.php")) {
    // Redirect to installation page.
    header("Location: install/");
}

// Initialize template engine
require_once 'vendor/autoload.php';
$loader = new Twig_Loader_Filesystem('templates/html');
$twig = new Twig_Environment($loader, array('cache' => 'cache/templates'));

if (!file_exists("config.php")) {
    // Neither install/ nor config.php exists.
    $template = $twig->loadTemplate('main/fatal_error.html');
    $page = $template->render(
        array(
            'error_message' => 'You forgot to copy <b>config.php-dist</b> '.
                'to <b>config.php</b> and edit it.'
        )
    );
    print $page;
    exit;
}

if (version_compare(PHP_VERSION, '5.3.7', '<')) {
    // The version of PHP is not sufficient for bfrss.
    $template = $twig->loadTemplate('main/fatal_error.html');
    $page = $template->render(
        array(
            'error_message' => 'PHP version 5.3.7 or newer required.'
        )
    );
    print $page;
    exit;
}

set_include_path(
    dirname(__FILE__) ."/include" . PATH_SEPARATOR . get_include_path()
);

require_once "autoload.php";
require_once "sessions.php";
require_once "functions.php";
require_once "sanity_check.php";
require_once "version.php";
require_once "config.php";
require_once "db-prefs.php";
require_once "lib/Mobile_Detect.php";

$mobile = new Mobile_Detect();

if (!init_plugins()) {
    return;
}

if (!$_REQUEST['mobile']) {
    if ($mobile->isTablet() && PluginHost::getInstance()->get_plugin("digest")) {
        header('Location: backend.php?op=digest');
        exit;
    } elseif ($mobile->isMobile() && PluginHost::getInstance()->get_plugin("mobile")) {
        header('Location: backend.php?op=mobile');
        exit;
    } elseif ($mobile->isMobile() && PluginHost::getInstance()->get_plugin("digest")) {
        header('Location: backend.php?op=digest');
        exit;
    }
}

login_sequence();

header('Content-Type: text/html; charset=utf-8');

$template = $twig->loadTemplate('main/index.html');
$template_vars = array(
        'version' => VERSION,
        'css_files' => array(),
        'css_user' => '',
        'css_plugins' => '',
        'js_files' => array(),
        'js' => '',
        'hook_toolbar_buttons' => array(),
        'hook_action_items' => array(),
        'hide_logout' => false
);

// Add css files
array_push($template_vars['css_files'], stylesheet_tag_array("lib/dijit/themes/claro/claro.css"));
array_push($template_vars['css_files'], stylesheet_tag_array("css/layout.css"));

if ($_SESSION["uid"]) {
    $theme = get_pref("USER_CSS_THEME", $_SESSION["uid"], false);
    if ($theme && file_exists("themes/$theme")) {
        array_push($template_vars['css_files'], stylesheet_tag_array("themes/$theme"));
    } else {
        array_push($template_vars['css_files'], stylesheet_tag_array("themes/default.css"));
    }
}

// Add user-customized css
$usercss_br = get_pref('USER_STYLESHEET');
if ($usercss_br) {
    $template_vars['css_user'] = str_replace("<br/>", "\n", $usercss_br);
}

// Add css from plugins
foreach (PluginHost::getInstance()->get_plugins() as $n => $p) {
    if (method_exists($p, "get_css")) {
        $template_vars['css_plugins'] .= $p->get_css();
    }
}


// Add js files
foreach (array(
    "lib/prototype.js",
    "lib/scriptaculous/scriptaculous.js?load=effects,controls",
    "lib/dojo/dojo.js",
    "lib/dojo/tt-rss-layer.js",
    "errors.php?mode=js"
    ) as $jsfile) {

    array_push($template_vars['js_files'], javascript_tag_array($jsfile));
}

// Add raw js
require_once 'lib/jshrink/Minifier.php';

$template_vars['js'] .= get_minified_js(array("tt-rss",
    "functions", "feedlist", "viewfeed", "FeedTree", "PluginHost"));

foreach (PluginHost::getInstance()->get_plugins() as $n => $p) {
    if (method_exists($p, "get_js")) {
        $template_vars['js'] .= JShrink\Minifier::minify($p->get_js());
    }
}

$template_vars['js'] .= init_js_translations_return();


// Toolbar buttons from plugins
foreach (PluginHost::getInstance()->get_hooks(PluginHost::HOOK_TOOLBAR_BUTTON) as $p) {
    array_push($template_vars['hook_toolbar_buttons'], $p->hook_toolbar_button());
}

// Action items from plugins
foreach (PluginHost::getInstance()->get_hooks(PluginHost::HOOK_ACTION_ITEM) as $p) {
    array_push($template_vars['hook_action_items'], $p->hook_action_item());
}

// Hide logout?
if ($_SESSION["hide_logout"]) {
    $template_vars['hide_logout'] = true;
}

// Render template and show it to the user
$page = $template->render($template_vars);
echo $page;
