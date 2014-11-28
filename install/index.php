<?php
set_include_path(
    dirname(__FILE__) . PATH_SEPARATOR . get_include_path()
);

require_once 'installer_functions.php';
require_once '../vendor/autoload.php';

$loader = new Twig_Loader_Filesystem('../templates/html/installer');
$twig = new Twig_Environment($loader, array('cache' => '../cache/templates'));

if (file_exists("../config.php")) {
    require "../config.php";

    if (!defined('_INSTALLER_IGNORE_CONFIG_CHECK')) {
        $error = "Error: config.php already exists in tt-rss directory; aborting.";
        $page = $twig->render('installer.html', array('error' => $error));
        echo $page;
        exit;
    }
}

@$op = $_REQUEST['op'];

@$DB_TYPE = strip_tags($_POST['DB_TYPE']);
@$DB_USER = strip_tags($_POST['DB_USER']);
@$DB_PASS = strip_tags($_POST['DB_PASS']);
@$DB_NAME = strip_tags($_POST['DB_NAME']);
@$DB_HOST = strip_tags($_POST['DB_HOST']);
@$DB_PORT = strip_tags($_POST['DB_PORT']);
@$SELF_URL_PATH = strip_tags($_POST['SELF_URL_PATH']);

if (!$SELF_URL_PATH) {
    $SELF_URL_PATH = preg_replace("/\/install\/$/", "/", make_self_url_path());
}

$template = $twig->loadTemplate('installer-base.html');
$template_vars = array(
        'DB_TYPE' => $DB_TYPE,
        'DB_USER' => $DB_USER,
        'DB_PASS' => $DB_PASS,
        'DB_NAME' => $DB_NAME,
        'DB_HOST' => $DB_HOST,
        'DB_PORT' => $DB_PORT,
        'SELF_URL_PATH' => $SELF_URL_PATH,
        'pgsql' => $DB_TYPE == "pgsql" ? "selected" : "",
        'mysql' => $DB_TYPE == "mysql" ? "selected" : ""
);


if ($op == 'testconfig') {
    $errors = sanity_check($DB_TYPE);

    if (count($errors) > 0) {
        $template = $twig->loadTemplate('installer-testconfig-error-config.html');
        $template_vars['config_errors'] = $errors;

    } else {
        $notices = array();
        if (!function_exists("curl_init")) {
            array_push($notices, "It is highly recommended to enable support for CURL in PHP.");
        }
        $template_vars['config_notices'] = $notices;

        $link = db_connect($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_TYPE, $DB_PORT);

        if (!$link) {
            $template = $twig->loadTemplate('installer-testconfig-error-db.html');
        } else {
            $result = @db_query($link, "SELECT true FROM ttrss_feeds", $DB_TYPE, false);

            $template = $twig->loadTemplate('installer-testconfig.html');
            $template_vars['db_exists'] = $result ? true : false;
        }
    }
} elseif ($op == 'installschema' || $op == 'skipschema') {

    $link = db_connect($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_TYPE, $DB_PORT);

    if (!$link) {
        $template = $twig->loadTemplate('installer-schema-error-db.html');
    } else {
        $template = $twig->loadTemplate('installer-schema.html');
        $template_vars['op'] = $op;

        if ($op == 'installschema') {
            $lines = explode(
                ";",
                preg_replace(
                    "/[\r\n]/",
                    "",
                    file_get_contents("../schema/ttrss_schema_".basename($DB_TYPE).".sql")
                )
            );

            foreach ($lines as $line) {
                if (strpos($line, "--") !== 0 && $line) {
                    db_query($link, $line, $DB_TYPE);
                }
            }
        }

        $template_vars['config_file_content'] = make_config(
            $DB_TYPE,
            $DB_HOST,
            $DB_USER,
            $DB_NAME,
            $DB_PASS,
            $DB_PORT,
            $SELF_URL_PATH
        );

        $template_vars['writable'] = is_writable("..");
    }

} elseif ($op == "saveconfig") {
    $template = $twig->loadTemplate('installer-saveconfig.html');

    if (!file_exists("../config.php")) {
        $fp = fopen("../config.php", "w");

        if ($fp) {
            $written = fwrite(
                $fp,
                make_config(
                    $DB_TYPE,
                    $DB_HOST,
                    $DB_USER,
                    $DB_NAME,
                    $DB_PASS,
                    $DB_PORT,
                    $SELF_URL_PATH
                )
            );

            $template_vars['notice'] = $written > 0 ?
                'Successfully saved config.php. '.
                'You can try <a href="..">loading tt-rss now</a>.' :
                'Unable to write into config.php '.
                'in tt-rss directory.';

            fclose($fp);
        } else {
            $template_vars['error'] = 'Unable to open config.php '.
                'in tt-rss directory for writing.';
        }
    } else {
        $template_vars['error'] = 'config.php already present '.
            'in tt-rss directory, refusing to overwrite.';
    }
}

$page = $template->render($template_vars);
echo $page;
