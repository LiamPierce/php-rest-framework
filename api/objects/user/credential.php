<?

namespace User;

class Credential{

  public static function salt(){
		$newSalt = openssl_random_pseudo_bytes(50);
		$newSalt = base64_encode($newSalt);

		return $newSalt;
	}

	public static function hash($password){
		$algorithm = "pbkdf2_sha512";
		$iterations = 100000;

		$newSalt = openssl_random_pseudo_bytes(20);
		$newSalt = base64_encode($newSalt);

		$hash = hash_pbkdf2("SHA512", $password, $newSalt, $iterations, 0, true);
		$toDBStr = $algorithm ."$". $iterations ."$". $newSalt ."$". base64_encode($hash);

		return $toDBStr;
	}

	public static function verify($password,$db){
		$pieces = explode("$", $db);

		$iterations = $pieces[1];
		$salt = $pieces[2];
		$old_hash = $pieces[3];

		$hash = hash_pbkdf2("SHA512", $password, $salt, $iterations, 0, true);
		$hash = base64_encode($hash);

		return $hash == $old_hash;
	}

}
?>
