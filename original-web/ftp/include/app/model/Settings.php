<?php

namespace Model;

use Nette;

class Settings extends Nette\NetteObject {
    
    /** @var Nette\Datatbase\Connection */
    private $db;
    
    /** @var string */
    private $table = "settings";
    
    private function table() {
        return $this->db->table($this->table);
    }

    public function getAll() {
        return $this->table();
    }
    
    /** @param Nette\Datatbase\Connection */
    public function __construct(Nette\Database\Connection $db) {
        $this->db = $db;
    }    
    
    public function getVariable($name, $defaultValue = 0) {
        $var = $this->table()->where("name", $name)->fetch();

        if($var === FALSE) {
            $this->createVariable($name, $defaultValue);
            return $defaultValue;
        }

        return $var->value; // returns FALSE if such variable doesn't exist
    }   

    public function setVariable($name, $value) {
        $var = $this->getVariable($name);
        if($var === FALSE) {
            $this->createVariable($name, $value);
        } else {
            $var->value = $value;
            $var->update();
        }
    }

    private function createVariable($name, $defaultValue) {
        $data = array(
            "name" => $name,
            "value" => $defaultValue,
        );

        $this->table()->insert($data);
    }
}