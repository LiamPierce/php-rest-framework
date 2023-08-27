<?php

namespace Database;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

class Credential{
	public $host;
	private $user;
	private $password;

	public function __construct($host, $user, $password){
		$this->host = $host;
		$this->user = $user;
		$this->password = $password;
	}

	public function apply(){
		return new \mysqli($this->host,$this->user,$this->password);
	}
}

class Query{
	const Prepared = "prepared";
	const Raw = "raw";

	public $association;

	public $type = null;
	public $query;
	public $binds;

	public $prepare = null;

	function __construct($query,$binds,$type=Query::Prepared){
		$this->type = $type;
		$this->query = $query;
		$this->binds = $binds;
	}

	function associate($db){
		$this->association = $db;
	}

	function prepare(){
		$prep = $this->association->connection->prepare($this->query);

		if (!$prep){
			error_log("Error in query : {$this->query} [{$this->association->connection->error}]");
			return false;
		}

		$this->prepare = $prep;
		return true;
	}

	function bind(){
		if (!$this->prepare){
			error_log("Prepare doesn't exist.");
			return false;
		}

		$shifted_binds = [];
		foreach($this->binds as $ind => &$val){
      $shifted_binds[] = &$val;
			unset($val);
		}

		//var_dump($shifted_binds);

		array_unshift($shifted_binds,str_repeat("s",count($shifted_binds)));
		if (count($shifted_binds) > 1){

			if (substr_count($this->query,"?") + 1 !== count($shifted_binds)){
				error_log($this->query." has the wrong number of binds.");
			}
			call_user_func_array(array($this->prepare,'bind_param'),$shifted_binds);
		}
	}

	function execute(){
		if (!$this->prepare){
			error_log("Prepare doesn't exist.");
			return false;
		}
		$this->prepare->execute();
		$this->prepare->store_result();
	}

	function insert_id(){
		return $this->prepare->insert_id;
	}

	function rows(){
		return $this->prepare->num_rows;
	}

	function affected(){
		return $this->prepare->affected_rows;
	}

	function get_one($assoc=true){

		$result = [];

		$data = $this->prepare->result_metadata();
		$row  = array();

		if (!$data){
			error_log("Cannot get rows on this query.");
			return;
		}

		while ($field = $data->fetch_field()){
			$row[] = &$result[$field->name]; // Pointer to location in assoc map.
		}

		call_user_func_array(array($this->prepare, 'bind_result'), $row);
		if ($this->prepare->fetch()){
			if ($assoc){
				return $result;
			}else{
				return array_values($result);
			}
		}else{
		}

		return false;
	}
}

class DB{
	private $credential = null;
	public $connection = null;

	public $last = null;
	public $idx = [];

	public function __construct($credential){
		$this->credential = $credential;
		$this->open();
	}

	public function connect($database){

		if (!$this->connection->select_db($database)){
			return false;
		}

		return true;
	}

	function open(){
		if ($this->connection && $this->connection->ping()){
			$this->close();
		}

		$this->connection = $this->credential->apply();
		$this->charset("utf8mb4");
	}

	function close(){
		if ($this->connection && $this->connection->ping()){
			$this->connection->close();
		}

		$this->connection = null;

		return true;
	}

	function charset($charset){
		if ($this->connection){
			$this->connection->set_charset($charset);
		}
	}

	function autocommit($do=true){
		$this->connection->autocommit(!!$do);
	}

	function query($querystring,$binds=array(), $idx=false){
		$query = new Query($querystring,$binds);
		$query->associate($this);

		if (!$query || !$query->prepare()){
			error_log("Failed to prepare.");
			return false;
		}

		$query->bind();
		$query->execute();

		$this->last = $query;
		if ($idx){
			$id = sha1(count($this->idx));
			$this->idx[$id] = $query;
			return $id;
		}


		return $this;
	}

	function raw_query($querystring){
		$this->connection->query($querystring);
	}

	function countOf($query,$binds=array()){
		return $this->query($query,$binds)->get_one()["count(*)"];
	}

	function insert_id(){
		return $this->last->insert_id();
	}

	function rows(){
		if ($this->last){
			return $this->last->rows();
		}

		return false;
	}

	function affected(){
		if ($this->last){
			return $this->last->affected();
		}

		return false;
	}

	function get_one($assoc=true, $id=null){
		if (!is_null($id)){
			return $this->idx[$id]->get_one($assoc);
		}else{
			return $this->last->get_one($assoc);
		}
	}

	function get_all($assoc=true){
		$all = array();
		while ($next = $this->get_one($assoc)){
			array_push($all,$next);
		}
		return $all;
	}

	function close_query($id){
		unset($this->idx[$id]);
	}
}

?>
