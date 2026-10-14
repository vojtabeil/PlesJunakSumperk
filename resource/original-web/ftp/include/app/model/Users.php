<?php

namespace Model;

use Nette;

class Users extends Nette\NetteObject {
    
    /** @var Nette\Datatbase\Connection */
    private $db;
    
    /** @var string */
    private $table = "users";
    
    private function table() {
        return $this->db->table($this->table);
    }
    
    /** @param Nette\Datatbase\Connection */
    public function __construct(Nette\Database\Connection $db) {
        $this->db = $db;
    }    
    
    /**
     * @param string
     * @param string
     * @param string
     * @return Nette\Database\Table\ActiveRow
     */
    public function addUser($name, $password, $role) {
        $data = array(
            'username' => $name,
            'password' => \Authenticator::calculateHash($password),
            'role' => $role
        );
        
        return $this->table()->insert($data);
    }
    
    /**
     * @param Nette\Database\Table\ActiveRow
     * @param string
     * @param string
     * @param string
     * @return int|FALSE
     */
    public function updateUser($userRow, $name, $password, $role) {
        $data = array(
            'username' => $name,
            'role' => $role
        );
        
        if(!empty($password)) {
            $data['password'] = \Authenticator::calculateHash($password);
        }
        
        return $userRow->update($data);
    }
    
    
    /** @return Nette\Database\Table\Selection */
    public function getAll() {
        return $this->table()->order('username');
    }
    
    
    /** @return Nette\Database\Table\ActiveRow */
    public function get($id) {
        return $this->table()->where('id', $id)->fetch();
    }
    
    
}