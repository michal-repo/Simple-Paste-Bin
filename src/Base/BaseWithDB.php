<?php

namespace SrcLibrary\Base;

use SrcLibrary\MyDB\DB;

class BaseWithDB {

    public $db;

    public function __construct() {
        $this->db = new DB();
    }
}
