<?php

namespace Model;

class TicketChair extends BaseModel {
	
	protected $table = "ticket_chair";

	public function connect($ticketId, $chairId) {
		return $this->table()->insert(array(
			"ticket_id"		=> $ticketId,
			"chair_id"		=> $chairId
		));
	}

}