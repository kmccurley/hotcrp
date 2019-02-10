<?php
// listactions/la_get_rev.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2019 Eddie Kohler; see LICENSE.

class GetPcassignments_ListAction extends ListAction {
    function allow(Contact $user) {
        return $user->is_manager();
    }
    function run(Contact $user, $qreq, $ssel) {
        list($header, $items) = ListAction::pcassignments_csv_data($user, $ssel->selection());
        return $user->conf->make_csvg("pcassignments")->select($header)->add($items);
    }
}

class GetReviewBase_ListAction extends ListAction {
    protected $isform;
    protected $iszip;
    protected $author_view;
    function __construct($isform, $iszip) {
        $this->isform = $isform;
        $this->iszip = $iszip;
    }
    protected function finish(Contact $user, $texts, $errors) {
        uksort($errors, "strnatcmp");

        if (empty($texts)) {
            if (empty($errors))
                Conf::msg_error("No papers selected.");
            else {
                $errors = array_map("htmlspecialchars", array_keys($errors));
                Conf::msg_error(join("<br>", $errors) . "<br>Nothing to download.");
            }
            return;
        }

        $warnings = array();
        $nerrors = 0;
        foreach ($errors as $ee => $iserror) {
            $warnings[] = $ee;
            if ($iserror)
                $nerrors++;
        }
        if ($nerrors)
            array_unshift($warnings, "Some " . ($this->isform ? "review forms" : "reviews") . " are missing:");

        $rfname = $this->author_view ? "aureview" : "review";
        if (!$this->iszip)
            $rfname .= count($texts) === 1 ? key($texts) : "s";

        if ($this->isform)
            $header = $user->conf->review_form()->textFormHeader(count($texts) > 1 && !$this->iszip);
        else
            $header = "";

        if (!$this->iszip) {
            $text = $header;
            if (!empty($warnings) && $this->isform) {
                foreach ($warnings as $w)
                    $text .= prefix_word_wrap("==-== ", $w, "==-== ");
                $text .= "\n";
            } else if (!empty($warnings))
                $text .= join("\n", $warnings) . "\n\n";
            $text .= join("", $texts);
            downloadText($text, $rfname);
        } else {
            $zip = new ZipDocument($user->conf->download_prefix . "reviews.zip");
            $zip->warnings = $warnings;
            foreach ($texts as $pid => $text)
                $zip->add_as($header . $text, $user->conf->download_prefix . $rfname . $pid . ".txt");
            $result = $zip->download();
            if (!$result->error)
                exit;
        }
    }
}

class GetReviewForm_ListAction extends GetReviewBase_ListAction {
    function __construct($conf, $fj) {
        parent::__construct(true, $fj->name === "get/revformz");
    }
    function allow(Contact $user) {
        return $user->is_reviewer();
    }
    function run(Contact $user, $qreq, $ssel) {
        $rf = $user->conf->review_form();
        if ($ssel->is_empty()) {
            // blank form
            $text = $rf->textFormHeader("blank") . $rf->textForm(null, null, $user, null) . "\n";
            downloadText($text, "review");
            return;
        }

        $texts = $errors = [];
        foreach ($user->paper_set($ssel) as $prow) {
            $whyNot = $user->perm_review($prow, null);
            if ($whyNot
                && !isset($whyNot["deadline"])
                && !isset($whyNot["reviewNotAssigned"]))
                $errors[whyNotText($whyNot, true)] = true;
            else {
                $texts[$prow->paperId] = "";
                if ($whyNot) {
                    $t = whyNotText($whyNot, true);
                    $errors[$t] = false;
                    if (!isset($whyNot["deadline"]))
                        $texts[$prow->paperId] .= prefix_word_wrap("==-== ", strtoupper($t) . "\n\n", "==-== ");
                }
                $rrows = $prow->full_reviews_of_user($user);
                if (empty($rrows))
                    $rrows[] = null;
                foreach ($rrows as $rrow)
                    $texts[$prow->paperId] .= $rf->textForm($prow, $rrow, $user, null) . "\n";
            }
        }

        $this->finish($user, $ssel->reorder($texts), $errors);
    }
}

class GetReviews_ListAction extends GetReviewBase_ListAction {
    private $include_paper;
    function __construct($conf, $fj) {
        parent::__construct(false, !!get($fj, "zip"));
        $this->include_paper = !!get($fj, "abstract");
        $this->author_view = !!get($fj, "author_view");
        require_once("la_get_sub.php");
    }
    function allow(Contact $user) {
        return $user->can_view_some_review();
    }
    function run(Contact $user, $qreq, $ssel) {
        $rf = $user->conf->review_form();
        $overrides = $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        if ($this->author_view && $user->privChair) {
            $au_seerev = $user->conf->au_seerev;
            $user->conf->au_seerev = Conf::AUSEEREV_YES;
        }
        $errors = $texts = [];
        foreach ($user->paper_set($ssel) as $prow) {
            if (($whyNot = $user->perm_view_paper($prow))) {
                $errors["#$prow->paperId: " . whyNotText($whyNot, true)] = true;
                continue;
            }
            $rctext = "";
            if ($this->include_paper)
                $rctext = GetAbstract_ListAction::render($prow, $user);
            $last_rc = null;
            $viewer = $this->author_view ? $prow->author_view_user() : $user;
            foreach ($prow->viewable_submitted_reviews_and_comments($user) as $rc) {
                if ($viewer === $user
                    || (isset($rc->reviewId)
                        ? $viewer->can_view_review($prow, $rc)
                        : $viewer->can_view_comment($prow, $rc))) {
                    $rctext .= PaperInfo::review_or_comment_text_separator($last_rc, $rc);
                    if (isset($rc->reviewId))
                        $rctext .= $rf->pretty_text($prow, $rc, $viewer, false, true);
                    else
                        $rctext .= $rc->unparse_text($viewer, true);
                    $last_rc = $rc;
                }
            }
            if ($rctext !== "") {
                if (!$this->include_paper) {
                    $header = "{$user->conf->short_name} Paper #{$prow->paperId} Reviews and Comments\n";
                    $rctext = $header . str_repeat("=", 75) . "\n"
                        . "* Paper #{$prow->paperId} {$prow->title}\n\n" . $rctext;
                }
                $texts[$prow->paperId] = $rctext;
            } else if (($whyNot = $user->perm_view_review($prow, null)))
                $errors["#$prow->paperId: " . whyNotText($whyNot, true)] = true;
        }
        $texts = $ssel->reorder($texts);
        $first = true;
        foreach ($texts as &$text) {
            if (!$first)
                $text = "\n\n\n" . str_repeat("* ", 37) . "*\n\n\n\n" . $text;
            $first = false;
        }
        unset($text);
        $user->set_overrides($overrides);
        if ($this->author_view && $user->privChair) {
            $user->conf->au_seerev = $au_seerev;
            Contact::update_rights();
        }
        $this->finish($user, $texts, $errors);
    }
}

class GetScores_ListAction extends ListAction {
    function allow(Contact $user) {
        return $user->can_view_some_review();
    }
    function run(Contact $user, $qreq, $ssel) {
        $rf = $user->conf->review_form();
        $overrides = $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        // compose scores; NB chair is always forceShow
        $errors = $texts = $any_scores = array();
        $any_decision = $any_reviewer_identity = false;
        foreach ($user->paper_set($ssel) as $row) {
            if (($whyNot = $user->perm_view_paper($row)))
                $errors[] = "#$row->paperId: " . whyNotText($whyNot);
            else if (($whyNot = $user->perm_view_review($row, null)))
                $errors[] = "#$row->paperId: " . whyNotText($whyNot);
            else {
                $row->ensure_full_reviews();
                $a = ["paper" => $row->paperId, "title" => $row->title];
                if ($row->outcome && $user->can_view_decision($row))
                    $a["decision"] = $any_decision = $user->conf->decision_name($row->outcome);
                foreach ($row->viewable_submitted_reviews_by_display($user) as $rrow) {
                    $view_bound = $user->view_score_bound($row, $rrow);
                    $this_scores = false;
                    $b = $a;
                    foreach ($rf->forder as $field => $f)
                        if ($f->view_score > $view_bound && $f->has_options
                            && ($rrow->$field || $f->allow_empty)) {
                            $b[$f->search_keyword()] = $f->unparse_value($rrow->$field);
                            $any_scores[$f->search_keyword()] = $this_scores = true;
                        }
                    if ($user->can_view_review_identity($row, $rrow)) {
                        $any_reviewer_identity = true;
                        $b["reviewername"] = trim($rrow->firstName . " " . $rrow->lastName);
                        $b["email"] = $rrow->email;
                    }
                    if ($this_scores)
                        $texts[$row->paperId][] = $b;
                }
            }
        }
        $user->set_overrides($overrides);

        if (!empty($texts)) {
            $header = ["paper", "title"];
            if ($any_decision)
                $header[] = "decision";
            if ($any_reviewer_identity)
                array_push($header, "reviewername", "email");
            return $user->conf->make_csvg("scores")
                ->select(array_merge($header, array_keys($any_scores)))
                ->add($ssel->reorder($texts));
        } else {
            if (empty($errors))
                $errors[] = "No papers selected.";
            Conf::msg_error(join("<br>", $errors));
        }
    }
}

class GetRank_ListAction extends ListAction {
    function allow(Contact $user) {
        return $user->conf->setting("tag_rank") && $user->is_reviewer();
    }
    function run(Contact $user, $qreq, $ssel) {
        $settingrank = $user->conf->setting("tag_rank") && $qreq->tag == "~" . $user->conf->setting_data("tag_rank");
        if (!$user->isPC && !($user->is_reviewer() && $settingrank))
            return self::EPERM;
        $tagger = new Tagger($user);
        if (($tag = $tagger->check($qreq->tag, Tagger::NOVALUE | Tagger::NOCHAIR))) {
            $real = "";
            $null = "\n";
            foreach ($user->paper_set($ssel, ["tagIndex" => $tag, "order" => "order by tagIndex, PaperReview.overAllMerit desc, Paper.paperId"]) as $prow)
                if ($user->can_change_tag($prow, $tag, null, 1)) {
                    $csvt = CsvGenerator::quote($prow->title);
                    if ($prow->tagIndex === null)
                        $null .= "X,$prow->paperId,$csvt\n";
                    else if ($real === "" || $lastIndex == $prow->tagIndex - 1)
                        $real .= ",$prow->paperId,$csvt\n";
                    else if ($lastIndex == $prow->tagIndex)
                        $real .= "=,$prow->paperId,$csvt\n";
                    else
                        $real .= str_pad("", min($prow->tagIndex - $lastIndex, 5), ">") . ",$prow->paperId,$csvt\n";
                    $lastIndex = $prow->tagIndex;
                }
            $text = "# Edit the rank order by rearranging this file's lines.

# The first line has the highest rank. Lines starting with \"#\" are
# ignored. Unranked papers appear at the end in lines starting with
# \"X\", sorted by overall merit. Create a rank by removing the \"X\"s and
# rearranging the lines. A line starting with \"=\" marks a paper with the
# same rank as the preceding paper. Lines starting with \">>\", \">>>\",
# and so forth indicate rank gaps between papers. When you are done,
# upload the file at\n"
                . "#   " . $user->conf->hoturl_absolute("offline", null, Conf::HOTURL_RAW) . "\n\n"
                . "Tag: " . trim($qreq->tag) . "\n"
                . "\n"
                . $real . $null;
            downloadText($text, "rank");
        } else
            Conf::msg_error($tagger->error_html);
    }
}

class GetLead_ListAction extends ListAction {
    private $type;
    function __construct($conf, $fj) {
        $this->type = $fj->type;
    }
    function allow(Contact $user) {
        return $user->isPC;
    }
    function run(Contact $user, $qreq, $ssel) {
        $key = $this->type . "ContactId";
        $can_view = "can_view_" . $this->type;
        $texts = array();
        foreach ($user->paper_set($ssel) as $row)
            if ($row->$key && $user->$can_view($row, true)) {
                $name = $user->name_object_for($row->$key);
                $texts[$row->paperId][] = [$row->paperId, $row->title, $name->firstName, $name->lastName, $name->email];
            }
        return $user->conf->make_csvg($this->type . "s")
            ->select(["paper", "title", "first", "last", "{$this->type}email"])
            ->add($ssel->reorder($texts));
    }
}
