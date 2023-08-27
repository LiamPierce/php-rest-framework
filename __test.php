<?

if ($_SERVER["REMOTE_ADDR"] !== "50.245.12.214"){
  http_response_code(404);
  die();
}

include_once("/var/www/api/api/api.php");

dynamic_load_module("objects", "auth");
dynamic_load_module("controllers", "auth");

switch($_GET["action"]){
  case "new-key":
    $key = \Auth\Token::create(array_merge([
      "organization_id"=>\User::current()->organization_id,
      "created_by_user_id"=>\User::current()->id,
      "token_name"=>"Pierce Key",
    ], \Auth\TokenGenerator::generate()));
    $key->commit();
    var_dump($key);
    break;
  case "generate-config":
    $pfm = \Platform::fromId(1);
    var_dump($pfm->generateFilterConfig());
    break;
}

die();

die();

$pfm = \Platform::fromId(1);
var_dump($pfm->getConfig()->read());

die();
$pfm = \Platform::fromId(1);
var_dump($pfm->generateFilterConfig());

die();

$filter = \Platform\Query\Filter::create([
  "organization_id"=>1,
  "platform_id"=>1,
  "filter"=>[
    "meta"=>[
      "label"=>"Customer {0}",
      "inputs"=>[
        [
          "type"=>"select",
          "options"=>[
            "Full Name"=>"full_name",
            "Short Name"=>"short_name",
            "Created"=>"created"
          ]
        ]
      ]
    ],
    "infra"=>[
      "datetime"=>[ //Infra model.
        "operators"=>[
          "equal",
          "nequal",
          "fuzzy",
          "nfuzzy",
          "soundex",
          "gt",
          "gte",
          "lt",
          "lte"
        ],
        "label"=>"Customer {0}",
        "inputs"=>[

        ]
      ]
    ],
    "meta_implies_infra"=>[
      "Full Name"=>"datetime",
    ]
  ],
  "processors"=>[
    "default"=>[
      "processor_type"=>"basic-sql",
      "query"=>"select id, cid from customers ",
      "session_variables"=>[],
    ]
  ]
]);

var_dump($filter);
//var_dump(\Auth\Token::generate());

die();

/*for ($i = 0; $i < 15000; $i++){
  $qo = \Platform\QueryObject::create([
    "organization_id"=>1,
    "platform_id"=>1,
    "public_name"=>sha1(rand(0, 10000)),
    "table_name"=>"",
    "configuration"=>"",
  ]);
  $qo->commit();

  $ids = [
    \Platform\QueryObject\Id::create([
      "platform_object_id"=>$qo->id,
      "column_name"=>"id",

    ]),
    Platform\QueryObject\Id::create([
      "platform_object_id"=>$qo->id,
      "column_name"=>"cid",

    ]),
    Platform\QueryObject\Id::create([
      "platform_object_id"=>$qo->id,
      "column_name"=>"organization_id",

    ])
  ];

  foreach ($ids as $k=>$v){
    $v->commit();
  }
}*/

/*if (isset($_GET["a"])){
  \PTimer::start("Multiquery Timer.");
  $object_1 = \Platform\QueryObject::from([
    "platform_id"=>1,
    "organization_id"=>1
  ],[
    "fetch_children"=>true,
  ]);
  \PTimer::stop("Multiquery Timer.");
  var_dump($object_1);
}

if (isset($_GET["b"])){
  \PTimer::start("Traditional Timer.");
  $object_2 = \Platform\QueryObject::from([
    "platform_id"=>1,
    "organization_id"=>1
  ],[
    "fetch_children"=>false,
  ]);
  \PTimer::stop("Traditional Timer.");
  var_dump($object_2);
}*/


?>
