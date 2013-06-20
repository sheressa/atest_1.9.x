<?php

/**
 * @author Matthew McNaney <mcnaney at gmail dot com>
 * @version $Id$
 */
ini_set('display_errors', 'On');
error_reporting(-1);


if (!defined('PHPWS_SOURCE_DIR')) {
    define('PHPWS_SOURCE_DIR',
            $_SERVER['DOCUMENT_ROOT'] . str_replace('/setup/index.php', '',
                    $_SERVER['PHP_SELF'] . '/'));
}
chdir(PHPWS_SOURCE_DIR);

if (!defined('PHPWS_SOURCE_HTTP')) {
    define('PHPWS_SOURCE_HTTP',
            'http://' . $_SERVER['HTTP_HOST'] . str_replace('/setup/index.php',
                    '', $_SERVER['PHP_SELF'] . '/'));
}

define('SETUP_USER_ERROR', -1);
define('SITE_HASH', 'x');
define('SETUP_CONFIGURATION_DIRECTORY', 'config/');


//require_once 'core/conf/defines.dist.php';
require_once 'core/conf/defines.php';
require_once 'Global/Functions.php';
require_once 'setup/class/Setup.php';

set_error_handler(array('Error', 'errorHandler'));
set_exception_handler(array('Error', 'exceptionHandler'));

try {
    $setup = new Setup;
    $setup->processCommand();
} catch (\Exception $e) {
    if ($e->getCode() == SETUP_USER_ERROR) {
        $setup->setMessage($e->getMessage());
    } else {
        //echo json_encode(array('error'=>$e->getMessage()));
        //exit();
        throw $e;
    }
}
$setup->display();
?>
