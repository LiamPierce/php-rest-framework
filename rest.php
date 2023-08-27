<?
/* Liam Pierce, 4/2/18 */

$_SERVER["DOCUMENT_ROOT"] = "/var/www/api";

include_once($_SERVER["DOCUMENT_ROOT"]."/api/api.php");

//include_once($_SERVER['DOCUMENT_ROOT']."/api/caching.php");
//include_once($_SERVER['DOCUMENT_ROOT'].'/api/direct.php');
header("Content-Type: text/plain");

dynamic_load_module("controllers", "auth");

$credentials = array(
	\Auth::User=>function(){
		$user = \User::current();
		return $user && $user->email_verified;
	},
	\Auth::Key=>function(){

		dynamic_load_module("objects", "auth");

		$headers = apache_request_headers();

		$token;
		if (isset($headers["Authorization"])){

			$parameters = explode(",", $headers["Authorization"]);
			$parsed_params = [];

			foreach ($parameters as $k=>$v){
				$obj = explode("=", trim($v));
				$param = array_shift($obj);
				$parsed_params[$param] = implode("=", $obj);
			}

			$token = @$parsed_params["Token"];
		}else{
			$token = @$_REQUEST["token"];
		}

		if (!$token){
			return false;
		}

		$token = \Auth\Token::get([
			"public_key"=>$token
		]);

		if (!$token){
			return false;
		}

		\Auth\Token::current($token);
		return true;
	},
	\Auth::KeySignature=>function(){
		dynamic_load_module("objects", "auth");

		$headers = apache_request_headers();

		$token;
		$signature;

		if (isset($headers["Authorization"])){
			$parameters = explode(",", $headers["Authorization"]);
			$parsed_params = [];

			foreach ($parameters as $k=>$v){
				$obj = explode("=", trim($v));
				$param = array_shift($obj);
				$parsed_params[$param] = implode("=", $obj);
			}

			$token = @$parsed_params["Token"];
			$signature = @$parsed_params["Signature"];
		}

		if (!$token || !$signature){
			return false;
		}

		$token = \Auth\Token::get([
			"public_key"=>$token
		]);

		echo file_get_contents("php://input");

		if (!$token){
			return false;
		}

		\Auth\Token::current($token);
		return true;
	}
);

$restfulStack = array(
	"/error-log"=>[
		"post"=>[
			"params"=>[
				"error",
				"url",
				"line",
			],
			"lazy-params"=>[
				"error",
				"url",
				"line",
			],
			"execute"=>function(){
				global $error;
				global $url;
				global $line;

				$log = \ErrorLog::create([
					"user_id"=>\User::current() ? \User::current()->id : null,
					"error"=>$error,
					"url"=>$url,
					"line"=>$line,
				]);
				$log->commit();

				respond(200);
			}
		]
	],

	"/organizations/register"=>[
		"credentials"=>[],
		"get"=>[
			"rate"=>5,
			"params"=>[],
			"execute"=>function(){

				$newSalt = openssl_random_pseudo_bytes(50);
				$newSalt = base64_encode($newSalt);

				$_SESSION["salt"] = hash('sha512', $newSalt);
				respond(200,["salt"=>$_SESSION["salt"]]);
			}
		],
		"post"=>[
			"rate"=>5,
			"params"=>[
				"organization"=>[
					"name"=>"varchar",
				],
				"user"=>[
					"name"=>"varchar",
					"email"=>"varchar",
					"password"=>"varchar"
				]
			],
			"execute"=>function(){

				global $organization;
				global $user;

				$email_exists = \User::count([
					"email"=>$user->email
				]);

				if ($email_exists > 0){
					respond(400, ["message"=>"This email is in use."]);
				}

				$org = Organization::create($organization);
				$org->insert();

				$newUser = User::create($user);

				$newUser->organization_id = $org->id;
				$newUser->security_role = "administrator";
				$newUser->setPassword($user->password);
				$newUser->insert();

				$newUser->localAuthenticate($user->password);
				$newUser->verifyEmail();

				respond(200);
			}
		],
	],
	"/verifications/([0-9]+)/([\S]+)"=>[
		"assign"=>["id", "code"],
		"get"=>[
			"execute"=>function(){
				global $id;
				global $code;

				$verification = \User\Verification::get([
					"id"=>$id,
					"token"=>$code,
				], [
					"search"=>[
						"query"=>"created > now() - INTERVAL 2 DAY",
						"data"=>[]
					]
				]);

				if (!$verification){
					respond(400, ["message"=>"This link has expired."]);
				}

				$user = \User::fromId($verification->user_id);
				$user->email_verified = 1;
				$user->update();


				header("Content-Type: text/html");

				include_once("/var/www/api/api/templates/verified.php");

				die();

			},
		],
	],

	"/user/authenticate"=>array(
		"credentials"=>array(),
		"assign"=>array(),
		"post"=>[
			"rate"=>5,
			"params"=>[
				"email"=>"varchar",
				"password"=>"varchar",
				"salt"=>"varchar"
			],
			"execute"=>function(){
				global $email;
				global $password;
				global $salt;

				$user = \User::current();

				if ($user){
					respond(409, ["message"=>"You are already signed in."]);
				}

				if (isset($_SESSION["salt"]) && $salt !== $_SESSION["salt"] || !isset($_SESSION["salt"])){
					respond(400,["message"=>"Invalid salt."]);
				}
				$_SESSION["salt"] = null;

				$auth = \User::authenticate($email, $password);

				if ($auth){
					respond(200,["success"=>$auth]);
				}else{
					respond(400,["message"=>"Credentials incorrect."]);
				}
			},
		],
		"get"=>[
			"rate"=>5,
			"params"=>array(),
			"execute"=>function(){
				$_SESSION["salt"] = hash('sha512', \User\Credential::salt());
				respond(200,["salt"=>$_SESSION["salt"]]);
			}
		],
	),
	"/user/logout"=>[
		"credentials"=>[Auth::User],
		"get"=>[
			"execute"=>function(){
				session_destroy();

				respond(200);
			}
		],
		"post"=>"get",
	],
	"/user/authenticated"=>[
		"get"=>[
			"execute"=>function(){
				global $config;
				$user = \User::current();
				error_log(var_export($user, true));
				if ($user){
					$organization = Organization::current();

					respond(200,[
						"authenticated"=>true,
						"credential"=>$user,
						"organization"=> $organization,
					]);
				}else{
					respond(200, [
						"authenticated"=>false,
					]);
				}

			}
		],
	],

	/*****************/
	/*   Platforms   */
	/*****************/

	"/platforms"=>[
		"credentials"=>[Auth::User],
		"post"=>[
			"params"=>[
				"name"=>"varchar"
			],
			"execute"=>function(){
				global $name;
				error_log(var_export(\User::current(), true));
			}
		],
		"get"=>[
			"params"=>[],
			"execute"=>function(){
				$platforms = \Platform::from([]);

				respond(200, [
					"data"=>$platforms,
				]);
			}
		]
	],
	"/platforms/([0-9]+)/sql-uploads"=>[
		"credentials"=>[Auth::User],
		"assign"=>["platform_id"],
		"post"=>[
			"preflight"=>function(){
				dynamic_load_module("objects", "platform/sql-upload");
			},
			"params"=>[
				"upload"=>"\Platform\SQLUpload",
			],
			"execute"=>function(){
				global $platform_id;
				global $upload;

				$exists = \Platform\SQLUpload::get([
					"sql_hash"=>sha1($upload->raw_sql_input),
				]);

				if ($exists){
					respond(200, [
						"data"=>$exists,
					]);
				}

				$upload->platform_id = $platform_id;
				$upload->organization_id = \User::current()->organization_id;
				$upload->commit();

				dynamic_load_module("controllers", "sql-upload");

				$upload->parsed_tables = \Platform\SQLUpload\Parser::parse($upload);
				$upload->commit();

				respond(200, [
					"data"=>$upload,
				]);
			}
		]
	],
	"/platforms/([0-9]+)/sql-uploads/([0-9]+)"=>[
		"credentials"=>[Auth::User],
		"assign"=>["platform_id", "upload_id"],
		"get"=>[
			"params"=>[],
			"execute"=>function(){
				global $platform_id;
				global $upload_id;

				dynamic_load_module("objects", "platform/sql-upload");

				$exists = \Platform\SQLUpload::get([
					"organization_id"=>\User::current()->organization_id,
					"id"=>$upload_id,
				]);

				if (!$exists){
					respond(404, [
						"message"=>"Specified SQL upload doesn't exist."
					]);
				}

				respond(200, [
					"data"=>$exists,
				]);
			}
		]
	],
	"/platforms/([0-9]+)/sql-uploads/([0-9]+)/conformation"=>[
		"credentials"=>[Auth::User],
		"assign"=>["platform_id", "upload_id"],
		"post"=>[
			"params"=>[
				"global_sql"=>"text",
				"table_enabled"=>"List(boolean)",
				"table_sql"=>"Map(text)",
				"column_enabled_by_table"=>"Map(Map(boolean))",
			],
			"execute"=>function(){
				global $platform_id;
				global $upload_id;

				global $global_sql;
				global $table_enabled;
				global $table_sql;
				global $column_enabled_by_table;

				dynamic_load_module("objects", "platform/sql-upload");
				dynamic_load_module("objects", "platform/query");
				dynamic_load_module("objects", "platform/query/filter");

				$exists = \Platform\SQLUpload::get([
					"organization_id"=>\User::current()->organization_id,
					"id"=>$upload_id,
				]);

				$object_store    = [];

				foreach ($exists->parsed_tables as $ind=>$table){
					if (isset($table_enabled[$ind]) && !$table_enabled[$ind]){
						continue;
					}

					$boundary = implode(" and ", array_filter(["($global_sql)", "({$table_sql["$table->table_name"]})"], function($m){ return $m !== "()"; }));

					$object = \Platform\Query\Obj::create([
						"organization_id"=>\User::current()->organization_id,
			      "platform_id"=>$platform_id,
			      "public_name"=>$table->object_name,
			      "configuration"=>[
			        "boundary"=>[
			          "raw_boundary"=>$boundary,
			          "formatted_boundary"=>$boundary
			        ]
			      ],
					]);
					$object->commit();
					$object_store[$table->table_name] = $object;

				}

				foreach ($exists->parsed_tables as $ind=>$table){
					if (isset($table_enabled[$ind]) && !$table_enabled[$ind]){
						continue;
					}

					$object = $object_store[$table->table_name];

					$primary = \Platform\Query\Obj\Id::create([
						"platform_query_object_id"=>$object->id,
						"key_columns"=>$table->primary,
						"references_platform_query_object_id"=>$object->id,
					]);
					$primary->commit();
					$object->primary[] = $primary;

					$meta_implies_infra = [

					];
					$infras = [

					];

					$filterOptions = [];
					foreach ($table->columns as $k=>$v){
						if (!$column_enabled_by_table[$table->table_name][$v->column]){
							continue;
						}

						$filterOptions[] = [
							"label"=>$v->name,
							"value"=>$v->column
						];
						$meta_implies_infra[$v->column] = $v->type;

						if (!isset($infras[$v->type])){
							$infras[$v->type] = [
								"label"=>"{0}",
								"inputs"=>[
									\Platform\Query\Filter\InputDescription::forSchemaType($v->type, $v->name),
								],
								"operators"=>\Platform\Query\Filter\InputDescription::getOperators($v->type),
							];
						}
					}

					$filter = \Platform\Query\Filter::create([
						"organization_id"=>1,
					  "platform_id"=>1,
						"private_name"=>"{$table->object_name} Simple Filter",
						"filter_return_object"=>$object->id,
						"filter_folder"=>"",
					  "filter"=>[
							"type"=>"filter",
					    "meta"=>[
					      "label"=>"{$table->object_name} {0}",
					      "inputs"=>[
					        [
					          "type"=>"select",
					          "options"=>$filterOptions,
					        ]
					      ]
					    ],
					    "infra"=>$infras,
					    "meta_implies_infra"=>$meta_implies_infra,
					  ],
					  "processors"=>[
					    "default"=>[
					      "processor_type"=>"basic-sql",
					      "query"=>"select id, cid from customers ",
					      "session_variables"=>[],
					    ]
					  ]
					]);
					$filter->commit();

					/*
					$object->secondary[] = \Platform\Query\Obj\Id::create([
						"platform_query_object_id"=>$table->id,
						"key_columns"=>$table->primary,
						"references_platform_query_object_id"=>$table->id,
					]);*/

				}

				if (!$exists){
					respond(404, [
						"message"=>"Specified SQL upload doesn't exist."
					]);
				}

				respond(200, [
					"data"=>$exists,
				]);
			}
		]
	],
	"/platforms/([0-9]+)/configuration"=>[
		"assign"=>["platform_id"],
		"credentials"=>[Auth::User],
		"get"=>[
			"execute"=>function(){
				global $platform_id;

				$pfm = \Platform::get([
					"organization_id"=>\User::current()->organization_id,
					"platform_id"=>$platform_id,
				]);

				if (!$pfm){
					respond(404, ["message"=>"Platform not found."]);
				}

				$config = $pfm->getConfig();

				if (!$config){
					respond(404, ["message"=>"Config not found."]);
				}

				respond(200, ["data"=>$config->read()]);
			}
		],
	],
	"/platforms/([0-9]+)/objects"=>[
		"credentials"=>[Auth::User],
		"assign"=>[
			"platform_id"
		],
		"rate"=>[
			"limit"=>12,
			"bucket"=>20,
			"bootstrap"=>20,
		],
		"post"=>[
			"params"=>[
				"object"=>"\Platform\QueryObject"
			],
			"execute"=>function(){
				global $platform_id;
				global $object;

				$platform = \Platform::get([
					"id"=>$platform_id,
					"organization_id"=>\Organization::current()->id,
				]);

				if (!$platform){
					respond(404, ["message"=>"Platform not found."]);
				}

				$object->organization_id = \Organization::current()->id;
				$object->platform_id = $platform->id;

				$object->commit();

				respond(200, ["data"=>$object]);
			}
		],
		"get"=>[
			"params"=>[],
			"execute"=>function(){
				global $platform_id;

				error_log(var_export(\User::current(), true));

				$platform = \Platform::get([
					"id"=>$platform_id,
					"organization_id"=>\User::current()->organization_id,
				]);

				if (!$platform){
					respond(404, ["message"=>"Platform not found."]);
				}

				respond(200, [
					"data"=>\Platform\QueryObject::from([
						"organization_id"=>\User::current()->organization_id,
						"platform_id"=>$platform_id,
					])
				]);
			}
		]
	],

	/******************************/
	/*   Public Platform Access   */
	/******************************/

	"/public-filter-configuration"=>[
		"credentials"=>[Auth::Key, Auth::User],
		"get"=>[
			"execute"=>function(){


				$config = \Platform::getPlatformConfig(\Auth\Credential::current()->platform_id);

				if (!$config){
					respond(404, ["message"=>"Config not found."]);
				}

				echo $config->read();
				http_response_code(200);
				\PTimer::stopAll();
				die();
			}
		],
	],
	"/query/enqueue"=>[
		"credentials"=>[Auth::Key],
		"post"=>[
			"preflight"=>function(){
				dynamic_load_module("objects", "platform/query/filter");
			},
			"params"=>[
				"filters"=>"List(\Platform\Query\Filter\Actual)",
			],
			"execute"=>function(){

				global $filters;

				$timestamp = microtime(true) * 1000;
				$nonce     = substr(base64_encode(openssl_random_pseudo_bytes(45)), 32);

				$signature = "qry_".hash_hmac("sha256", $timestamp.$nonce, \Auth\Token::current()->token_public."@".(parse_url($_SERVER["HTTP_REFERER"])["host"]));

				$pfm = \Platform::get([
					"organization_id"=>\Auth\Credential::current()->organization_id,
					"platform_id"=>\Auth\Token::current()->platform_id,
				]);

				if (!$pfm){
					respond(404, ["message"=>"Platform not found."]);
				}

				dynamic_load_module("objects", "platform/query");

				$query = \Platform\Query::create([
					"organization_id"=>\Auth\Credential::current()->organization_id,
		      "platform_id"=>$pfm->id,
		      "auth_type"=>is_a(\Auth\Credential::current(), "\Auth\Token") ? "token" : "user",
		      "auth_id"=>\Auth\Credential::current()->id,

		      "request_ip"=>$_SERVER["REMOTE_ADDR"],
		      "request_user_agent"=>$_SERVER["HTTP_USER_AGENT"],
		      "request_domain"=>$_SERVER['HTTP_REFERER'],

					"signature"=>$signature,

					"filters"=>$filters,
					"filters_checksum"=>"none",

				]);
				$query->commit();

				respond(200, ["data"=>$query]);
			}
		],
	],
	"/query/execute"=>[
		"assign"=>[],
		"credentials"=>[\Auth::KeySignature],
		"post"=>[
			"preflight"=>function(){
				dynamic_load_module("objects", "platform/query");
			},
			"params"=>[
				"qid"=>"varchar",
				"session"=>"Map(varchar)"
			],
			"execute"=>function(){
				global $qid;
				global $session;

				$query = \Platform\Query::get([
					"signature"=>$qid
				]);
				$query->signature_verified = true;
				$query->update();
				respond(200, ["data"=>$query]);

			}
		]
	]
);

include_once($_SERVER['DOCUMENT_ROOT']."/api/rest/api.php");

PTimer::stop("api-overhead");
PTimer::start("rest-endpoint /$resource");
rest::evaluate($_SERVER['REQUEST_METHOD'],$resource,$_REQUEST,function($data){
	\PTimer::stopAll();
	\PTimer::sendTimingHeader();
	echo json_encode($data, JSON_UNESCAPED_UNICODE);
});


?>
