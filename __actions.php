<?

if ($_SERVER["REMOTE_ADDR"] !== "50.245.12.214"){
  http_response_code(404);
  die();
}

include_once("/var/www/api/api/api.php");

dynamic_load_module("objects", "auth");
dynamic_load_module("controllers", "auth");

switch($_GET["action"]){
  case "new-key":
    $key = \Auth\Token::create(array_merge([
      "organization_id"=>\User::current()->organization_id,
      "created_by_user_id"=>\User::current()->id,
      "token_name"=>"Pierce Key",
    ], \Auth\TokenGenerator::generate()));
    $key->commit();
    var_dump($key);
    break;
  case "generate-config":
    $pfm = \Platform::get([
      "organization_id"=>\User::current()->organization_id
    ]);
    var_dump($pfm->generateFilterConfig());
    break;
}

die();
?>
