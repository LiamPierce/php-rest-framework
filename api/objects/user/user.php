<?

/*
Copyright Demon LLC
Author Liam Pierce
*/

class User {
  use \databaseModelItem;
  use \expandable;

  private $password;
  private $twofa_secret;

  public static $current;

  public static $__db_model = [
    "table"=>"settable.users",
    "columns"=>[
      "id"=>"int",
      "organization_id"=>"int",
      "name"=>"varchar",
      "email"=>"varchar",
      "email_verified"=>"tinyint",
      "password"=>"varchar",
      "security_role"=>[
        "administrator",
        "developer"
      ],
      "twofa_enabled"=>"tinyint",
      "twofa_secret"=>"varchar",
      "created"=>"datetime",
      "updated"=>"datetime"
    ],
    "primary"=>"id",
    "ids"=>[
      "id",
    ],
    "updatable"=>[
      "email_verified",
    ],
    "order"=>"id desc",
  ];

  public static function current(){
    if (isset(static::$current)){
      return static::$current;
    }

    if (isset($_SESSION["user_id"])){
      static::$current = User::fromId($_SESSION["user_id"]);
      return static::$current;
    }
  }

  public function setPassword($pw){
    $this->password = \User\Credential::hash($pw);
  }

  public function verifyEmail(){
    if ($this->email_verified != 0){
      return false;
    }

    $verificationObject = \User\Verification::create([
      "user_id"=>$this->id,
      "email"=>$this->email,
    ]);
    $verificationObject->commit();

    ob_start();
    include_once("/var/www/api/api/templates/verify.php");
    $body = ob_get_clean();

    $verification = new \SendGrid\Mail\Mail();
    $verification->setFrom("support@settable.com", "Settable");
    $verification->setSubject("Verify your email address");
    $verification->addTo($this->email, $this->name);
    $verification->addContent("text/html", $body);

    try {
      $response = sgg()->send($verification);
      return $verificationObject;
    }catch (Exception $e) {
      error_log($e);
      return false;
    }
  }

  private function comparePasswordWithHash($pw){
    return \User\Credential::verify($pw, $this->password);
  }

  public static function authenticate($email, $pw){
    $user = \User::get([
      "email"=>$email,
    ]);

    if (!$user){
      return false;
    }

    return $user->localAuthenticate($pw);
  }

  public function localAuthenticate($pw){
    if (!$this->comparePasswordWithHash($pw)){
      return false;
    }

    $_SESSION["user_id"] = $this->id;
    $_SESSION["organization_id"] = $this->organization_id;
    $_SESSION["two_factor"] = false;

    return true;
  }
}

?>
