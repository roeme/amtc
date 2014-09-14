<?php
// this might to be split into /display/api.php, /admin/api.php ...?
// do this to trigger async REST issues...:
// sleep(2);

$amtcwebConfigFile = 'data/siteconfig.php';
@include $amtcwebConfigFile; // to let static ember help pages work event without DB
date_default_timezone_set( defined('AMTC_TZ') ? AMTC_TZ : 'Europe/Berlin');

require 'lib/php-activerecord/ActiveRecord.php';
require 'lib/Slim/Slim.php';

// Initialize http://www.slimframework.com/
\Slim\Slim::registerAutoloader();
$app = new \Slim\Slim();
$app->config('debug', false); // ... and enables custom $app->error() handler
$app->response()->header('Content-Type', 'application/json;charset=utf-8');
$app->notFound(function () use ($app) {
  echo json_encode(Array('error'=>'Not found')); 
});
$app->error(function (\Exception $e) use ($app) {
  echo json_encode( array('exceptionMessage'=> substr($e->getMessage(),0,128).'...') ); // to much / insecure?
});
//$app->expires('+1 minutes'); // seems to be a bad idea ... :-/

// this hack lets the ember GUI clearly detect a unconfigured system. redir->setup.php.
if ( !defined('AMTC_PDOSTRING') && $app->request->getResourceUri() == '/ou-tree' ) {
  header('Content-Type: application/json;charset=utf-8');
  // index.html/ember looks for this 'special' exception/message:
  echo json_encode( array('exceptionMessage'=> 'unconfigured') ); 
  return 1; // will make it return a 200 OK anyway
}

// Initialize http://www.phpactiverecord.org/
ActiveRecord\Config::initialize(function($cfg){
  $cfg->set_model_directory('lib/db-model');
  $cfg->set_connections(
     array(
      'production' => AMTC_PDOSTRING
     )
  );
  $cfg->set_default_connection('production');
});
// fixme: how-to...
// PRAGMA foreign_keys = ON;
// ^ needed for sqlite to respect constraints
// see http://www.sqlite.org/pragma.html#pragma_foreign_keys

$phptests = array(
  //'id'   => array('description',      'test to execute, ret true/false',   'how-to-fix if failed'),
  'pdo'       => array('Check for pdo',              'return phpversion("pdo")?true:false;' , 'install php pdo module'),
  'pdo_mysql' => array('Check for MySQL support',    'return phpversion("pdo_mysql")?true:false;' , 'install php pdo mysql module'),
  'pdo_oci'   => array('Check for Oracle support',   'return phpversion("pdo_mysql")?true:false;' , 'install php pdo orcale module'),
  'pdo_pgsql' => array('Check for Postgres support', 'return phpversion("pdo_mysql")?true:false;' , 'install php pdo postgres module'),
  'pdo_sqlite'=> array('Check for SQLite support',   'return phpversion("pdo_mysql")?true:false;' , 'install php pdo sqlite module'),
  'php53'     => array('php 5.3+',                   '$v=explode(".",PHP_VERSION);return $v[0]>5||($v[0]==5&&$v[1]>2);', 'upgrade'),
  'data'      => array('data/ writable',             'return is_writable(data);',    'run chmod 777 on data/ directory'),
  //'dbconnect' => array('',   '', ''),
  //'dbwrite'   => array('',   '', ''),
);

/*****************************************************************************/
/**************** Only SLIM request handling below ***************************/
/*****************************************************************************/

//  Non-DB-Model requests 
 
// provide URI for ember-data REST adapter, based on this php script's location
$app->get('/rest-config.js', function () use ($app,$amtcwebConfigFile) {    
  sleep(1); // oh boy, just to have the splashscreen have its fun. nuts.
  $app->response->header('Content-Type', 'application/javascript;charset=utf-8');
  $path = substr($_SERVER['SCRIPT_NAME'],1);
  echo "DS.RESTAdapter.reopen({\n";
  echo " namespace: '$path'\n";
  echo "});\n";
  printf("var AMTCWEB_IS_CONFIGURED = %s;\n", 
            file_exists($amtcwebConfigFile) ? 'true' : 'false');
});

// Return static markdown help pages, json encoded
$app->get('/pages/:id', function ($id) use ($app) {
  $file = sprintf("pages/%s.md", $id);
  is_readable($file) || $app->notFound();
  $contents = file_get_contents($file);
  echo json_encode( array('page'=>array(
    'id' => $id,
    'page_name' => 'unused',
    'page_title' => 'unused',
    'page_content' => $contents
  )));  
});

$app->get('/phptests', function () use ($app, $phptests) {
  $test=Array();
  foreach ($phptests as $tid=>$test) {
    $t=array();
    $t['id'] = $tid;
    $t['description'] = $test[0];
    $tests[] = $t;
  }
  $result = array('phptests'=>$tests);
  echo json_encode( $result );
});
$app->get('/phptest/:id', function ($id) use ($app, $phptests) {
  $r=array();
  $phptests[$id] || $app->notFound();
  $r['id'] = $id;
  $t['description'] = $phptests[$id][0];
  $r['result'] = eval($phptests[$id][1]);
  echo json_encode( array('phptest'=>$r ));
});

// DB-Model requests 

/**************** Notifications / Short user messages for dashboard **********/

$app->get('/notifications', function () {
  $result = array('notifications'=>array());
  foreach (Notification::all(array("order" => "tstamp desc", 'limit' => 50)) as $record) { $result['notifications'][] = $record->to_array(); }
  echo json_encode( $result );
});

/**************** OUs / Rooms ************************************************/

$app->get('/ous', function () {
  $result = array('ous'=>array());
  foreach (OU::all() as $record) {
    $r = $record->to_array();
    $children = OU::find('all', array('conditions' => array('parent_id = ?', $r['id'])));
    $kids = array();
    foreach ($children as $childOu) {
      $kids[] = $childOu->id;
    }
    $r['children'] = $kids;
    $r['ou_path'] = $record->getPathString(); // should/could be done clientside, too
    $result['ous'][] = $r;
  }
  # relations? Book::all(array('include'=>array('author'));
  echo json_encode( $result );
});
$app->get('/ous/:id', function ($ouid) use ($app) {
  if ($ou = OU::find($ouid)) {
    echo json_encode( array('ou'=> $ou->to_array()) );
  }
});
$app->put('/ous/:id', function ($id) {
  $put = get_object_vars(json_decode(\Slim\Slim::getInstance()->request()->getBody()));
  $udev = $put['ou'];
  if ($dev = OU::find_by_id($id)) {
    $dev->name = $udev->name;
    $dev->description = $udev->description;
    $dev->parent_id = $udev->parent_id;
    $dev->optionset_id = $udev->optionset_id;
    $dev->save();
    echo json_encode( array('ou'=> $dev->to_array()) );
  }
});
$app->post('/ous', function () use ($app) {
  $post = get_object_vars(json_decode(\Slim\Slim::getInstance()->request()->getBody()));
  $ndev = $post['ou'];
  if ($dev = new OU) {
    $dev->name = $ndev->name;
    $dev->description = $ndev->description;
    $dev->parent_id = $ndev->parent_id;
    $dev->optionset_id = $ndev->optionset_id;
    $dev->save();
    echo json_encode( array('ou'=> $dev->to_array()) );
  }
});
$app->delete('/ous/:id', function ($id) {
  if ($dev = OU::find_by_id($id)) {
    OU::query('PRAGMA foreign_keys = ON;');
    $dev->delete();
    // "Note: Although after destroyRecord or deleteRecord/save the adapter 
    // expects an empty object e.g. {} to be returned from the server after
    //  destroying a record."
    // http://emberjs.com/guides/models/the-rest-adapter/
    echo '{}';
  }
});

/**************** Hosts ******************************************************/

$app->get('/hosts', function () {
  $result = array('hosts'=>array());
  foreach (Host::all(array("order" => "hostname asc")) as $record) {
    $r = $record->to_array();
    $result['hosts'][] = $r;
  }
  echo json_encode( $result );
});

/**************** AMT Optionsets *********************************************/

$app->get('/optionsets', function () {
  $result = array('optionsets'=>array());
  foreach (Optionset::all(array("order" => "name asc")) as $record) {
    $r = $record->to_array();
    $result['optionsets'][] = $r;
  }
  echo json_encode( $result );
});
$app->get('/optionsets/:id', function ($ouid) use ($app) {
  if ($os = Optionset::find($ouid)) {
    echo json_encode( array('optionset'=> $os->to_array()) );
  }
});
$app->put('/optionsets/:id', function ($id) {
  $put = get_object_vars(json_decode(\Slim\Slim::getInstance()->request()->getBody()));
  $udev = $put['optionset'];
  if ($dev = Optionset::find_by_id($id)) {
    $dev->name = $udev->name;
    $dev->description = $udev->description;
    $dev->sw_v5 = $udev->sw_v5;
    // this gets boring. improve.
    $dev->sw_dash = $udev->sw_dash;
    $dev->sw_scan22 = $udev->sw_scan22;
    $dev->sw_scan3389 = $udev->sw_scan3389;
    $dev->sw_skipcertchk = $udev->sw_skipcertchk;
    $dev->sw_usetls = $udev->sw_usetls;
    $dev->opt_cacertfile = $udev->opt_cacertfile;
    $dev->opt_maxthreads = $udev->opt_maxthreads;
    $dev->opt_passfile = $udev->opt_passfile;
    $dev->opt_timeout = $udev->opt_timeout;
    $dev->save();
    echo json_encode( array('optionset'=> $dev->to_array()) );
  }
});
$app->delete('/optionsets/:id', function ($id) {
  if ($dev = Optionset::find_by_id($id)) {
    Optionset::query('PRAGMA foreign_keys = ON;');
    $dev->delete();
    echo '{}';
  }
});
$app->post('/optionsets', function () use ($app) {
  $post = get_object_vars(json_decode(\Slim\Slim::getInstance()->request()->getBody()));
  $udev = $post['optionset'];
  if ($dev = new Optionset) {
    $dev->name = $udev->name;
    $dev->description = $udev->description;
    $dev->sw_v5 = $udev->sw_v5;
    // this gets boring. improve.
    $dev->sw_dash = $udev->sw_dash;
    $dev->sw_scan22 = $udev->sw_scan22;
    $dev->sw_scan3389 = $udev->sw_scan3389;
    $dev->sw_skipcertchk = $udev->sw_skipcertchk;
    $dev->sw_usetls = $udev->sw_usetls;
    $dev->opt_cacertfile = $udev->opt_cacertfile;
    $dev->opt_maxthreads = $udev->opt_maxthreads;
    $dev->opt_passfile = $udev->opt_passfile;
    $dev->opt_timeout = $udev->opt_timeout;
    $dev->save();
    echo json_encode( array('optionset'=> $dev->to_array()) );
  }
});

/*****************************************************************************/
/*
 *
 * ... run, forrest, run!
 */

$app->run();

