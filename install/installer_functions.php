<?php

function make_password($length = 8)
{
    $password = "";
    $possible = "0123456789abcdfghjkmnpqrstvwxyzABCDFGHJKMNPQRSTVWXYZ*%+^";

    $i = 0;

    while ($i < $length) {
        $char = substr($possible, mt_rand(0, strlen($possible)-1), 1);

        if (!strstr($password, $char)) {
            $password .= $char;
            $i++;
        }
    }
    return $password;
}


function sanity_check($db_type)
{
    $errors = array();

    if (version_compare(PHP_VERSION, '5.3.0', '<')) {
        array_push($errors, "PHP version 5.3.0 or newer required.");
    }

    if (!function_exists("curl_init") && !ini_get("allow_url_fopen")) {
        array_push(
            $errors,
            "PHP configuration option allow_url_fopen is disabled, and CURL ".
            "functions are not present. Either enable allow_url_fopen or ".
            "install PHP extension for CURL."
        );
    }

    if (!function_exists("json_encode")) {
        array_push($errors, "PHP support for JSON is required, but was not found.");
    }

    if ($db_type == "mysql" && !function_exists("mysql_connect") && !function_exists("mysqli_connect")) {
        array_push($errors, "PHP support for MySQL is required for configured $db_type in config.php.");
    }

    if ($db_type == "pgsql" && !function_exists("pg_connect")) {
        array_push($errors, "PHP support for PostgreSQL is required for configured $db_type in config.php");
    }

    if (!function_exists("mb_strlen")) {
        array_push($errors, "PHP support for mbstring functions is required but was not found.");
    }

    if (!function_exists("hash")) {
        array_push($errors, "PHP support for hash() function is required but was not found.");
    }

    if (!function_exists("ctype_lower")) {
        array_push($errors, "PHP support for ctype functions are required by HTMLPurifier.");
    }

    if (!function_exists("iconv")) {
        array_push($errors, "PHP support for iconv is required to handle multiple charsets.");
    }

    /* if (ini_get("safe_mode")) {
        array_push($errors, "PHP safe mode setting is not supported.");
    } */

    if (!class_exists("DOMDocument")) {
        array_push($errors, "PHP support for DOMDocument is required, but was not found.");
    }

    return $errors;
}

function db_connect($host, $user, $pass, $db, $type, $port = false)
{
    if ($type == "pgsql") {

        $string = "dbname=$db user=$user";

        if ($pass) {
            $string .= " password=$pass";
        }

        if ($host) {
            $string .= " host=$host";
        }

        if ($port) {
            $string = "$string port=" . $port;
        }

        $link = pg_connect($string);

        return $link;
    }

    if ($type == "mysql") {
        if (function_exists("mysqli_connect")) {
            if ($port) {
                return mysqli_connect($host, $user, $pass, $db, $port);
            }
            return mysqli_connect($host, $user, $pass, $db);
        }

        $link = mysql_connect($host, $user, $pass);
        if ($link) {
            $result = mysql_select_db($db, $link);
            if ($result) {
                return $link;
            }
        }
    }
}

function make_config(
    $DB_TYPE,
    $DB_HOST,
    $DB_USER,
    $DB_NAME,
    $DB_PASS,
    $DB_PORT,
    $SELF_URL_PATH
) {
    $data = explode("\n", file_get_contents("../config.php-dist"));

    $rv = "";

    $finished = false;

    if (function_exists("mcrypt_decrypt")) {
        $crypt_key = make_password(24);
    } else {
        $crypt_key = "";
    }

    foreach ($data as $line) {
        if (preg_match("/define\('DB_TYPE'/", $line)) {
            $rv .= "define('DB_TYPE', '$DB_TYPE');\n";
        } elseif (preg_match("/define\('DB_HOST'/", $line)) {
            $rv .= "define('DB_HOST', '$DB_HOST');\n";
        } elseif (preg_match("/define\('DB_USER'/", $line)) {
            $rv .= "define('DB_USER', '$DB_USER');\n";
        } elseif (preg_match("/define\('DB_NAME'/", $line)) {
            $rv .= "define('DB_NAME', '$DB_NAME');\n";
        } elseif (preg_match("/define\('DB_PASS'/", $line)) {
            $rv .= "define('DB_PASS', '$DB_PASS');\n";
        } elseif (preg_match("/define\('DB_PORT'/", $line)) {
            $rv .= "define('DB_PORT', '$DB_PORT');\n";
        } elseif (preg_match("/define\('SELF_URL_PATH'/", $line)) {
            $rv .= "define('SELF_URL_PATH', '$SELF_URL_PATH');\n";
        } elseif (preg_match("/define\('FEED_CRYPT_KEY'/", $line)) {
            $rv .= "define('FEED_CRYPT_KEY', '$crypt_key');\n";
        } else {
            $rv .= "$line\n";
        }
    }

    return $rv;
}

function db_query($link, $query, $type, $die_on_error = true)
{
    if ($type == "pgsql") {
        $result = pg_query($link, $query);
        if (!$result) {
            $query = htmlspecialchars($query); // just in case
            if ($die_on_error) {
                // TODO Show a complete error page.
                die("Query <i>$query</i> failed [$result]: " . ($link ? pg_last_error($link) : "No connection"));
            }
        }
        return $result;
    }

    if ($type == "mysql") {
        if (function_exists("mysqli_connect")) {
            $result = mysqli_query($link, $query);
        } else {
            $result = mysql_query($query, $link);
        }
        if (!$result) {
            $query = htmlspecialchars($query);
            if ($die_on_error) {
                // TODO Show a complete error page.
                die(
                    "Query <i>$query</i> failed: " .
                    ($link ?
                        (function_exists("mysqli_connect") ? mysqli_error($link) : mysql_error($link)) :
                        "No connection")
                );
            }
        }
        return $result;
    }
}

function make_self_url_path()
{
    $url_path = ((!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] != "on") ? 'http://' :  'https://') .
        $_SERVER["HTTP_HOST"] . parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);

    return $url_path;
}
