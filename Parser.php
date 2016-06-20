<?php
//connect php
$connection = mysqli_connect ("localhost", "timeslots_user", "parse", "db_timeslots");
//load xml
$path = "https://www.resengo.com/Code/API/?APIClientID=1020139&Call=RN_LASTMINUTES&APISECRET=NLo4bkphb3dqUWGmE8VUcxqwQowwTlwcrsxFJqg7FpTdJSNWjR&RN_CompanyID=688451&RN_CompanyIDs=292493";
try { $xml_file = simplexml_load_file($path); } catch (Exception $e) { echo $e; }
//build xml array 
$xml_array = []; $xml_record = []; $record_value; $record_key; //variables to construct xml records in an array form. 
$sql_match = [];
$table_columns = ["LastMinuteID","CompanyID","StartTime","EndTime","Description","Remarks","Cost","OriginalCost","InsertDate","Deleted","ReservationURL"];
foreach($xml_file->RECORD as $records){
    $sql_match[] = (string)$records->LastMinuteID;
    foreach($records as $field) {
        $record_key = $field->getName();
        if( in_array($field->getName(),array($table_columns[2],$table_columns[3],$table_columns[8])) ) { //if a date
            $record_value = build_date( (string)$field );
        } else if ($field->getName() == $table_columns[9]) { //if boolean deleterd
            $record_value = (string)$field == "True" ? 1 : 0;
        } else { //all other are converted to string
            $record_value = (string)$field; 
        }
        $xml_record[$record_key] = $record_value;
        
    }
    $xml_array[] = $xml_record;
}
//build sql array
$query = "SELECT * FROM records WHERE LastMinuteID IN (".join(",",$sql_match).");";
$result_set = mysqli_query($connection,$query);
$sql_array = [];
while($row = mysqli_fetch_array($result_set)){
    $sql_array[] = $row;
}
//build mismatch
$update_string = ""; $update_record = []; $create_array = []; $update_count = 0;
if(!empty($sql_array)) {
    foreach($xml_array as $xml_record) { //loop through collection of xml records
        $is_new = 1; //if true by the end of the loop, add this record as a new sql row 
        foreach($sql_array as $sql_record) { //loop through collection of sql records
            $update_record = []; //reset update record
            if( $xml_record[ $table_columns[0] ] == $sql_record[ $table_columns[0] ] ) { //if LastMinuteID match
                $is_new = 0;
                foreach ($table_columns as $column_name) { //loop through sql column names
                    if($xml_record[$column_name] != $sql_record[$column_name]) { //if mismatch between sql and xml
                        $update_record[$column_name] = $xml_record[$column_name];
                    }
                }
                if(!empty($update_record)) { //changes detected.
                    $update_attributes = prep_sql_values($update_record);
                    $attribute_pairs = [];
                    foreach($update_attributes as $key => $value) {
                        $attribute_pairs[] = "{$key}={$value}";
                    }
                    $update_string .= " UPDATE records SET ".join(", ",$attribute_pairs)." WHERE id=".$sql_record["id"]."; ";
                    $update_count++;
                }
            }
        }
        if($is_new) $create_array[] = $xml_record;
    }
} else {
    $create_array = $xml_array;
}
write_sql();
/* ********************** FUNCTIONS ************************* */
function build_date($string) { //convert xml date into sql DATETIME
    $time_string = strtotime($string);
    $time_sql = date("Y-m-d H:i:s", $time_string);
    return $time_sql; 
}
function prep_sql_values($array) { //convert values to sql string with quotes. Exclude numeric 
    $prep_array = [];
    foreach($array as $record_key => $record_value) {
        $prep_array[$record_key] = is_numeric($record_value) ? $record_value : "'".$record_value."'";  
    }
    return $prep_array;
}
function write_sql() {
    global $create_array, $update_string, $update_count, $connection, $table_columns; 
    $transaction = "UPDATE records SET {$table_columns[9]}=1 WHERE {$table_columns[2]} <= NOW();"; //probably best to move below if working with outdated xml source
    if( !empty($create_array) || !empty($update_string) ) {
        //update
        if( !empty($update_string) )  $transaction .= $update_string;
        //create
        if( !empty($create_array) ) {
            $transaction .= "INSERT INTO records (".join(", ", array_keys($create_array[0])). ") VALUES ";
            $update_string_collection = [];
            foreach( $create_array as $create_record) {
                $attributes = prep_sql_values($create_record);
                $create_string_collection[] = "(".join(", ", array_values($attributes)).")";
            }
            $transaction .= join(", ",$create_string_collection)."; ";
        }
        //echo $transaction."<hr/>";
        echo "Synchronizing database: Created ".count($create_array)." new records, updated ".$update_count;
    } else {
        echo "All records are up to date, no changes needed.";
    }
    mysqli_multi_query($connection,$transaction); 
    mysqli_close($connection);
}
?>