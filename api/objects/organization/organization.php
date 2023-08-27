<?

class Organization{
  use databaseModelItem;
  use expandable;

  public static $current;
  
  public static $__db_model = [
    "table"=>"settable.organizations",
    "columns"=>[
      "id"=>"int",
      "name"=>"varchar",
    ],
    "primary"=>"id",
    "ids"=>[
      "id",
    ],
    "updatable"=>[
      "name"
    ],
  ];
  public static $__expandable = [
  ];

  private function expandify($key,$force=false){
		if (!$force && isset($this->{$key})){
			return false;
		}
	}

  static function create($data=[]){
    $obj = static::internalCreate($data);

    return $obj;
  }

  public static function current(){
    if (isset(static::$current)){
      return static::$current;
    }

    if (isset($_SESSION["organization_id"])){
      static::$current = Organization::fromId($_SESSION["organization_id"]);
      return static::$current;
    }
  }

}

?>
