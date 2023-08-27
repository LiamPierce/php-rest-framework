<?

namespace Auth;

class Credential{

  public static function current(){
    return \Auth\Token::current() ?: \User::current();
  }
}

?>
