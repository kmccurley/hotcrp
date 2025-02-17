<?php
// responseround.php -- HotCRP helper class for response rounds
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class ResponseRound {
    /** @var bool */
    public $unnamed = false;
    /** @var string */
    public $name;
    /** @var int */
    public $id;
    /** @var bool */
    public $active = false;
    /** @var int */
    public $open = 0;
    /** @var int */
    public $done = 0;
    /** @var int */
    public $grace = 0;
    /** @var int */
    public $words = 500;
    /** @var ?PaperSearch */
    public $search;
    /** @var ?string */
    public $instructions;

    /** @param bool $with_grace
     * @return bool */
    function time_allowed($with_grace) {
        return $this->active
            && $this->open > 0
            && $this->open <= Conf::$now
            && ($this->done <= 0
                || $this->done + ($with_grace ? $this->grace : 0) >= Conf::$now);
    }

    /** @param bool $with_grace
     * @return bool */
    function can_author_respond(PaperInfo $prow, $with_grace) {
        return $this->time_allowed($with_grace)
            && (!$this->search || $this->search->test($prow));
    }

    /** @return bool */
    function relevant(Contact $user, PaperInfo $prow = null) {
        if (($prow ? $user->allow_administer($prow) : $user->is_manager())
            && ($this->done || $this->search || $this->name !== "1")) {
            return true;
        } else if ($user->isPC) {
            return $this->open > 0;
        } else {
            return $this->active
                && $this->open > 0
                && $this->open < Conf::$now
                && (!$this->search || $this->search->filter($prow ? [$prow] : $user->authored_papers()));
        }
    }

    /** @return string */
    function tag_name() {
        return $this->unnamed ? "unnamedresponse" : $this->name . "response";
    }

    /** @return string */
    function instructions(Conf $conf) {
        $fmt = $conf->fmt();
        if ($this->instructions !== null
            && !$fmt->has_override("resp_instrux_{$this->id}")) {
            $fmt->add_override("resp_instrux_{$this->id}", $this->instructions);
        }
        $fa = new FmtArg("wordlimit", $this->words);
        $m = $fmt->_ci("resp_instrux", "resp_instrux_{$this->id}", $fa);
        if ($m === "") {
            $m = $fmt->_ci("resp_instrux", "resp_instrux", $fa);
        }
        return $m;
    }
}
