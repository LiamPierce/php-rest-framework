<?

namespace Auth;

class OTP{
	private $secret;
	static function create($secret){
		return new self($secret);
	}

	function __construct($secret){
		$this->secret = $secret;
	}

	function generate(){
		$hash = hash_hmac("sha1", $this->intToBytestring(floor(time() / 30)), Base32::decode($this->secret));
		foreach(str_split($hash, 2) as $hex) {
			$hmac[] = hexdec($hex);
		}
		$offset = $hmac[19] & 0xf;
		$code = ($hmac[$offset+0] & 0x7F) << 24 |
			($hmac[$offset + 1] & 0xFF) << 16 |
			($hmac[$offset + 2] & 0xFF) << 8 |
			($hmac[$offset + 3] & 0xFF);
		$code = $code % pow(10, 6);
		return str_repeat("0",6 - strlen(strval($code))).$code;
	}

	public function intToBytestring($int) {
		$result = [];
		while($int != 0) {
			$result[] = chr($int & 0xFF);
			$int >>= 8;
		}
		return str_pad(join(array_reverse($result)), 8, "\000", STR_PAD_LEFT);
    }
}

?>
