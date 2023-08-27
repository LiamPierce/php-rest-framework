<?php

/*
Author: Liam Pierce
Copyright: Demon LLC
*/

function isAssoc($arr){
	if (array() === $arr || is_null($arr)) return false;
	return array_keys($arr) !== range(0, count($arr) - 1);
}

abstract class ColumnRegulator{
  public $model;
  function isRegulator(){
    return true;
  }

  function __construct($model){
    $this->model = $model;
  }

  static function regulate($model){
    return new static($model);
  }
}

class ListRegulator extends ColumnRegulator{

  function conform($value, $object, $static, $options){
    $conformed = [];
    foreach ($value??[] as $k=>$v){
      if (!is_numeric($k)){
        if (gettype($v) === "object"){
          $v->{"index"} = $k;
        }else if (gettype($v) === "array"){
          $v["index"] = $k;
        }
      }

      $conformed[] = $static::conformColumnToModel($object, $v, $this->model, $options);
    }

    return $conformed;
  }
}

class MapRegulator extends ColumnRegulator{

  function conform($value, $object, $static, $options){
    $conformed = [];
    foreach ($value??[] as $index=>$value){
      $conformed[$index] = $static::conformColumnToModel($object, $value, $this->model, $options);
    }

    return $conformed;
  }
}

class StructuredMapRegulator extends ColumnRegulator{

  function conform($value, $object, $static, $options){
    $conformed = [];
    foreach ($value??[] as $k=>$v){
      if (is_numeric($k)){
				$conformed[] = $static::conformColumnToModel($object, $v, $this->model, $options);
			}else{
				$conformed[$k] = $this->conform($v, $object, $static, $options);
			}
    }

    return $conformed;
  }
}


trait databaseModelItem{

  private $inserted = false;
  private $initializing = true;

  private static $__cache = [];

  static function conformFieldToType($value, $type){

    preg_match("/^([A-z]+)(?:\(([^\)]+)\))?(.+)?$/", $type, $conform);

    $nullable = true;
    $default = null;
    $do_strip_html = true;
    $do_stringify = false;

    @[, $type, $config, $flags] = $conform;

    $flags = explode(" ", $flags);
    foreach ($flags as $k=>$v){
      if ($v === "not-null"){
        $nullable = false;
      }else if (substr($v, 0, 7) === "default"){
        if (is_null($value)){
          $value = substr(substr($v, 8), 0, -1);
        }
      }else if ($v === "required"){
        if (is_null($value)){
          throw new Exception("Required field not set.");
        }
      }else if ($v === "no-html"){
        $do_strip_html = true;
      }else if ($v === "html"){
        $do_strip_html = false;
      }else if ($v === "stringify"){
        $do_stringify = true;
      }else if ($v === "no-special-chars"){
        $value = preg_replace("/[^0-9A-z-_]/", "", $value);
      }
    }

    if (is_null($value) && $nullable){
      return null;
    }else if (is_null($value) && !$nullable && $default){
      $value = $default;
    }

    if ($config && !empty($config)){
      if ($type === "string"){
        if (strlen($value) > intval($config)){
          $value = substr($value, 0, intval($config));
          //throw new Exception("Field doesn't fit.");
        }
      }else if ($type === "binary"){
        if (bindec($value) > pow(2, intval($config))){
          throw new Exception("Field doesn't fit.");
        }
      }else{
        if (strlen(strval($value)) > intval($config)){
          $value = substr(strval($value), 0, intval($config));
          //throw new Exception("Field doesn't fit.");
        }
      }
    }

    if ($type === "varchar" || $type === "text" || $type === "longtext" || $type === "mediumtext" || $type === "shorttext"){
			if (gettype($value) === "object"){
				$value = serialize($value);
			}

      $value = strval($value);

      if ($do_strip_html){
        $value = strip_tags($value);
      }
    }else if ($type === "int"){
      $value = intval($value);
    }else if ($type === "tinyint"){
      if ($value === "false"){
        $value = false;
      }else if ($value === "true"){
        $value = true;
      }

      $value = boolval($value) ? 1 : 0;
    }else if ($type === "boolean" || $type === "bool"){
      if ($value === "false" || $value === "f"){
        $value = false;
      }else if ($value === "true" || $value === "t"){
        $value = true;
      }

      $value = $do_stringify ? ($value ? "true" : "false") : boolval($value);
    }else if ($type === "float" || $type === "double"){
      $value = doubleval($value) ?? null;
    }else if ($type === "raw"){
      $value = $value;
      if (gettype($value) === "string"){
        $k = json_decode($value, true);
        if ($k){
          $value = $k;
        }
      }
    }else if ($type === "binary"){
      if (gettype($value) === "string"){
        $value = bindec($value);
      }
    }else if ($type === "datetime" || $type === "timestamp"){
      $date = DateTime::createFromFormat("Y-m-d H:i:s", $value);
      if (!$date){
        return null;
      }
      return $date->format("Y-m-d H:i:s");
    }

    return $value;
  }

  static function conformColumnToModel($object, $value, $model, $options=[]){
    $v = $model;

    if (is_callable($v)){
      if (is_string($value)){
        $v = $v($object, json_decode($value)?:$value);
      }else{
        $v = $v($object, $value);
      }
    }

    $type = gettype($v);


    if ($type === "string" && class_exists($v)){
      if (in_array("databaseModelItem", class_uses($v, true))){
        if (gettype($value) === "string"){
          return $v::create(get_object_vars(json_decode($value)));
        }else{
          return $v::create(get_object_vars($value));
        }
      }
    }else if ($type === "string" && preg_match("/^List\(([\S]+?)\)$/", $v, $matches)){
			if (class_exists($matches[1]) && in_array("databaseModelItem", class_uses($matches[1], true))){
        if (gettype($value) === "string"){
					$objects = [];
					foreach (json_decode($value) as $k=>$entry){
						$objects[] = $matches[1]::create(get_object_vars($entry));
					}
					return $objects;
        }else if (gettype($value) === "object"){
					$objects = [];
					foreach (get_object_vars($value) as $k=>$entry){
						$objects[] = $matches[1]::create(get_object_vars($entry));
					}
					return $objects;
				}else if (gettype($value) === "array"){
					$objects = [];
					foreach ($value as $k=>$entry){
						$objects[] = $matches[1]::create(get_object_vars($entry));
					}
					return $objects;
				}else{
          return [];
        }
      }else{
				$type = "object";
				$v = \ListRegulator::regulate($matches[1]);
			}
		}else if ($type === "string" && preg_match("/^Map\(([\S]+?)\)$/", $v, $matches)){
			if (class_exists($matches[1]) && in_array("databaseModelItem", class_uses($matches[1], true))){
        if (gettype($value) === "object"){
					$objects = [];
					foreach (get_object_vars($value) as $k=>$entry){
						$objects[$k] = $matches[1]::create(get_object_vars($entry));
					}
					return $objects;
				}else if (gettype($value) === "array"){
					$objects = [];
					foreach ($value as $k=>$entry){
						$objects[$k] = $matches[1]::create(get_object_vars($entry));
					}
					return $objects;
				}else{
          return [];
        }
      }else{
				$type = "object";
				$v = \MapRegulator::regulate($matches[1]);
			}
		}

    if ($type === "NULL"){
      return null;
    }

    if ($type === "string"){
      return static::conformFieldToType($value??null, $v);
    }else if ($type === "array"){
      if (isAssoc($v)){
        if (is_string($value)){
          return static::conformDataToModel(new stdClass(), json_decode($value)??null, $v, $options);
        }else{
          return static::conformDataToModel(new stdClass(), $value??null, $v, $options);
        }
      }else{
        if (is_null($value)){
          return null;
        }

        if (in_array($value??null, $v)){
          return $value??null;
        }else{
          return null;
          //throw new Exception("Invalid enum value: ".var_export($value, true).", with model ".var_export($v,true).".");
        }
      }
    }else if ($type === "object"){
      if (is_a($v, "\ColumnRegulator")){
        if (is_string($value)){
          return $v->conform(json_decode($value)??null, $object, static::class, $options);
        }else{
          return $v->conform($value??null, $object, static::class, $options);
        }
      }
    }
  }
  static function conformDataToModel($transpose, $object, $model, $options=[]){

    if ($object !== null && !is_object($object)){
      $object = json_decode(json_encode($object, JSON_FORCE_OBJECT));
    }

		if (is_array($model)){
			$keys = array_keys($model);
	    if ($options["limited"] ?? false){
	      $keys = array_intersect($keys, array_keys((array) $object));
	    }

	    foreach ($keys as $k){
	      $transpose->{$k} = static::conformColumnToModel($transpose, $object->{$k} ?? null, $model[$k], $options);
	    }
		}else{
			return static::conformColumnToModel($transpose, $object ?? null, $model, $options);
		}

    return $transpose;
  }

  static function conformModelToDatabase($object){
    foreach ($object as $k=>$v){
      if (is_object($v) || is_array($v)){
        if (is_object($v) && in_array("databaseModelItem", class_uses(get_class($v)))){
          $object->{$k} = json_encode($v);
        }else{
          $object->{$k} = json_encode($v);
        }
      }else if (is_bool($v)){
				$object->{$k} = $v ? "1" : "0";
			}
    }

    return $object;
  }

  public static function internalCreate($data){
    $obj = static::populate($data);

    if (method_exists($obj,"creatify")){
      $obj->creatify();
    }
    if (method_exists($obj,"__create")){
      $obj->__create();
    }

    $obj->initializing = false;

    return $obj;
  }

	public static function localTableName(){
		$exp = explode(".", static::$__db_model["table"]);
		return array_pop($exp);
	}

  public static function populate($raw_data, $with_prefix=null){

		$data;
		if (!is_null($with_prefix)){
			foreach (static::$__db_model["columns"] as $k=>$v){
				$data[$k] = $raw_data[$with_prefix."_".$k];
			}
		}else{
			$data = $raw_data;
		}

    $object = new static();
    static::conformDataToModel($object, $data, static::$__db_model["columns"], []);

    if (isset(static::$__db_model["extensions"])){
      static::conformDataToModel($object, $data, static::$__db_model["extensions"], []);
    }

    foreach (static::$__db_model["external"]??[] as $key=>$generator){
      $object->{$key} = $data[$key]??null;
    }

    if (isset(static::$__db_model["primary"])){
      $object->inserted = isset($object->{static::$__db_model["primary"]}) ? ($object->{static::$__db_model["primary"]} > 0) : false;
    }else{
      $object->inserted = false;
    }

    if (method_exists($object,"populatify")){
      $object->populatify();
    }

    if (method_exists($object,"__populated")){
      $object->__populated();
    }

    $object->initializing = false;

    return $object;
  }

  static function create($data=[]){
    $obj = static::internalCreate($data);

    return $obj;
  }

  function set($name, $value){
    if (array_key_exists($name, static::$__db_model["columns"])){
      $this->{$name} = static::conformColumnToModel($this, $value, static::$__db_model["columns"][$name]);
    }else{
      $this->{$name} = $value;
    }
  }

  public function setInserted($isInserted=true){
    $this->inserted = $isInserted;
  }

  public function isInserted(){
    return $this->inserted;
  }

  public function extract($call=true){

    if ($call && method_exists($this, "__extract")){
      $this->__extract();
    }

    $extract = clone $this;

    if ($call && method_exists($this,"extractify")){
      static::extractify($extract);
    }

    return (array) static::conformModelToDatabase(static::conformDataToModel(new stdClass(), $extract, static::$__db_model["columns"]));
  }

  public static function hasDatabaseField($field){
    return isset(static::$__db_model["columns"][$field]);
  }

  public static function from($indicies, $options=array()){

    $queryData = [];
    $query = "select ".static::$__db_model["table"].".*";

		$children = [];
		if (isset(static::$__db_model["children"]) && $options["fetch_children"] ?? false){
			$children = static::$__db_model["children"];
		}

		$table_name = static::localTableName();

		if (isset($options["delete"])){
      $query="delete";
    }else{
      if (isset(static::$__db_model["external"]) && ($options["external"] ?? true)){
        foreach (static::$__db_model["external"] as $column=>$generator){
          if (is_null($generator)){
            continue;
          }
          $query .= ", ($generator) as $column ";
        }
      }

			if (count($children) || @$options["generate_column_names"] ?? false){
				$query = "select ";

				$first = true;
				foreach (static::$__db_model["columns"] as $column=>$format){
					if (!$first){
						$query .= ", ";
					}else{ $first = false; }

          $query .= "{$column} as {$table_name}_{$column}";
        }
			}
    }

    $query .= " from ".static::$__db_model["table"]." where ";

    $conformed = (array) static::conformDataToModel(new stdClass(), $indicies, static::$__db_model["columns"], [
      "limited"=>true,
    ]);

    foreach ($conformed as $key=>$value){
      if (is_null($value)){
        $query .= static::$__db_model["table"].".`$key` is null and ";
      }else{
        $query .= static::$__db_model["table"].".`$key`=? and ";
        $queryData[] = $value;
      }
    }

    if (isset($options["search"])){
      if (empty($options["search"]["data"]) && !$options["search"]["query"]){
        unset($options["search"]);
      }else{
        $query .= $options["search"]["query"]." and ";
        foreach ($options["search"]["data"] as $i=>$v){
          $queryData[] = $v;
        }
      }
    }

    if (count($conformed) + (isset($options["search"]) ? 1 : 0) > 0){
      $query = substr($query,0,-5);
    }else{
      $query = substr($query,0,-7);
    }

		if ($options["query_only"] ?? false){
			return [
				"query"=>$query,
				"data"=>$queryData,
			];
		}

		if (count($children)){
			$alias = "a";
			$query = "select * from ($query) a";

			foreach ($children as $k=>$v){

				$alias = chr(ord($alias) + 1);
				$subquery = $v["object"]::from([], [
					"query_only"=>true,
					"generate_column_names"=>true
				]);

				$query .= " left join (";
				$query .= $subquery["query"];
				$query .= ") $alias on a.{$table_name}_{$v["fk"][0]}={$alias}.".$v["object"]::localTableName()."_".$v["fk"][1];
			}
		}

		if (count($children)){

		}else{
	    if (!isset($options["order"]) && isset(static::$__db_model["order"])){
	      $query .= " order by ".static::$__db_model["order"];
	    }
		}

    /* Options */

    if (isset($options["order"])){
      $query .= " order by ".$options["order"];
    }

    if (isset($options["limit"])){
      $query .= " limit ?";
      $queryData[] = $options["limit"];
    }

    if (isset($options["offset"])){
      $query .= " offset ?";
      $queryData[] = $options["offset"];
    }

    if (isset($options["delete"])){
      db()->query($query, $queryData);
      return;
    }

		error_log($query);

    if ($idx = db()->query($query,$queryData, true)){

			$populated = [];

			if (count($children)){

				$child_table_fk = [];
				$child_table_names = [];

				foreach ($children as $k=>$child){
					$child_table_names[$k] = $child["object"]::localTableName();
					$child_table_fk[$k] = $child_table_names[$k]."_".$child["fk"][1];
				}


				$last_parent_id = null;

				$obj = null;
				$total = 0;
				while ($total < 10000000){
					$total++;

					$v = db()->get_one(true, $idx);
					if (!$v){
						break;
					}

					$parent_id = $v[$table_name."_".static::$__db_model["primary"]];
					if ($parent_id !== $last_parent_id){
						$last_parent_id = $parent_id;
						if ($obj !== null){
							$populated[] = $obj;
							$obj = null;
						}

						$obj = static::populate($v, $table_name);
					}

					foreach ($children as $k=>$child){
						if (!is_null($v[$child_table_fk[$k]])){
							$obj->{$child["destination"]}[] = $child["object"]::populate($v, $child_table_names[$k]);
						}
					}

				}

				db()->close_query($idx);

				$populated[] = $obj;

				return $populated;

			}else{
				$total = 0;

				while ($total < 10000000){

					$v = db()->get_one(true, $idx);

					if (!$v){
						break;
					}

					$total++;
					$populated[] = static::populate($v);
				}

				db()->close_query($idx);

			  return $populated;
			}
    }else{
			error_log("Query returning nothing.");
      return [];
    }
  }

  public static function collect(){
    $data = db()->get_all();

    $populated = [];
    foreach ($data as $k=>$v){
      $populated[] = static::populate($v);

    }
    return $populated;
  }

  public static function count($indicies, $convert=true, $addition=null){

    $queryData = [];
    $query = (static::$__db_model["count"] ?? ("select count(*) from ".static::$__db_model["table"]))." where ";

    $intersect = array_intersect(array_keys($indicies),array_keys(static::$__db_model["columns"]));
    foreach ($intersect as $key){
      if (is_null($indicies[$key])){
        $query .= static::$__db_model["table"].".`$key` is null and ";
      }else{
        $query .= static::$__db_model["table"].".`$key`=? and ";
        $queryData[] = $indicies[$key];
      }
    }

    if (count($intersect) > 0){
      $query = substr($query,0,-5);
    }else{
      $query = substr($query,0,-7);
    }

    if (isset(static::$__db_model["order"])){
      $query .= " order by ".static::$__db_model["order"];
    }

    return db()->countOf($query.($addition??""),$queryData);
  }

  public static function get($indicies, $convert=true){
    $data = static::from($indicies, $convert," limit 1");
    if (is_array($data)){
      return $data[0] ?? false;
    }
    return false;
  }

  public static function fromId($id){
    return static::get([
      "id"=>$id,
    ]);
  }

  public function commitType(){
    if (isset(static::$__db_model["primary"])){
      if ($this->inserted || (isset($this->{static::$__db_model["primary"]}) && $this->{static::$__db_model["primary"]} > 0)){
        return "update";
      }else{
        return "insert";
      }
    }else{
      if (isset($this->inserted) && $this->inserted == true){
        return "update";
      }else{
        return "insert";
      }
    }

  }

  public function commit(){
    if ($this->commitType() === "insert"){
      return $this->insert();
    }else{
      return $this->update();
    }
  }

  public function insert($complex=true){
    if ($complex){
      if (method_exists($this, "__insert")){
        $this->__insert();
      }
      if (method_exists($this, "__change")){
        $this->__change();
      }
    }

    $data = $this->extract();

    $data = array_filter($data,function($value){
      if (is_null($value)){
        return false;
      }

      return true;
    });

    $keys = array_keys($data);
    try{
      db()->query("insert into ".static::$__db_model["table"]." (".implode(", ", array_map(function($k){ return "`$k`"; }, $keys)).") values (".substr(str_repeat("?,",count($data)),0,-1).")",array_values($data));

			if (isset(static::$__db_model["primary_is_serial"]) && static::$__db_model["primary_is_serial"] || !isset(static::$__db_model["primary_is_serial"])){
				$this->id = db()->insert_id();
			}

      $this->inserted = true;
      if ($complex){
        if (method_exists($this,"__created")){
          $this->__created();
        }
        if (method_exists($this,"__inserted")){
          $this->__inserted();
        }
        if (method_exists($this,"__changed")){
          $this->__changed();
        }
      }
      return true;
    }catch(Exception $e){
      error_log("Exception in insert.");
      error_log($e);
      return false;
    }
  }

  public function update($complex=true){
    if ($complex){
      if (method_exists($this, "__update")){
        $this->__update();
      }
      if (method_exists($this, "__change")){
        $this->__change();
      }
    }
    $data = $this->extract();

    $queryData = [];
    $query = "update ".static::$__db_model["table"]." set ";

    foreach (static::$__db_model["updatable"]??[] as $key){
      $query .="`$key`=?, ";
      $queryData[] = $data[$key];
    }

    if (count(static::$__db_model["updatable"]??[]) > 0){
      $query = substr($query,0,-2);
    }

    $query .= " where ";
    foreach (static::$__db_model["ids"] ?? [] as $key){
      if (!is_null($data[$key])){
        $query .="`$key`=? and ";
        $queryData[] = $data[$key];
      }

    }

    if (count(static::$__db_model["ids"]) > 0){
      $query = substr($query,0,-5);
    }
    db()->query($query,$queryData);

    if ($complex){
      $affected = db()->affected();
      if (method_exists($this,"__updated")){
        $this->__updated();
      }

      if ($affected == 0){
        error_log("None affected.");
        return false;
      }

      if (method_exists($this,"__changed")){
        $this->__changed();
      }
    }

    return true;
  }

  public function delete(){
    if (method_exists($this, "__delete")){
      $this->__delete();
    }
    if (method_exists($this, "__change")){
      $this->__change();
    }
    $data = $this->extract();

    $queryData = [];
    $query = "delete from ".static::$__db_model["table"]." where ";

    foreach (static::$__db_model["ids"] as $key){


      if (is_null($data[$key])){
        $query .= "$key is null and ";
      }else{
        $query .="$key=? and ";
        $queryData[] = $data[$key];
      }
    }

    if (count(static::$__db_model["ids"]) > 0){
      $query = substr($query,0,-5);
    }

    db()->query($query,$queryData);
    if (method_exists($this,"__deleted")){
      $this->__deleted();
    }
    if (method_exists($this,"__changed")){
      $this->__changed();
    }
  }

  public static function deleteFrom($data){
    self::from($data, [
      "delete"=>true,
    ]);
  }
}

?>
