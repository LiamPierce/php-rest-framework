<?

/*
Copyright Demon LLC
Author Liam Pierce
*/

namespace User;

class Verification {
  use \databaseModelItem;
  use \expandable;

  public static $__db_model = [
    "table"=>"settable.user_verifications",
    "columns"=>[
      "id"=>"int",
      "user_id"=>"int",
      "email"=>"varchar",
      "code"=>"varchar",
      "token"=>"varchar",
      "created"=>"datetime",
    ],
    "primary"=>"id",
    "ids"=>[
      "id",
      "user_id",
    ],
    "updatable"=>[
      "verified",
    ],
    "order"=>"id desc",
  ];

  public static $__expandable = [
    "user"
  ];


  private function expandify($key,$force=false){
		if (!$force && isset($this->{$key})){
			return false;
		}

    switch($key){
      case "user":
        $this->user = \User::fromId($this->user_id);
        break;
    }
  }

  public function __create(){
    $newSalt = openssl_random_pseudo_bytes(40);
		$newSalt = hash("sha512", $newSalt);

    $this->token = $newSalt;
    $this->code = mt_rand(1111111, 9999999);
  }
}

?>
