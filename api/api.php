<?php

/**
 * Initialization
 *  - Apparently the autoloaders must be registered separately in both index.php and api.php
 */

// Composer Autoloader
require 'vendor/autoload.php';

// Directus Autoloader
use Symfony\Component\ClassLoader\UniversalClassLoader;
$loader = new UniversalClassLoader();
$loader->registerNamespace("Directus", dirname(__FILE__) . "/core/");
$loader->register();

// Non-autoload components
require dirname(__FILE__) . '/config.php';
require dirname(__FILE__) . '/core/db.php';
require dirname(__FILE__) . '/core/media.php';
require dirname(__FILE__) . '/core/functions.php';

// Define directus environment
defined('DIRECTUS_ENV')
    || define('DIRECTUS_ENV', (getenv('DIRECTUS_ENV') ? getenv('DIRECTUS_ENV') : 'production'));

switch (DIRECTUS_ENV) {
    case 'development':
        break;
    case 'production':
    default:
        error_reporting(0);
        break;
}

use Directus\Application;
use Directus\Auth\Provider as AuthProvider;
use Directus\Auth\RequestNonceProvider;
use Directus\Auth\RequestNonceHasntBeenProcessed;
use Directus\Collection\Users;
use Directus\Db;
use Directus\View\JsonView;

// Slim Middleware
use Directus\Middleware\MustBeLoggedIn;
use Directus\Middleware\MustHaveRequestNonce;

/**
 * Slim Bootstrap
 */

$app = new \Slim\Slim(array(
    'mode'    => DIRECTUS_ENV,
    'log.writer' => new \Slim\Extras\Log\DateTimeFileWriter()
));

$app->configureMode('production', function () use ($app) {
    $app->config(array(
        'log.enable' => true,
        'debug' => false
    ));
});

$app->configureMode('development', function () use ($app) {
    $app->config(array(
        'log.enable' => false,
        'debug' => true
    ));
});

// Custom global accessor for Slim application object
Application::setApp($app);

// Version shortcut for routes:
$v = API_VERSION;

/**
 * Middleware
 */

/* URL patterns which will not be protected by the following middleware */
$routeWhitelist = array("/^\/?$v\/auth\/?/");

$authProvider = new AuthProvider();
$app->add(new MustBeLoggedIn($routeWhitelist, $authProvider));

$requestNonceProvider = new RequestNonceProvider();
$app->add(new MustHaveRequestNonce($routeWhitelist, $requestNonceProvider));

/**
 * Globals
 */

/**
 * DB Transitional:
 *   Initialize ZendDb, then extract the PDO object.
 *   Insert the PDO object into the DB class and leave it where it was.
 *   This way we can smoothly transition to using the Zend-structured DB-layer.
 */
$dbConfig = array(
    'driver'    => 'Pdo_Mysql',
    'database'  => DB_NAME,
    'username'  => DB_USER,
    'password'  => DB_PASSWORD
);
$ZendDb = new \Zend\Db\Adapter\Adapter($dbConfig);
$connection = $ZendDb->getDriver()->getConnection();
$connection->connect();
$PDO = $connection->getResource();
$db = new \DB($PDO, DB_NAME);

$params = $_GET;
$requestPayload = json_decode($app->request()->getBody(), true);

/**
 * Extension Alias
 */

if(isset($_REQUEST['run_extension']) && $_REQUEST['run_extension']) {
    // Validate extension name
    $extensionName = $_REQUEST['run_extension'];
    if(!Application::extensionExists($extensionName)) {
        header("HTTP/1.0 404 Not Found");
        return JsonView::render(array('message' => 'No such extension.'));
    }
    // Validate request nonce
    if(!$requestNonceProvider->requestHasValidNonce()) {
        if('development' !== DIRECTUS_ENV) {
            header("HTTP/1.0 401 Unauthorized");
            return JsonView::render(array('message' => 'Unauthorized (nonce).'));
        }
    }
    $extensionsDirectory = APPLICATION_PATH . "/extensions";
    $responseData = require "$extensionsDirectory/$extensionName/api.php";
    $nonceOptions = $requestNonceProvider->getOptions();
    $newNonces = $requestNonceProvider->getNewNoncesThisRequest();
    header($nonceOptions['nonce_response_header'] . ': ' . implode($newNonces, ","));
    return JsonView::render($responseData);
}

/**
 * Slim Routes
 * (Collections arranged alphabetically)
 */

/**
 * AUTHENTICATION
 */

$app->post("/$v/auth/login/?", function() use ($app, $authProvider, $requestNonceProvider) {
    $response = array(
        'message' => "Wrong username/password.",
        'success' => false,
        'all_nonces' => $requestNonceProvider->getAllNonces()
    );
    if($authProvider::loggedIn()) {
        $response['success'] = true;
        return JsonView::render($response);
    }
    $req = $app->request();
    $email = $req->post('email');
    $password = $req->post('password');
    $user = Users::findOneByEmail($email);
    if(!$user) {
        return JsonView::render($response);
    }
    $response['success'] = $authProvider
        ->login($user['id'], $user['password'], $user['salt'], $password);
    if($response['success'])
        unset($response['message']);
    JsonView::render($response);
});

$app->get("/$v/auth/logout/?", function() use ($app, $authProvider) {
    if($authProvider::loggedIn())
        $authProvider::logout();
    $app->redirect(DIRECTUS_PATH . "login.php");
});

$app->get("/$v/auth/nonces/?", function() use ($app, $requestNonceProvider) {
    $all_nonces = $requestNonceProvider->getAllNonces();
    $response = array('nonces' => $all_nonces);
    JsonView::render($response);
});

/**
 * ACTIVITY COLLECTION
 */

$app->get("/$v/activity/?", function () use ($db, $ZendDb) {
    $Activity = new Db\Activity('directus_activity', $ZendDb);
    $new_get = $Activity->fetchFeed();
    $old_get = $db->get_activity();
    JsonView::render($new_get, $old_get);
});

/**
 * COLUMNS COLLECTION
 */

// GET all table columns, or POST one new table column

$app->map("/$v/tables/:table/columns/?", function ($table) use ($db, $params, $requestPayload, $app) {
    $params['table_name'] = $table;
    if($app->request()->isPost()) {
        /* @TODO improves readability: use two separate methods for fetching one vs all entries */
        $params['column_name'] = $db->add_column($table, $requestPayload); // NOTE Alters the behavior of db#get_table below
    }
    $response = $db->get_table($table, $params);
    JsonView::render($response);
})->via('GET', 'POST');

// GET or PUT one column

$app->map("/$v/tables/:table/columns/:column/?", function ($table, $column) use ($db, $params, $requestPayload, $app) {
    $params['column_name'] = $column;
    $params['table_name'] = $table;
    // Add table name to dataset. @TODO more clarification would be useful
    foreach ($requestPayload as &$row) {
        $row['table_name'] = $table;
    }
    if($app->request()->isPut()) {
        $db->set_entries('directus_columns', $requestPayload);
    }
    $response = $db->get_table($table, $params);
    JsonView::render($response);
})->via('GET', 'PUT');

/**
 * ENTRIES COLLECTION
 */

$app->map("/$v/tables/:table/rows/?", function ($table) use ($db, $ZendDb, $params, $requestPayload, $app) {
    $id = null;
    $params['table_name'] = $table;
    switch($app->request()->getMethod()) {
        // POST one new table entry
        case 'POST':
            $id = $db->set_entry_relational($table, $requestPayload);
            $params['id'] = $id;
            break;
        // PUT a change set of table entries
        case 'PUT':
            $db->set_entries($table, $requestPayload);
            break;
    }
    // GET all table entries
    $get_old = $db->get_entries($table, $params);
    $Table = new Db\TableGateway($table, $ZendDb);
    $get_new = $Table->getEntries($params);
    JsonView::render($get_new, $get_old);
})->via('GET', 'POST', 'PUT');

$app->map("/$v/tables/:table/rows/:id/?", function ($table, $id) use ($db, $ZendDb, $params, $requestPayload, $app) {
    $params['table_name'] = $table;
    switch($app->request()->getMethod()) {
        // PUT an updated table entry
        case 'PUT':
            $db->set_entry_relational($table, $requestPayload);
            break;
        // DELETE a given table entry
        case 'DELETE':
            echo $db->delete($table, $id);
            return;
    }
    $params['id'] = $id;
    // GET a table entry
    $get_old = $db->get_entries($table, $params);
    $Table = new Db\TableGateway($table, $ZendDb);
    $get_new = $Table->getEntries($params);
    JsonView::render($get_new, $get_old);
})->via('DELETE', 'GET', 'PUT');

/**
 * GROUPS COLLECTION
 */

/** (Optional slim route params break when these two routes are merged) */

$app->get("/$v/groups/?", function () use ($db, $ZendDb) {
    // @TODO need POST and PUT
    $get_old = $db->get_entries("directus_groups");
    $Groups = new Db\TableGateway('directus_groups', $ZendDb);
    $get_new = $Groups->getEntries();
    JsonView::render($get_new, $get_old);
});

$app->get("/$v/groups/:id/?", function ($id = null) use ($db, $ZendDb) {
    // @TODO need POST and PUT
    // Hardcoding ID temporarily
    is_null($id) ? $id = 1 : null;
    $get_old = $db->get_group($id);
    $Groups = new Db\TableGateway('directus_groups', $ZendDb);
    $get_new = $Groups->find($id);
    JsonView::render($get_new, $get_old);
});

/**
 * MEDIA COLLECTION
 */

$app->map("/$v/media(/:id)/?", function ($id = null) use ($db, $ZendDb, $params, $requestPayload, $app) {

    if(!is_null($id))
        $params['id'] = $id;

    // A URL is specified. Upload the file
    if (isset($requestPayload['url']) && $requestPayload['url'] != "") {
        $media = new Media($requestPayload['url'], RESOURCES_PATH);
        $media_data = $media->data();
        $requestPayload['type'] = $media_data['type'];
        $requestPayload['charset'] = $media_data['charset'];
        $requestPayload['size'] = $media_data['size'];
        $requestPayload['width'] = $media_data['width'];
        $requestPayload['height'] = $media_data['height'];
        $requestPayload['name'] = $media_data['name'];
        $requestPayload['date_uploaded'] = $media_data['date_uploaded'];
        if (isset($media_data['embed_id'])) {
            $requestPayload['embed_id'] = $media_data['embed_id'];
        }
    }

    if (isset($requestPayload['url']))
        unset($requestPayload['url']);

    /** Attribute these actions to the authenticated user. */
    if(!empty($requestPayload) && !is_numeric_array($requestPayload)) {
        $currentUser = AuthProvider::getUserInfo();
        $requestPayload['user'] = $currentUser['id'];
    }

    $table = "directus_media";
    switch ($app->request()->getMethod()) {
        case "POST":
            $requestPayload['date_uploaded'] = gmdate('Y-m-d H:i:s');
            $params['id'] = $db->set_media($requestPayload);
            break;
        case "PATCH":
            $requestPayload['id'] = $id;
        case "PUT":
            if (!is_null($id)) {
                $db->set_entries($table, $requestPayload);
                break;
            }
            $db->set_media($requestPayload);
            break;
    }

    $get_old = $db->get_entries($table, $params);
    $Media = new Db\TableGateway($table, $ZendDb);
    $get_new = $Media->getEntries($params);
    JsonView::render($get_new, $get_old);
})->via('GET','PATCH','POST','PUT');

/**
 * PREFERENCES COLLECTION
 */

$app->map("/$v/tables/:table/preferences/?", function($table) use ($db, $ZendDb, $params, $requestPayload, $app) {
    $params['table_name'] = $table;
    switch ($app->request()->getMethod()) {
        case "PUT":
            //This data should not be hardcoded.
            $id = $requestPayload['id'];
            $db->set_entry('directus_preferences', $requestPayload);
            //$db->insert_entry($table, $requestPayload, $id);
            break;
        case "POST":
            // This should not be hardcoded, needs to be corrected
            $requestPayload['user'] = 1;
            $id = $db->insert_entry($table, $requestPayload);
            $params['id'] = $id;
            break;
    }
    $currentUser = AuthProvider::getUserInfo();
    $get_old = $db->get_table_preferences($currentUser['id'], $table);
    $Preferences = new Db\Preferences('directus_preferences', $ZendDb);
    $get_new = $Preferences->fetchByUserAndTable($currentUser['id'], $table);
    JsonView::render($get_new, $get_old);
})->via('GET','POST','PUT');

/**
 * REVISIONS COLLECTION
 */

$app->get("/$v/tables/:table/rows/:id/revisions/?", function($table, $id) use ($db, $ZendDb, $params) {
    $params['table_name'] = $table;
    $params['id'] = $id;
    $get_old = $db->get_revisions($params);
    $Activity = new Db\Activity('directus_activity', $ZendDb);
    $get_new = $Activity->fetchRevisions($id, $table);
    JsonView::render($get_new, $get_old);
});

/**
 * SETTINGS COLLECTION
 */

$app->map("/$v/settings(/:id)/?", function ($id = null) use ($db, $ZendDb, $params, $requestPayload, $app) {
    switch ($app->request()->getMethod()) {
        case "POST":
        case "PUT":
            $db->set_settings($requestPayload);
            break;
    }

    $settings_old = $db->get_settings();
    $get_old = is_null($id) ? $settings_old : $settings_old[$id];

    $Settings = new Db\Settings('directus_settings', $ZendDb);
    $settings_new = $Settings->fetchAll();
    $get_new = is_null($id) ? $settings_new : $settings_new[$id];

    JsonView::render($get_new, $get_old);
})->via('GET','POST','PUT');

/**:
 * TABLES COLLECTION
 */

// GET table index
$app->get("/$v/tables/?", function () use ($db, $params, $requestPayload) {
    $response = $db->get_tables($params);
    JsonView::render($response);
})->name('table_index');

// GET and PUT table details
$app->map("/$v/tables/:table/?", function ($table) use ($db, $ZendDb, $params, $requestPayload, $app) {
    /* PUT updates the table */
    if($app->request()->isPut()) {
        $db->set_table_settings($requestPayload);
    }
    $response = $db->get_table_info($table, $params);

    // GET all table entries

    // New
    $Table = new Db\TableGateway($table, $ZendDb);
    $response_new = $Table->getEntries($params);

    // Old
    $response_old = $db->get_entries($table, $params);

    JsonView::render($response_new, $response_old);

})->via('GET', 'PUT')->name('table_detail');

/**
 * UPLOAD COLLECTION
 */

$app->post("/$v/upload/?", function () use ($db, $params, $requestPayload, $app) {
    $result = array();
    foreach ($_FILES as $file) {
      $media = new Media($file, RESOURCES_PATH);
      array_push($result, $media->data());
    }
    JsonView::render($result);
});

/**
 * USERS COLLECTION
 */

// GET user index
$app->get("/$v/users/?", function () use ($db, $ZendDb, $params, $requestPayload) {

    $Users = new Db\Users("directus_users", $ZendDb);
    $new = $Users->fetchAllWithGroupData();

    $old = $db->get_users();

    JsonView::render($new, $old);

})->name('user_index');

// POST new user
/**
 * Appearances suggest that this route is not used, & that this one is used instead:
 *     POST /directus/api/1/tables/directus_users/rows
 * @todo  Confirm & prune this route
 */
$app->post("/$v/users/?", function() use ($db, $ZendDb, $params, $requestPayload) {
    $table = 'directus_users';
    $id = $db->set_entries($table, $params);

    $params['id'] = $id;
    $old = $db->get_entries($table, $params);

    $Users = new Db\Users("directus_users", $ZendDb);
    $new = $Users->find($id);

    JsonView::render($new, $old);
})->name('user_post');

// GET or PUT a given user
$app->map("/$v/users/:id/?", function ($id) use ($db, $ZendDb, $params, $requestPayload, $app) {
    $table = 'directus_users';
    $params['id'] = $id;
    if($app->request()->isPut()) {
        $db->set_entry($table, $requestPayload);
    }

    $app->getLog()->info("IGNORE the following comparison failure. It is due to a buggy date field in the \"old\" response.");

    $old_get = $db->get_entries($table, $params);

    $Users = new Db\Users("directus_users", $ZendDb);
    $new_get = $Users->find($id);

    JsonView::render($new_get, $old_get);
})->via('GET', 'PUT');

/**
 * UI COLLECTION
 */

// $app->map("/$v/tables/:table/ui/?", function($table) use ($db, $params, $requestPayload, $app) {
//     $params['table_name'] = $table;
//     switch ($app->request()->getMethod()) {
//       case "PUT":
//       case "POST":
//         $db->set_ui_options($requestPayload, $table, $params['column_name'], $params['ui_name']);
//         break;
//     }
//     $response = $db->get_ui_options($table, $params['column_name'], $params['ui_name']);
//     JsonView::render($response);
// })->via('GET','POST','PUT');

$app->map("/$v/tables/:table/columns/:column/:ui/?", function($table, $column, $ui) use ($db, $ZendDb, $params, $requestPayload, $app) {
    $params['table_name'] = $table;
    $params['column_name'] = $column;
    $params['ui_name'] = $ui;
    switch ($app->request()->getMethod()) {
      case "PUT":
      case "POST":
        $db->set_ui_options($requestPayload, $table, $column, $ui);
        break;
    }
    $get_old = $db->get_ui_options($table, $column, $ui);
    $UiOptions = new Db\UiOptions('directus_ui', $ZendDb);
    $get_new = $UiOptions->fetchOptions($table, $column, $ui);
    JsonView::render($get_old, $get_new);
})->via('GET','POST','PUT');


/**
 * Run the Router
 */

if(isset($_GET['run_api_router']) && $_GET['run_api_router']) {
    try {
        // Run Slim
        $app->response()->header('Content-Type', 'application/json; charset=utf-8');
        $app->run();
    } catch (DirectusException $e){
        switch ($e->getCode()) {
            case 404:
                header("HTTP/1.0 404 Not Found");
                echo json_encode($e->getMessage());
                break;
        }
    } catch (Exception $e) {
        header("HTTP/1.1 500 Internal Server Error");
        if('production' != DIRECTUS_ENV) {
            echo print_r($e, true);
            //echo format_json(json_encode($e));
            //echo $e->getCode();
            //echo $e->getMessage();
        }
    }
}