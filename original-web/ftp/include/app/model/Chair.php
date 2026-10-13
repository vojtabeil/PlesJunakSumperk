<?php

namespace Model;

use Nette;

class Chair extends BaseModel {
        
    /** @var string */
    protected $table = "chairs";

    public function getVisible() {
        return $this->table()->where(array("visible" => 1));
    }

    public function add($x, $y) {
        return $this->table()->insert(array(
            "x" => $x,
            "y" => $y,
            "ticket_id" => NULL
        ));
    }

    public function move($id, $x, $y) {
        $row = $this->get($id);
    	return $row->update(array(
    		"x"	=> $x,
    		"y"	=> $y
    	));
    }

    public function take($chairId, $ticketId) {
        $chair = $this->table()->get($chairId);
        return $chair->update(array(
            "ticket_id" => $ticketId
        ));
    }

    public function free($chairId) {
        $chair = $this->table()->get($chairId);
    	return $chair->update(array(
    		"ticket_id" => NULL
    	));
    }

    public function belongsTo($chairId, $email) {
        $chair = $this->table()->get($chairId);
        return $chair->ticket->email == $email;
    }

    public function isTicked($chairId) {
        $chair = $this->table()->get($chairId);
        return $chair->ticked == 1;
    }

    public function confirmedAt($chairId) {
        $chair = $this->table()->get($chairId);
        return $chair->confirmed_at;
    }

    public function toggle($chairId) {
        $chair = $this->table()->get($chairId);
        return $chair->update(array(
            "ticked" => $chair->ticked == 0 ? 1 : 0,
            "confirmed_at" => $chair->ticked == 0 ? new \DateTime() : NULL
        ));
    }
}