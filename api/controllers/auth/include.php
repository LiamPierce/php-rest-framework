<?

include_once("token-generator.php");

class Auth{
  public const UnverifiedUser = "user";         //Any user, verified or not.
  public const User = "user-verified";          //The user has verified their email.

  public const Key = "key";                     //Just the public API key.
  public const KeySignature = "key-signature";  //The public API key and the signature header that signs the requset.
}

?>
