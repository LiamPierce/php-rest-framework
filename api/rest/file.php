<?
/*

Author: Liam Pierce

*/

namespace Rest;

trait File{

  static $__file_errors = [
    0 => 'There is no error, the file uploaded with success',
    1 => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
    2 => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form',
    3 => 'The uploaded file was only partially uploaded',
    4 => 'No file was uploaded',
    6 => 'Missing a temporary folder',
    7 => 'Failed to write file to disk.',
    8 => 'A PHP extension stopped the file upload.',
  ];

  function checkValid(){
    return is_uploaded_file($this->tmp_name) && $this->isValid();
  }

  function moveTo($new_location){
    if (file_exists($new_location)){
      error_log("Cannot move a file into a location with an existing file.");
      return false;
    }

    return move_uploaded_file($this->tmp_name, $new_location);
  }

}

class BasicFile{
  use \databaseModelItem;
  use \expandable;
  use \Rest\File;

  public static $__db_model = [
    "columns"=>[
      "name"=>"varchar",
      "type"=>"varchar",
      "tmp_name"=>"varchar",
      "error"=>"int",
      "size"=>"int",
    ],
  ];

  public function __populated(){
    if ($this->error != 0){
      error_log("ERROR UPLOADING FILE: ".strtolower($this->name)." ".static::$__file_errors[$this->error].".");
    }
  }

  function isValid(){
    error_log(var_export($this, true));
    error_log("File error code: {$this->error}");
    return $this->error == 0;
  }
}

?>
