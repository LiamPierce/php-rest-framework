<?

include_once("fundamentals/include.php");

\PTimer::start("api-overhead");

ini_set('default_charset', 'utf-8');
date_default_timezone_set("UTC");

/*session_set_cookie_params([
  'lifetime' => 600,
  'path' => '/',
  'domain' => $_SERVER['HTTP_HOST'] === "api.settable.io" ? "settable.io" : "settable.com",
  'secure' => true,
]);*/
session_start();


$GLOBALS["release"] = 61;
$GLOBALS["nonce"] = hash('sha256',bin2hex(random_bytes(10)));

$GLOBALS["settings"] = new stdClass();

$security_policy = [
	"default-src"=>[
		"*",
		"'self'",
		"*.demondms.com",
		"blob:",
	],
	"img-src"=>[
		"*",
		"*.demondms.com",
		"data:"
	],
	"script-src"=>[
		"'self'",
		"*.demondms.com",
		"*.stage.demondms.com",
		"*.search.demondms.com",
		"https://www.googletagmanager.com",
		"https://kit.fontawesome.com",
		"https://ajax.googleapis.com",
		"https://www.gstatic.com/",
		"https://www.google-analytics.com",
		"https://js.stripe.com",
		"https://cdn.tiny.cloud",
		"https://cdn.plaid.com",
		"nonce-{$GLOBALS["nonce"]}",
	],
	"style-src"=>[
		"'unsafe-inline'",
		"'self'",
		"*.demondms.com",
	],
	"font-src"=>[
		"*",
		"*.demondms.com",
	],
	"frame-src"=>[
		"'self'",
		"*.demondms.com",
		"blob:",
	],
	"connect-src"=>[
		"*",
		"'self'",
		"wss://*.demondms.com:8080/",
		"blob:"
	],
];

$policy = "";
foreach ($security_policy as $index=>$category){
	$policy .= " $index ";
	foreach ($category as $k=>$v){
		$policy .= "$v ";
	}
	$policy .= ";";
}

header("Content-Security-Policy: $policy");
header('Cache-Control: no-cache, no-store, must-revalidate');

header("Access-Control-Allow-Origin: *");

if (isset($_SERVER["HTTP_ORIGIN"])){
	header("Access-Control-Allow-Origin: ".$_SERVER["HTTP_ORIGIN"]);
}else{

}

header("Access-Control-Allow-Credentials: true");
header("Access-Control-Max-Age: 7200");
header("Access-Control-Allow-Headers: Content-Type, Authorization, Content-Length, X-Requested-With");
header("Access-Control-Allow-Methods: HEAD,GET,PUT,POST,DELETE,OPTIONS");

header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-Xss-Protection: 1; mode=block");

header('Pragma: no-cache');
header('Expires: 0');

function denyAccess(){
	ob_end_clean();
	header("HTTP/1.1 403 FORBIDDEN");
}

function dynamic_load_module(String $type, String $module="", $extend=false){

	if ($extend){
		$run = "";
		foreach (explode("/", $module) as $k=>$path){
			$run = $run ? "$run/$path" : $path;
			//error_log("/var/www/api/api/".implode("/", array_filter([$type,$run]))."/include.php");
			@include_once("/var/www/api/api/".implode("/", array_filter([$type,$run]))."/include.php");
		}
	}else{
		@include_once("/var/www/api/api/".implode("/", array_filter([$type, $module]))."/include.php");
	}
}

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS"){
	\PTimer::stopAll();
	http_response_code(200);
	die();
}

dynamic_load_module("traits");       // Load traits first since they contain information to build objects.
dynamic_load_module("objects");		 	 // Next load objects that are loaded by default.
dynamic_load_module("controllers");  // Finally load the controllers.


/*     						   */
/*    Config Load    */
/* 									 */

$config = parse_ini_file('/home/settable/config/settable.ini', true, INI_SCANNER_RAW);

/*     						  */
/*  Database Setup  */
/* 									*/

function createDatabase($config){

	$database = new \Database\DB(new \Database\Credential(
		"localhost",
		$config["database"]["user"],
		$config["database"]["password"]
	));

	$database->connect("settable");

	return $database;
}

$GLOBALS["DB"] = createDatabase($config);

function db(){
	return $GLOBALS["DB"];
}

require '/home/settable/packages/vendor/autoload.php';

$GLOBALS["REDIS"] = new Predis\Client();

function redis(){
	return $GLOBALS["REDIS"];
}

function sgg(){
	global $config;
	return new \SendGrid($config["email"]["sendgrid_api_key"]);
}

?>
