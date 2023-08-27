<?

class PTimer{
	public static $timers = [];
	public static $results = [];

	static function start($index){
		self::$timers[$index] = hrtime(true);
	}

	static function stop($index){
		$elapsed = (hrtime(true) - self::$timers[$index]) / 1e+6;
		static::$results[$index] = $elapsed;
		error_log("Timer $index stopped after ".$elapsed." milliseconds.");
	}

	static function get($index){
		return static::$results[$index] ?? 0;
	}

	static function sendTimingHeader(){
		$header = "Server-Timing: ";

		foreach (static::$results as $k=>$v){
			$header.="$k; dur=$v, ";
		}

		header($header);
	}
}

?>
