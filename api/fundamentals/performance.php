<?

/*

Copyright Demon LLC
Author: Liam Pierce

*/

class PTimer{
	public static $timers = [];
	public static $results = [];

	static function start($index){
		static::$timers[$index] = microtime(true);
	}

	static function stop($index){
		$elapsed = (microtime(true) - self::$timers[$index]) * 1e3;
		static::$results[$index] = $elapsed;
		error_log("Timer $index stopped after ".$elapsed." milliseconds.");
	}

	static function stopAll(){
		foreach (array_keys(self::$timers) as $k=>$index){
			static::stop($index);
		}
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
