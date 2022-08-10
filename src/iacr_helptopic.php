<?php
// src/iacr_helptopic.php -- HotCRP help functions

class IACR_HelpTopic {
    static function render_all($hth, $gj) {
      global $Me;
      echo $hth->subhead("Available only to program chairs");
      if ($Me->privChair) {
        echo "<p><a href=\"../iacr/\">Located here</a></p>";
      }
    }
}
