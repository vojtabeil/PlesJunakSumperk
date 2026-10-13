<?php

namespace AdminModule;

use Nette\Application\UI\Form;

class HallPresenter extends \SecuredPresenter {
    
    public function renderDefault() {
        $this->template->chairs = $this->context->getService("chairs")->getAll();
        if($this->isAjax()) {
            $this->invalidateControl("chairs");
        }
    }

    public function handleAdd($x, $y) {
        $this->context->getService("chairs")->add($x, $y);
    }
    
    public function handleDelete($id) {
        $chair = $this->context->getService("chairs")->get($id);
        if($chair) {
        	$chair->delete();
        }
    }

    public function handleMove($x, $y, $id) {
        $this->context->getService("chairs")->move($id, $x, $y);
    }

    public function handleToggleLowerPrice($id) {
        $chair = $this->context->getService("chairs")->get($id);
        $chair->update(array(
            "is_lower_price" => !($chair->is_lower_price == TRUE)
        ));
    }

}