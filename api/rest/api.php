<?

/*

Author: Liam Pierce

*/

include_once("file.php");

use bandwidthThrottle\tokenBucket\Rate;
use bandwidthThrottle\tokenBucket\TokenBucket;
use bandwidthThrottle\tokenBucket\storage\PredisStorage;

if (!isset($_REQUEST['resource'])){
	header("HTTP/1.1 403 FORBIDDEN");
}

$resource = preg_replace("/^rest/","",$_REQUEST['resource']);

global $restfulStack;
global $credentials;

$headers = apache_request_headers();

if (isset($headers["Content-Type"]) && preg_match("/^application\/json/",$headers["Content-Type"])){
	if ($data = json_decode(file_get_contents("php://input"),true)){
		if (is_array($data)){
			$_REQUEST = $data;
		}
	}

	$_REQUEST = array_merge($_REQUEST, $_FILES ?? []);
}else{
	parse_str(file_get_contents("php://input"),$_PARAM);
	$_REQUEST = array_merge($_REQUEST,$_PARAM ?? [], $_FILES ?? []);
}

global $inhibitor;
$inhibitor = 0;

function inhibit(){
	global $inhibitor;
	$inhibitor += 1;
}

global $last_response;
$last_response = null;

function get_last_response(){
	global $last_response;
	return $last_response;
}

$addition = array();
function respond($code=200){
	global $inhibitor;
	global $last_response;
	if (!is_int($code)){
		throw new Exception("Invalid response code.");
		$code = 500;
	}

	$data = [];
	$args = func_get_args();
	array_shift($args);
	foreach ($args as $k=>$v){
		$data = array_merge($data,(array) $v);
	}

	if (!is_null($data)){
		$data["status_code"] = $code;
		if ($code >= 300){
			db()->close();
			$data["success"] = false;

			http_response_code($code);
			echo json_encode($data,JSON_UNESCAPED_UNICODE);

			\PTimer::stopAll();
			\PTimer::sendTimingHeader();

			die();
		}else{
			if (!isset($data["success"])){
				$data["success"] = true;
			}
		}
	}

	if ($inhibitor > 0){
		$inhibitor--;
		$last_response = $data;
		return;
	}

	db()->close();

	http_response_code($code);

	rest::trigger($data);
	if (empty(rest::$triggers)){
		die();
	}
}



class rest{
	public static $scope = [];

	public static $triggers = [];

	public static function trigger($data){
		$triggerable = array_pop(self::$triggers);
		$triggerable($data);
	}

	public static function passthrough($url_m){
		$HANDLE = curl_init($url_m);

		$requestData = $_REQUEST;

		$requestData["resource"] = null;

		curl_setopt($HANDLE,CURLOPT_RETURNTRANSFER,1);
		curl_setopt($HANDLE,CURLOPT_POST, count($requestData));
		curl_setopt($HANDLE,CURLOPT_POSTFIELDS, http_build_query($requestData));

		$result = curl_exec($HANDLE);
		//var_dump($result);

		curl_close($HANDLE);

		return json_decode($result,true);
	}

	public static function checkCredential($credential){
		global $credentials;
		if (strpos($credential, "scope://") !== false){
			if (Credential::authenticated() && Credential::get()->hasScopePath(substr($credential, 7))){
				return true;
			}else{
				return false;
			}
		}else{
			if (isset($credentials[$credential]) && $credentials[$credential]()){
				return true;
			}else{
				return false;
			}
		}
	}

	public static function evaluate($method,$resource,$requestData,$callback=null){
		global $restfulStack;
		global $credentials;

		if (!$callback){
			inhibit();
		}

		error_log($method);
		error_log($resource);

		/* Performance increase for non-regex endpoints. */
		if (isset($restfulStack["/".$resource])){
			$resfulStack = [
				"/$resource"=>$restfulStack["/".$resource],
			];
		}

		foreach ($restfulStack as $match=>$onMatch){
			//Resource Match
			if (!$resource){
				$resource = "/";
			}
			if (!preg_match("/^".preg_replace("/\//","\/",$match)."\/?$"."/",$resource[0] === "/" ? $resource : "/".$resource,$matchData)){
				continue;
			}

			//HTTP Method.
			if (!isset($onMatch[strtolower($method)])){
				respond(400,["message"=>"HTTP method incorrect."]);
			}

			$methodBucket = $onMatch[strtolower($method)];

			/* Method change redirect. */
			if (gettype($methodBucket) === "string"){
				$methodBucket = $onMatch[strtolower($methodBucket)];
			}

			/* Endpoint wide rate limit. */
			if (isset($onMatch["rate"])){
				$methodBucket["rate"] = @($methodBucket["rate"] ?? $onMatch["rate"]);
			}

			if (gettype($methodBucket) === "object"){
				$methodBucketTemp = [];
				$methodBucketTemp["execute"] = $methodBucket;
				$methodBucketTemp["params"] = $onMatch[strtolower($method)."-request"]??[];
				$methodBucketTemp["lazy-params"] = $onMatch["lazy-".strtolower($method)]??[];
				$methodBucketTemp["credentials"] = $onMatch[strtolower($method)."-credentials"]??($onMatch["credentials"]??[]);
				$methodBucketTemp["rate"] = $onMatch[strtolower($method)."-rate"]??120;

				$methodBucket = $methodBucketTemp;
			}

			/*                 */
			/*  Rate Limiting  */
			/*  					 		 */

			global $inhibitor;

			$rate_limit_key = Organization::current() ? Organization::current()->id : $_SERVER["REMOTE_ADDR"];

			//Endpoint level rate limit.
			if (isset($methodBucket["rate"]) && !empty($methodBucket["rate"])){
				$rate        = 60;
				$rate_bucket = 20;
				$bootstrap   = 20;

				if (is_string($methodBucket["rate"]) || is_int($methodBucket["rate"]) || is_float($methodBucket["rate"])){
					$rate = $methodBucket["rate"];
				}else if (is_array($methodBucket["rate"])){
					$rate = $methodBucket["rate"]["limit"] ?? 60;
					$rate_bucket = @($methodBucket["rate"]["bucket"] ?? 20);
					$bootstrap = @($methodBucket["rate"]["bootstrap"] ?? $rate_bucket);
				}

				$endpointLevelBucket = new TokenBucket($rate_bucket, new Rate(floatVal($rate), Rate::MINUTE), new PredisStorage("$rate_limit_key $method $match", redis()));
				$endpointLevelBucket->bootstrap($bootstrap);

				if (!$endpointLevelBucket->consume(1, $seconds)){
					error_log("Rate limit hit for ".$_SERVER["REMOTE_ADDR"]);
					header(sprintf("Retry-After: %f", round($seconds,2)));
					respond(429, ["message"=>"Too many requests. Trying again in ".round($seconds,2)." seconds.", "rate_limit_scope"=>"Endpoint Only"]);

				  exit();
				}
			}

			//Global rate limit.
			$globalLevelBucket = new TokenBucket(100, new Rate(60, Rate::MINUTE), new PredisStorage($rate_limit_key, redis()));
			$globalLevelBucket->bootstrap(10);

			if (!$globalLevelBucket->consume(1, $seconds)){
				error_log("Global rate limit hit for ".$_SERVER["REMOTE_ADDR"]);
				header(sprintf("Retry-After: %f", round($seconds,2)));
				respond(429, ["message"=>"Too many requests. Trying again in ".round($seconds,2)." seconds.", "rate_limit_scope"=>"Endpoint Only", "rate_limit_id"=>$_SERVER["REMOTE_ADDR"]." $method $match"]);
				exit();
			}


			/*                    */
			/*  Credential Check  */
			/*  					 		    */

			$mtype = gettype($onMatch[strtolower($method)]);

			if ($mtype === "string"){
				$method = $onMatch[strtolower($method)];
				$mtype = gettype($onMatch[strtolower($method)]);
			}

			$authorityCheck = [];
			if ($mtype == "array"){
				$authorityCheck = $onMatch[strtolower($method)]["credentials"] ?? ($onMatch["credentials"] ?? []);
			}else{
				$authorityCheck = $onMatch[strtolower($method)."-credentials"] ?? ($onMatch["credentials"] ?? []);
			}

			$authorized = empty($authorityCheck);
			foreach ($authorityCheck as $k=>$v){
				if (is_string($v)){
					if (rest::checkCredential($v)){
						$authorized = true;
						break;
					}
				}else if (is_array($v)){
					if (empty($v)){
						respond(500,["message"=>"Empty credential set."]);
					}

					$lauth = true;
					foreach ($v as $_=>$sub){
						if (!rest::checkCredential($v)){
							$lauth = false;
						}
					}
					if ($lauth){
						$authorized = true;
						break;
					}
				}else{
					respond(500,["message"=>"Invalid credential type."]);
				}
			}

			if (!$authorized){
				respond(401,["message"=>"Insufficient credentials."]);
			}

			if (count($onMatch["assign"] ?? []) != count($matchData) - 1){
				respond(400,["message"=>"URL Assignments unmatched."]);
			}

			if (isset($methodBucket["preflight"]) && is_callable($methodBucket["preflight"])){
				$methodBucket["preflight"]();
			}

			$requested;
			$lazy;

			$requested = $methodBucket["params"] ?? [];
			$lazy 		 = $methodBucket["lazy-params"] ?? [];

			$variables = [];

			foreach(array_slice($matchData,1) as $index=>$assign){
				$variables[$onMatch["assign"][$index]] = urldecode($assign);
			}

			foreach ($requested as $index=>$value){
				if (is_int($index)){
					$variables[$value] = $requestData[$value] ?? null;
				}else{
					$variables[$index] = databaseModelItem::conformDataToModel(new stdClass(), @$requestData[$index]??null, $value, []);

					if (is_object($variables[$index]) && get_class($variables[$index]) && in_array("Rest\File", class_uses(get_class($variables[$index])))){
						if (!$obj->checkValid()){
							respond(400, ["message"=>"File $index invalid."]);
						}
					}
				}
			}

			foreach ($variables as $k=>$v){
				if ($v === null && !in_array($k,$lazy)){
					respond(400,["message"=>"Variable $k not set."]);
				}
			}

			//var_dump(get_defined_vars());

			return static::internal($mtype == "array" ? $onMatch[strtolower($method)]["execute"] : $onMatch[strtolower($method)],$variables,$callback);
		}

		respond(404,["message"=>"No matching resource $method $resource."]);
		return get_last_response();
	}
	public static function documentation($restfulStack){
		global $nonce;
		global $release;
		?>
		<style>
			div[enc] {
				width: 50%;
				background-color: white;
				box-shadow: 1px 1px 7px #00000059;
				padding: 2px 0px 15px 30px;
				margin-top: 10px;
				margin-bottom: 10px;
				white-space: nowrap;
				overflow-y: scroll;
			}

			.resultant {
				position: fixed;
				width: 400px;
				height: 90%;
				top: 5%;
				right: 50px;
				box-shadow: 0px 2px 7px #00000078;
				overflow-y:scroll;
			}

			.call {
				width: 95%;
				margin-left:2.5%;
				box-shadow: -1px 1px 3px #00000040;
				line-height: 40px;
				height: 40px;
			}

			.resultant__list {
				margin-top: 20px;;
			}

			.call>.call__method, .call>.call__response,.call>.call__url {
				display: inline-block;
				margin-left: 20px;
			}

			* {
				font-family: sans-serif;
			}

			.call.call-error {
				color: #ae1717;
			}

			.call {
				color: #2e7f2e;
				cursor: pointer;
				white-space: nowrap;
				margin-top:15px;
			}

			.call>.call__url {
				overflow-x: scroll;
				float: right;
				margin-right: 20px;
				max-width: 191px;
				height: 40px;
			}

			.call-open {
				height: 300px;
				margin-top: 7px;
				width: 95%;
				left: 2.5%;
				position: relative;
				box-shadow: -1px 2px 6px #b6b6b6;
				margin-bottom:20px;
				overflow-y:scroll;
				overflow-x:scroll;
			}

			.response {
				padding-top:20px;
				padding-left: 20px;
			}
		</style>
		<script src="/javascript/rest.js"></script>
		<script src="/javascript/jquery.js"></script>
		<script nonce="<?=$nonce?>">
			function getAddrFromBlock(block){
				var address = "";
				$(block).find("*[poster]").map(function(a,b){
					if ($(b).is("input")){
						return $(b).attr("poster") + $(b).val();
					}
					return $(b).attr("poster")
				}).each(function(a,b){
					address += b;
				})
				return address;
			}

			var rtype    = "";
			var raddress = "";

			var rid = 0;
			function requestRecieve(code, response){
				$(".call-open").hide();
				$(".resultant__list").prepend(`
					<div class="call" rid='` + rid + `'>
						<div class="call__method"> ` + rtype.toUpperCase() + ` </div>
						<div class="call__response"> `+code+` </div>
						<div class="call__url"> `+raddress+` </div>
					</div>
					<div class="call-open" rid='` + rid + `' style="">
						<div class="response">`+JSON.stringify(response)+`</div>
					</div>
				`)
				rid += 1;
				$(".resultant__list .call").off("click");
				$(".resultant__list .call").on("click",function(){
					$(".call-open").hide();
					$(".call-open[rid=" + $(this).attr("rid") + "]").show();;
				});
			}

			function requestError(request){

			}

			$(window).on("load",function(){
				$(".blockitem button").on("click",function(){
					var reqblock = $(this).parents("div.blockitem").eq(0);
					var reqparent = $(this).parents("*[enc]").eq(0);
					var address   = getAddrFromBlock(reqparent);

					var reqMethod = reqblock.attr("type");
					var request   = {};

					raddress = address;
					rtype    = reqMethod;

					reqblock.find("input[name]").each(function(i,e){
						request[$(e).attr("name")] = $(e).val();
					});
					console.log(address);
					switch(reqMethod){
						case "post":
							rest.post(address,request,requestRecieve);
							break;
						case "head":
							rest.head(address,request,requestRecieve);
							break;
						case "get":
							rest.get(address,request,requestRecieve);
							break;
						case "put":
							rest.put(address,request,requestRecieve);
							break;
						case "delete":
							rest.delete(address,request,requestRecieve);
							break;
						case "patch":
							rest.patch(address,request,requestRecieve);
							break;
						case "update":
							//rest.update(address,request,requestRecieve);
							break;
					}
				});
			});
		</script>
		<div class="resultant">
			<div class="resultant__list">

			</div>
		</div>
		<?
		foreach($restfulStack as $k=>$v){
			?>
				<div enc data="<?=$k?>">
				<h1> <?=$k?> </h1>

			<?
				if(isset($v["assign"])){
					?>
					<h4> URL Assignments </h4>
					<?
					$poster = preg_match_all("/\([\s\S]*?\)/",$k,$matches,PREG_OFFSET_CAPTURE);
					$breakup = array();
					$last    = 0;
					foreach ($matches[0] as $_=>$match){
						$offset = $match[1];
						array_push($breakup,substr($k,$last,$offset-$last));
						$last = $offset + strlen($match[0]);
					}

					array_push($breakup,substr($k,$last));

					foreach ($v["assign"] as $ind=>$assignment){

						?><div style='display:inline-block; min-width:100px'> <?=$assignment?> </div> <input placeholder='<?=$assignment?>' poster='<?=$breakup[$ind]?>'> <br><?
					}
					?><div class='post' poster='<?=$breakup[count($breakup) - 1]?>'> </div><?
				}else{
					?><div class='post' poster='<?=$k?>'> </div><?
				}
				foreach (array("head","get","post","delete","put","update","patch") as $_=>$item){
					if (isset($v[$item])){
						?>
						<div class="blockitem" type="<?=$item?>">
							<h2><?=$item?></h2>
							<? if (isset($v[$item."-request"])){ foreach ($v[$item."-request"] as $key=>$data){
								if (!is_int($key)){
									$data = $key;
								}?>
								<div style="display:inline-block; min-width:100px;"> <?=$data?> </div> <input name="<?=$data?>" placeholder="<?=$data?>"> <br>
							<? }} ?>
							<button> Submit Request </button>
						</div>
						<?
					}
				}
			?>
				</div>
			<?

		}
		die();
	}

	public static function internal($func, $assignments, $callback=null){

		static::$scope[] = $assignments;

		if ($callback){
			static::$triggers[] = $callback ?? function(){ };
		}

		foreach ($assignments as $k=>$v){
			global $$k;
			$$k = $v;
		}

		try{
			$func();
		}catch(Exception $e){
			error_log($e);
			respond(500,["Message"=>"Internal Error"]);
		}

		return get_last_response();
	}
}


if (preg_match("/^".preg_replace("/\//","\/","docs")."\/?$"."/",$resource)){
	header("Content-Type: text/html");
	rest::documentation($restfulStack);
	die();
}


?>
