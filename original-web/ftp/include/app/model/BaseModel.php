<?php

namespace Model;

use Nette;

abstract class BaseModel extends Nette\NetteObject {
    
    /** @var Nette\Datatbase\Connection */
    private $db;
    
    /** @var string */
    protected $table = "";
    
    protected function table() {
        return $this->db->table($this->table);
    }

    public function get($pk) {
        return $this->table()->get($pk);
    }

    public function getAll() {
        return $this->table();
    }
    
    /** @param Nette\Datatbase\Connection */
    public function __construct(Nette\Database\Connection $db) {
        $this->db = $db;
    }    
    
}