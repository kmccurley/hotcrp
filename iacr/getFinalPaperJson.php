<?php
//  include "../conf/options.php";
//  include "../src/initweb.php";
//  require "/var/www/util/hotcrp/hmac.php";
//  global $Opt;
//  $dbname = $Opt['dbName'];
require "finalLib.php";
$paperdata = getFinalPaperData();
header("Content-Type: application/json");
echo json_encode($paperdata);

?>
