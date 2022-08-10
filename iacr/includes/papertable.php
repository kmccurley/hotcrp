<?php
// Form submission options (PaperOption) receives special treatment for some IACR-created
// options. These are indicated by $opt->iacrSetting, and may be extended in the future.
// This is included from src/papertable.php. and src/settings/s_options.php
// It is intended for handling of special IACR paper options in the submission
// form. Options have a "iacrSetting" field in their json, which is serialized along with
// other fields.
//
class IACRSetting {
  const COPYRIGHT = 'copyright';
  const FINAL_PAPER = 'final_paper';
  const SLIDES = 'slides';
  const VIDEO = 'video';
  const RUMP_NOTE = 'rump_note';
  const RUMP_MINUTES = 'rump_minutes';
  const SUPPLEMENTARY_MATERIAL = 'supplementary_material';
  const LONG_PAPER = 'long_paper';
  const RESUBMISSION = 'resubmission';
  const COMMENTS_TO_EDITOR = 'comments_to_editor';
  const SPEAKER = 'speaker';
};
// We used to base this on the ID, but that was brittle and
// kept colliding with other options created by the chair.
// Old values used to be:
// 5: copyright     (required for authors, may not be deleted)
// 6: upload paper  (required for authors, may not be deleted)
// 7: upload slides (optional for authors, may not be deleted)
// 8: upload video. (optional for authors, may be deleted)
// Because these were changed, it isn't really feasible to do
// updates on instances created before this switch.
/**
 * Called with a PaperOption to determine if it requires special
 * handling for a button to perform an external action.
 */
function has_iacr_button(PaperOption $opt) {
  return ($opt->iacrSetting != null &&
          ($opt->iacrSetting == IACRSetting::COPYRIGHT ||
           $opt->iacrSetting == IACRSetting::FINAL_PAPER ||
           $opt->iacrSetting == IACRSetting::SLIDES ||
           $opt->iacrSetting == IACRSetting::VIDEO));
}

/**
 * These options may not be deleted by the administrator. Slides and
 * video are optional and may be deleted by the admin. If those are
 * omitted they cannot be restored unless we hand code the option.
 */
function is_iacr_required_paper_option(PaperOption $opt) {
  return ($opt->iacrSetting != null &&
          ($opt->iacrSetting == IACRSetting::COPYRIGHT ||
           $opt->iacrSetting == IACRSetting::FINAL_PAPER));
}

/**
 * Used to echo a button. $opt should be a PaperOption with
 * iacrSetting defined.
*/
function echo_iacr_button(PaperOption $opt, Conf $conf, $paperId) {
  global $Me;
  $email = $Me->email;
  include_once('/var/www/util/hotcrp/hmac.php');
  $paper_msg = get_paper_message($conf->opt['iacrType'],
                                 $conf->opt['year'],
                                 $paperId,
                                 $email,
                                 'hc',
                                 $conf->opt['dbName']);
  $querydata = array('venue' => $conf->opt['iacrType'],
                     'year' => $conf->opt['year'],
                     'paperId' => $paperId,
                     'email' => $email,
                     'shortName' => $conf->opt['dbName'],
                     'auth' => get_hmac($paper_msg),
                     'app' => 'hc');
  switch($opt->iacrSetting) {
    case IACRSetting::COPYRIGHT:
      $url = '/' . $conf->opt['dbName'] . '/iacrcopyright/' . strval($paperId);
      $msg = 'IACR copyright form';
      break;
    case IACRSetting::FINAL_PAPER:
      $url = 'https://iacr.org/submit/upload/paper.php?' . http_build_query($querydata);
      $msg = 'Upload final paper';
      break;
    case IACRSetting::SLIDES:
      $url = 'https://iacr.org/submit/upload/slides.php?' . http_build_query($querydata);
      $msg = 'Upload slides';
      break;
    case IACRSetting::VIDEO:
      $url = 'https://iacr.org/submit/upload/video.php?' . http_build_query($querydata);
      $msg = 'Upload video';
      break;
    default:
      return;
  }
  $extras = array('class' => 'iacrSubmitButtons');
  echo Ht::link($msg, $url, $extras);
}

?>
