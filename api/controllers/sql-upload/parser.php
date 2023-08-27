<?

namespace Platform\SQLUpload;

class Parser{

  public static function parse(\Platform\SQLUpload $sqldump){

    include_once("/home/settable/secondary-packages/lexer/vendor/autoload.php");
    include_once("plurals.php");

    $tables = [

    ];

    $parser = new \PHPSQLParser\PHPSQLParser();

    $queries = explode(";", $sqldump->raw_sql_input);
    foreach ($queries as $_=>$v){
      if (!preg_match("/create table/i", $v)){
        continue;
      }

      $parse = $parser->parse($v);

      $object_name = str_replace("_", " ", trim($parse["TABLE"]["name"], "`'\""));
      $object_name_parts = explode(" ", $object_name);
      $ending = \Pluralizer::singularize(array_pop($object_name_parts));
      $object_name = ucwords(implode(" ", array_merge($object_name_parts, [$ending])));
      
      $table = [
        "table_name"=>$parse["TABLE"]["name"],
        "object_name"=>$object_name,
        "primary"=>[],
        "secondary"=>[],
        "columns"=>[],
      ];

      foreach ($parse["TABLE"]["create-def"]["sub_tree"] as $_=>$cdef){
        if ($cdef["expr_type"] === "primary-key"){
          foreach ($cdef["sub_tree"] as $_=>$pkdef){
            if ($pkdef["expr_type"] !== "column-list"){
              continue;
            }

            foreach ($pkdef["sub_tree"] as $_=>$primaries){
              $table["primary"][] = $primaries["name"];
            }
          }

        }else if ($cdef["expr_type"] === "column-def"){
          $columnDef = [
            "column"=>null,
            "name"=>null,
            "type"=>null
          ];

          foreach ($cdef["sub_tree"] as $_=>$ref){
            if ($ref["expr_type"] === "column-type"){
              foreach ($ref["sub_tree"] as $_=>$type){
                if ($type["expr_type"] !== "data-type"){
                  continue;
                }

                $columnDef["type"] = $type["base_expr"];

                break;
              }
            }else if ($ref["expr_type"] === "colref"){
              $columnDef["column"] = $ref["base_expr"];

              $columnDef["name"] = str_replace("_", " ", trim($ref["base_expr"], "`'\""));
              $name_parts = explode(" ", $columnDef["name"]);
              $ending = \Pluralizer::singularize(array_pop($name_parts));
              $columnDef["name"] = ucwords(implode(" ", array_merge($name_parts, [$ending])));

            }
          }

          $table["columns"][] = $columnDef;
        }else if ($cdef["expr_type"] === "foreign-key"){
          $secondary = [
            "table_name"=>$table["table_name"],
            "columns"=>[],
            "reference_table"=>null,
            "reference_columns"=>[],
          ];
          foreach ($cdef["sub_tree"] as $_=>$fkst){
            if ($fkst["expr_type"] === "column-list"){
              foreach ($fkst["sub_tree"] as $_=>$clist){
                if ($clist["expr_type"] !== "index-column"){
                  continue;
                }

                $secondary["columns"][] = $clist["name"];
              }
            }else if ($fkst["expr_type"] === "foreign-ref"){
              foreach ($fkst["sub_tree"] as $_=>$tlst){


                if ($tlst["expr_type"] === "table"){
                  $secondary["reference_table"] = $tlst["table"];
                }else if ($tlst["expr_type"] === "column-list"){
                  foreach ($tlst["sub_tree"] as $_=>$clist){
                    if ($clist["expr_type"] !== "index-column"){
                      continue;
                    }

                    $secondary["reference_columns"][] = $clist["name"];
                  }
                }


              }
            }

            continue;
          }
          $table["secondary"][] = $secondary;
        }else{
          continue;
        }
      }

      $tables[] = $table;
    }

    return $tables;
  }
}

?>
