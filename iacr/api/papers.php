<?php
require "lib.php";

global $Opt;

header('Content-Type: application/json');
if (!isset($_GET['auth'])) {
  showError('Unauthenticated request');
  exit;
}
$msg = get_conf_message($Opt['dbName'], $Opt['iacrType'], $Opt['year']);

if (!validate_hmac($_GET['auth'], $msg)) {
  showError('Bad auth token');
  exit;
}
  
try {
  $dbname = $Opt['dbName'];
  $db = new PDO("mysql:host=localhost;dbname=$dbname;charset=utf8", $Opt['dbUser'], $Opt['dbPassword']);
  $sql = "SELECT paperId,title,authorInformation,abstract FROM Paper WHERE outcome > 0 AND timeWithdrawn = 0";
  $stmt = $db->query($sql);
  $papers = $stmt->fetchAll(PDO::FETCH_ASSOC);

  foreach ($papers as &$paper) {
    $authorInfo = preg_split("/[\n]/", $paper['authorInformation'], -1, PREG_SPLIT_NO_EMPTY);
    $paper['authors'] = array();
    $paper['affiliations'] = array();
    $paper['authorlist'] = array();
    // concats first and last names and adds to authors array + populates affiliations array
    foreach ($authorInfo as $authorLine) {
      // this is a HotCRP thing
      $author = Author::make_tabbed($authorLine);
      $name = $author->firstName . ' ' . $author->lastName;
      $paper['authorlist'][] = array('name' => $name,
                                     'lastName' => $author->lastName,
                                     'affiliation' => $author->affiliation);
      $paper['authors'][] = $name;
      $paper['affiliations'][] = $author->affiliation;
    }
    unset($paper['authorInformation']);
  }

  unset($paper);
  $data = array('_source' => 'IACR/hotcrp v1',
                'shortName' => $Opt['shortName'],
                'longName' => $Opt['longName'],
                'venue' => $Opt['iacrType'],
                'year' => $Opt['year'],
                'acceptedPapers' => $papers);
  echo json_encode($data, JSON_PRETTY_PRINT);
  $db = null;
} catch (PDOException $e) {
  echo $e->message();
}
?>
