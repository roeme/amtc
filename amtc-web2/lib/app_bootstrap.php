<?php
/*
 * lib/app_bootstrap.php - part of amtc-web, part of amtc
 * https://github.com/schnoddelbotz/amtc
 *
 * Try to load config, require libs, initialzie ORM and Slim
 */

define('AMTC_WEBROOT', dirname(__FILE__).'/..');
define('AMTC_CFGFILE', AMTC_WEBROOT.'/config/siteconfig.php');

@include AMTC_CFGFILE; // to let static ember help pages work even if unconfigured
date_default_timezone_set( defined('AMTC_TZ') ? AMTC_TZ : 'Europe/Berlin');

set_include_path(get_include_path().PATH_SEPARATOR.
  AMTC_WEBROOT.'/lib'.PATH_SEPARATOR.AMTC_WEBROOT.'/lib/db-model');
spl_autoload_extensions('.php');
spl_autoload_register();

require 'idiorm.php';
require 'paris.php';
require 'Slim/Slim.php';

// Initialize http://j4mie.github.io/idiormandparis/
ORM::configure(AMTC_PDOSTRING);

// Initialize http://www.slimframework.com/
\Slim\Slim::registerAutoloader();
$app = new \Slim\Slim();
$app->config('debug', false); // ... and enables custom $app->error() handler
$app->response()->header('Content-Type', 'application/json;charset=utf-8');
$app->notFound(function () use ($app) {
  echo json_encode(Array('error'=>'Not found'));
});
$app->error(function (\Exception $e) use ($app) {
  echo json_encode( array('exceptionMessage'=> substr($e->getMessage(),0,128).'...') );
});

