<?php

trait expandable{

  function expand($key, $force=false){
    if (!in_array($key,static::$__expandable)){
      return false;
    }

    $this->expandify($key, $force);
  }

  function expandAll($expansion, $force=false){
    if (is_array($expansion)){
      foreach ($expansion as $k){
        $this->expand($k, $force);
      }
    }else{
      $this->expand($expansion, $force);
    }
  }

  function isExpanded($key){
    if (isset($this->{$key})){
      return true;
    }
  }
}

?>
