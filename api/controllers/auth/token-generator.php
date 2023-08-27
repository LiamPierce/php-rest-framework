<?

/*
Copyright Demon LLC
Author Liam Pierce
*/

namespace Auth;

class TokenGenerator {

  static function generate(){

    include_once($_SERVER["DOCUMENT_ROOT"]."/api/fundamentals/base32.php");

    $pbk = str_replace(["/", "+", "="], ["0", 'L', "k"], substr(base64_encode(openssl_random_pseudo_bytes(45)), 25));
    $pvt = str_replace(["/", "+", "="], ["0", 'L', "k"], base64_encode(openssl_random_pseudo_bytes(64)));

    return [
      "token_public"=>"stbl_pk_".$pbk,
      "token_private"=>"stbl_sk_".$pvt
    ];
  }
}

?>
