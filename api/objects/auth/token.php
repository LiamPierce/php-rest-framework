<?

/*
Copyright Demon LLC
Author Liam Pierce
*/

namespace Auth;

class Token {
  use \databaseModelItem;
  use \expandable;

  private $token_private;

  public static $current;

  public static $__db_model = [
    "table"=>"settable.auth_tokens",
    "columns"=>[
      "id"=>"int",
      "organization_id"=>"int",
      "platform_id"=>"int",
      "created_by_user_id"=>"int",
      "token_name"=>"varchar",
      "token_public"=>"varchar",
      "token_private"=>"varchar",
      "created"=>"datetime",
      "updated"=>"datetime"
    ],
    "primary"=>"id",
    "ids"=>[
      "id",
    ],
    "updatable"=>[
      "name",
    ],
    "order"=>"id desc",
  ];

  public function __created(){

  }

  public static function current($token=null){
    if ($token){
      static::$current = $token;
      return;
    }

    return static::$current ?? false;
  }

  public function checkSignaure($string, $signature){
    return !empty($string) && hash_hmac("sha256", $string, $this->token_private) === $signature;
  }
}

?>
