<?php

class TicketBtns extends Nette\Application\UI\Control {
	
	private $chairs;

	public function __construct($ticket) {
		$this->chairs = $ticket->related("chair")->fetchPairs("id");
	}

	public function render() {
		$tpl = $this->createTemplate();
		$tpl->setFile(__DIR__ . "/btns.latte");
		$tpl->chairs = $this->chairs;
		$tpl->render();
	}

    public function handleToggleChair($id) {
        $chair = $this->chairs[$id];
        if($chair) {
            $chair->update(array( "ticked" => $chair->ticked == 0 ? 1 : 0 ));
        }
    }

}