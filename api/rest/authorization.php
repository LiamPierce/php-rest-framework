<?

class authorization{
	
	public $public_identifier;
	private $credential;
	private $rule;
	
	static function RandomToken($length = 32){
		if(!isset($length) || intval($length) <= 8 ){
		  $length = 32;
		}

		if (function_exists('random_bytes')) {
			return bin2hex(random_bytes($length));
		}
		if (function_exists('mcrypt_create_iv')) {
			return bin2hex(mcrypt_create_iv($length, MCRYPT_DEV_URANDOM));
		}
		if (function_exists('openssl_random_pseudo_bytes')) {
			return bin2hex(openssl_random_pseudo_bytes($length));
		}
	}
	
	static function create($features=array()){
		$prefix = authorization::RandomToken(15);
		$post   = authorization::RandomToken(50);
		
		db()->query("insert into api_credentials (prefix,private,features) values (?,?,?)",[
			$prefix,
			$prefix.".".$post,
			json_encode($features)
		]);
		$id = db()->insert_id();
		
		$public = hash('sha256',hash('sha512',$id).hash('sha512',$prefix.".".$post));
		db()->query("update api_credentials set public=? where id=?",[$public,$id]);
	}
	
	function __construct($public){
		$this->public_identifier = $public;
	}
	
	function rules($rules){
		if (is_bool($rules) && $rules){
			$this->rule = "enforce";
		}else if (is_array($rules) && isset($rules["valid"]) && !isset($rules["features"])){
			$this->rule = "enforce-valid";
		}
	}
	
	function verifyFeature($feature){
		if ($this->rule === "enforce-valid"){
			return true;
		}
		
		$features = json_decode($this->credential["features"],true);
		foreach ($features as $k=>$v){
			if (preg_match("/".preg_replace("/\//","\/",$v)."/",$feature)){
				
				return true;
			}
		}
		return false;
	}
	
	function verifySignature($signature,$algo){
		unset($_REQUEST["public_authorization"]);
		unset($_REQUEST["signature"]);
		unset($_REQUEST["algo"]);
		unset($_REQUEST["resource"]);
		unset($_REQUEST["sessionid"]);

		if ($signature !== hash_hmac($algo,trim(json_encode($_REQUEST)),$this->credential["private"])){
			return false;
		}
		return true;
	}
	
	function verifyFor($feature,$signature,$algo='sha512'){
		db()->query("select features,private from api_credentials where public=?",[$this->public_identifier]);
		$authExists = db()->get_one();
		
		if (!$authExists){
			return false;
		}
		
		$this->credential = $authExists;
		
		if ($this->verifyFeature($feature)){
			return $this->verifySignature($signature,$algo);
		}
		return false;
	}
	
	
}


?>