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
        $error = render_error(
            $twig,
            "Error: config.php already exists in tt-rss directory; aborting."
        );
        print($error);
        exit;
    }
}

@$op = $_REQUEST['op'];

@$DB_HOST = strip_tags($_POST['DB_HOST']);
@$DB_TYPE = strip_tags($_POST['DB_TYPE']);
@$DB_USER = strip_tags($_POST['DB_USER']);
@$DB_NAME = strip_tags($_POST['DB_NAME']);
@$DB_PASS = strip_tags($_POST['DB_PASS']);
@$DB_PORT = strip_tags($_POST['DB_PORT']);
@$SELF_URL_PATH = strip_tags($_POST['SELF_URL_PATH']);

if (!$SELF_URL_PATH) {
    $SELF_URL_PATH = preg_replace("/\/install\/$/", "/", make_self_url_path());
}
?>

<html>
<head>
	<title>Tiny Tiny RSS - Installer</title>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<link rel="stylesheet" type="text/css" href="../css/utility.css">
	<link rel="stylesheet" type="text/css" href="../css/dijit.css">
	<style type="text/css">
	textarea { font-size : 12px; }
	</style>
</head>
<body>
<div class="floatingLogo"><img src="../images/logo_small.png"></div>
<h1>Tiny Tiny RSS Installer</h1>
<div class='content'>

<form action="" method="post">
<input type="hidden" name="op" value="testconfig">

<h2>Database settings</h2>

<?php
$issel_pgsql = $DB_TYPE == "pgsql" ? "selected" : "";
$issel_mysql = $DB_TYPE == "mysql" ? "selected" : "";
?>

<fieldset>
	<label>Database type</label>
	<select name="DB_TYPE">
		<option <?php echo $issel_pgsql ?> value="pgsql">PostgreSQL</option>
		<option <?php echo $issel_mysql ?> value="mysql">MySQL</option>
	</select>
</fieldset>

<fieldset>
	<label>Username</label>
	<input required name="DB_USER" size="20" value="<?php echo $DB_USER ?>"/>
</fieldset>

<fieldset>
	<label>Password</label>
	<input name="DB_PASS" size="20" type="password" value="<?php echo $DB_PASS ?>"/>
</fieldset>

<fieldset>
	<label>Database name</label>
	<input required name="DB_NAME" size="20" value="<?php echo $DB_NAME ?>"/>
</fieldset>

<fieldset>
	<label>Host name</label>
	<input name="DB_HOST" size="20" value="<?php echo $DB_HOST ?>"/>
	<span class="hint">If needed</span>
</fieldset>

<fieldset>
	<label>Port</label>
	<input name="DB_PORT" type="number" size="20" value="<?php echo $DB_PORT ?>"/>
	<span class="hint">Usually 3306 for MySQL or 5432 for PostgreSQL</span>
</fieldset>

<h2>Other settings</h2>

<p>This should be set to the location your Tiny Tiny RSS will be available on.</p>

<fieldset>
	<label>Tiny Tiny RSS URL</label>
	<input type="url" name="SELF_URL_PATH" placeholder="<?php echo $SELF_URL_PATH; ?>"
		size="60" value="<?php echo $SELF_URL_PATH ?>"/>
</fieldset>


<p><input type="submit" value="Test configuration"></p>

</form>

<?php
if ($op == 'testconfig') {

    ?><h2>Checking configuration</h2>
    <?php

    $errors = sanity_check($DB_TYPE);

    if (count($errors) > 0) {
        ?>
        <p>Some configuration tests failed. Please correct them before continuing.</p>

        <ul>
        <?php

        foreach ($errors as $error) {
            print "<li style='color : red'>$error</li>";
        }
        ?>
        </ul>
        <?php

        exit;
    }

    $notices = array();

    if (!function_exists("curl_init")) {
        array_push($notices, "It is highly recommended to enable support for CURL in PHP.");
    }

    if (count($notices) > 0) {
        print_notice("Configuration check succeeded with minor problems:");

        ?>
        <ul>
        <?php

        foreach ($notices as $notice) {
            print "<li>$notice</li>";
        }

        ?>
        </ul>
        <?php
    } else {
        print_notice("Configuration check succeeded.");
    }

    ?><h2>Checking database</h2>
    <?php

    $link = db_connect($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_TYPE, $DB_PORT);

    if (!$link) {
        print_error("Unable to connect to database using specified parameters.");
        exit;
    }

    print_notice("Database test succeeded.");

    ?><h2>Initialize database</h2>

    <p>Before you can start using tt-rss, database needs to be initialized.Click on the button below to do that now.</p>

    <?php
    $result = @db_query($link, "SELECT true FROM ttrss_feeds", $DB_TYPE, false);

    if ($result) {
        print_error(
            "Existing tt-rss tables will be removed from the database. ".
            "If you would like to keep your data, skip database initialization."
        );
        $need_confirm = true;
    } else {
        $need_confirm = false;
    }
    ?>

    <table><tr><td>
    <form method="post">
    <input type="hidden" name="op" value="installschema">

    <input type="hidden" name="DB_USER" value="<?php echo $DB_USER ?>"/>
    <input type="hidden" name="DB_PASS" value="<?php echo $DB_PASS ?>"/>
    <input type="hidden" name="DB_NAME" value="<?php echo $DB_NAME ?>"/>
    <input type="hidden" name="DB_HOST" value="<?php echo $DB_HOST ?>"/>
    <input type="hidden" name="DB_PORT" value="<?php echo $DB_PORT ?>"/>
    <input type="hidden" name="DB_TYPE" value="<?php echo $DB_TYPE ?>"/>
    <input type="hidden" name="SELF_URL_PATH" value="<?php echo $SELF_URL_PATH ?>"/>

    <?php
    if ($need_confirm) {
        ?>
        <p><input onclick="return confirm('Please read the warning above. Continue?')"
            type="submit" value="Initialize database" style="color : red"></p>
        <?php
    } else {
        ?>
        <p><input type="submit" value="Initialize database" style="color : red"></p>
        <?php
    }
    ?>
    </form>

    </td><td>
    <form method="post">
    <input type="hidden" name="DB_USER" value="<?php echo $DB_USER ?>"/>
    <input type="hidden" name="DB_PASS" value="<?php echo $DB_PASS ?>"/>
    <input type="hidden" name="DB_NAME" value="<?php echo $DB_NAME ?>"/>
    <input type="hidden" name="DB_HOST" value="<?php echo $DB_HOST ?>"/>
    <input type="hidden" name="DB_PORT" value="<?php echo $DB_PORT ?>"/>
    <input type="hidden" name="DB_TYPE" value="<?php echo $DB_TYPE ?>"/>
    <input type="hidden" name="SELF_URL_PATH" value="<?php echo $SELF_URL_PATH ?>"/>

    <input type="hidden" name="op" value="skipschema">
    <p><input type="submit" value="Skip initialization"></p>
    </form>

    </td></tr></table>

    <?php
} elseif ($op == 'installschema' || $op == 'skipschema') {

    $link = db_connect($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_TYPE, $DB_PORT);

    if (!$link) {
        print_error("Unable to connect to database using specified parameters.");
        exit;
    }

    if ($op == 'installschema') {

        ?>
        <h2>Initializing database...</h2>
        <?php

        $lines = explode(
            ";",
            preg_replace("/[\r\n]/", "", file_get_contents("../schema/ttrss_schema_".basename($DB_TYPE).".sql"))
        );

        foreach ($lines as $line) {
            if (strpos($line, "--") !== 0 && $line) {
                db_query($link, $line, $DB_TYPE);
            }
        }

        print_notice("Database initialization completed.");

    } else {
        print_notice("Database initialization skipped.");
    }

    ?>
    <h2>Generated configuration file</h2>

    <p>Copy following text and save as <code>config.php</code> in tt-rss
    main directory. It is suggested to read through the file to the end in
    case you need any options changed fom default values.</p>

    <p>After copying the file, you will be able to login with default
    username and password combination: <code>admin</code> and
    <code>password</code>. Don't forget to change the password immediately!</p>

    <form action="" method="post">
    <input type="hidden" name="op" value="saveconfig">
    <input type="hidden" name="DB_USER" value="<?php echo $DB_USER ?>"/>
    <input type="hidden" name="DB_PASS" value="<?php echo $DB_PASS ?>"/>
    <input type="hidden" name="DB_NAME" value="<?php echo $DB_NAME ?>"/>
    <input type="hidden" name="DB_HOST" value="<?php echo $DB_HOST ?>"/>
    <input type="hidden" name="DB_PORT" value="<?php echo $DB_PORT ?>"/>
    <input type="hidden" name="DB_TYPE" value="<?php echo $DB_TYPE ?>"/>
    <input type="hidden" name="SELF_URL_PATH" value="<?php echo $SELF_URL_PATH ?>"/>
    <?php

    print "<textarea cols=\"80\" rows=\"20\">";
    echo make_config(
        $DB_TYPE,
        $DB_HOST,
        $DB_USER,
        $DB_NAME,
        $DB_PASS,
        $DB_PORT,
        $SELF_URL_PATH
    );
    print "</textarea>";

    if (is_writable("..")) {
        ?>
        <p>We can also try saving the file automatically now.</p>

        <p><input type="submit" value="Save configuration"></p>
        </form>
        <?php
    } else {
        print_error(
            "Unfortunately, parent directory is not writable, so we're ".
            "unable to save config.php automatically."
        );
    }

    print_notice("You can generate the file again by changing the form above.");

} elseif ($op == "saveconfig") {

    ?>
    <h2>Saving configuration file to parent directory...</h2>
    <?php

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

            if ($written > 0) {
                print_notice("Successfully saved config.php. You can try <a href=\"..\">loading tt-rss now</a>.");

            } else {
                print_notice("Unable to write into config.php in tt-rss directory.");
            }

            fclose($fp);
        } else {
            print_error("Unable to open config.php in tt-rss directory for writing.");
        }
    } else {
        print_error("config.php already present in tt-rss directory, refusing to overwrite.");
    }
}
?>

</div>

</body>
</html>
