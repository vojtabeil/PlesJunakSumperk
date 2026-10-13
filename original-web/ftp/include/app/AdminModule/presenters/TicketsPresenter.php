<?php

namespace AdminModule;

use Nette;
use Nette\Application\UI\Form;

// class TicketsPresenter extends \BasePresenter {
class TicketsPresenter extends \SecuredPresenter {
    
    /**
     ** CRUD - Users
     **/
         
    /** Display the list **/
    public function renderDefault($type = "all") {
        $this->template->bought = $this->context->tickets->getBought()->order("reserved DESC");
        $this->template->pending = $this->context->tickets->getReserved()->order("reserved DESC");

        if($type !== "all") {
            $this->template->pending->where("payment_method", $type);
        }
        $this->template->type = $type;

        $this->template->rejected = $this->context->tickets->getRejected()->order("reserved DESC");
        $this->template->inCash = $this->context->tickets->getNonRejected()->where("payment_method", "cash")->sum('price');
        $this->template->account = $this->context->tickets->getNonRejected()->where("payment_method", "online")->sum('price');
        $this->template->zTickets = $this->context->zTickets->getAll();
    
    }

    public function renderExport() {
        $this->template->tickets = $this->context->tickets->getBought();
    }

    public function renderZ() {
        $this->template->tickets = $this->context->zTickets->getAll();
        
    }
    
    
    /** Delete user **/
    public function handleOk($id) {
        
        if($this->context->tickets->confirmTicketPayment($id, $this->context->settings->getVariable("sender_email", "plesskaut@gmail.com"))) {
            $this->flashMessage("Platba rezervace s id $id byla potvrzena.", "success");            
        } else {
            $this->flashMessage("Nepodařilo se platbu s id $id potvrdit.", "error");
        }
        
        $this->redirect('this');
    }

    public function handleTogglePaymentMethod($id) {
        if($this->context->tickets->togglePaymentMethod($id)) {
            $this->flashMessage("Platební metoda rezervace s id $id byla změněna.", "success");
        } else {
            $this->flashMessage("Platební metodu rezervaci s id $id nebylo možno změnit.", "error");
        }

        $this->redirect('this');
    }
    
    /** Delete user **/
    public function handleReject($id) {
        if($this->context->tickets->reject($id, $this->context->settings->getVariable("sender_email", "plesskaut@gmail.com"))) {
            $this->flashMessage("Rezervace s id $id byla zrušena.", "success");            
        } else {
            $this->flashMessage("Nepodařilo se rezervaci s id $id zamítnout.", "error");
        }
        
        $this->redirect('this');
    }

    public function createComponentChairs() {
        $presenter = $this;
        return new Nette\Application\UI\Multiplier(function($ticketId) use ($presenter) {
            $ticket = $presenter->context->getService("tickets")->get($ticketId);
            return new \TicketBtns($ticket);
        });
    }

    public function handleToggleZTaken($id) {
        $tick = $this->context->getService("zTickets")->getAll()->where("id", $id)->fetch();
        if($tick) {
            $tick->update(array(
                "taken"     => $tick->taken == 1 ? 0 : 1
            ));

            if($this->isAjax()) {
                $this->invalidateControl("zTickets");
            } else {
                $this->redirect("this");
            }
        }
    }

    public function handleChangeZName($id, $name) {
        $tick = $this->context->getService("zTickets")->get($id);
        if($tick) {
            $tick->update(array(
                "name"     => $name
            ));

            if(!$this->isAjax()) {
                $this->redirect("this");
            }
        }
    }

    /**
     *
     */
    public function handleRevertOK($id) {

        if($this->context->tickets->revertOk($id, $this->context->settings->getVariable("sender_email", "plesskaut@gmail.com"))) {
            $this->flashMessage("Platba rezervace s id $id byla odebrána.", "success");            
        } else {
            $this->flashMessage("Nepodařilo se platbu s id $id odebrat.", "error");
        }
        
        $this->redirect('this');
    }

    public function handlePrint($id, $email) {
        $this->redirect(":Front:Ticket:detail", [ "id" => $id, "email" => $email ]);
    }

    /**
     * A method for finding n-th prime numbers. Usage example follows at the end.
     * @author Paul Scott <paul.scotto@gmail.com>
     */
}
