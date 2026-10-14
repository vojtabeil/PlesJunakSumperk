<?php

namespace FrontModule;

use Nette\Application\UI\Form;

class TicketPresenter extends \BasePresenter {
    
    private $ticket;

    public function actionDefault($id, $email = NULL) {
        $this->ticket = $this->context->getService("tickets")->get($id);
        if(!$this->ticket) {
            $this->flashMessage("Lístek neexistuje.", "danger");
            $this->redirect("Content:default");
        }
    }

    public function renderDefault($id, $email = NULL) {
        if ($email !== NULL) {
            $this["checkEmail"]->setDefaults(array( "email" => $email ));
        }
    }

    public function actionEntranceCheck($chairId, $email) {
        if(!$this->user->isLoggedIn()) {
            $this->flashMessage("Děkujeme za zakoupení lístku na ples. Budeme se těšit 4. 2. 2023 v Rapotíně!", "success");
            $this->redirect("Content:default");
        }

        $correctEmail = $this->context->chairs->belongsTo($chairId, $email);
        $isTicked = $this->context->chairs->isTicked($chairId);

        $this->template->chairId = $chairId;
        $this->template->email = $email;
        $this->template->letIn = $correctEmail && !$isTicked;
        $this->template->confirmedAt = $this->context->chairs->confirmedAt($chairId);
    }
    
    public function handleConfirm($chairId, $email) {
        if(!$this->user->isLoggedIn()) {
            $this->flashMessage("Děkujeme za zakoupení lístku na ples. Budeme se těšit 4. 2. 2023 v Rapotíně!", "success");
            $this->redirect("Content:default");
        }

        $correctEmail = $this->context->chairs->belongsTo($chairId, $email);
        $isTicked = $this->context->chairs->isTicked($chairId);

        if ($correctEmail && !$isTicked) {
            $this->context->chairs->toggle($chairId);
        }

        $this->redirect("this");
    }

    public function createComponentCheckEmail() {
        $form = new Form;

        $form->addText("email", "Emailová adresa: ")
            ->setRequired("Musíte vyplnit emailovou adresu.");
        $form->addSubmit("check", "Ověřit");
        $form->onSuccess[] = callback($this, "check");


        return $form;
    }

    public function check($form) {
        $email = $form->values->email;
        if($email === $this->ticket->email) {
            $this->redirect("detail", [ "id" => $this->ticket->id, "email" => $email ]);
        } else {
            $form->addError("Toto není správný email.");
            return false;
        }
    }

    public function actionDetail($id, $email = NULL) {
        if ($email === NULL) {
            $this->redirect("default", $id);
        }
    
        $t = $this->context->getService("tickets")->get($id);
        if(!$t) {
            $this->flashMessage("Lístek neexistuje.", "danger");
            $this->redirect("Content:default");
        }
    
        if ($t->email !== $email) {
            $this->redirect("default", $id, $email);
        }
    
        if($t->state !== "paid") {
            $this->flashMessage("Tento lístek ještě není zaplacený.", "danger");
            $this->redirect("Content:default");            
        }

        $this->ticket = $t;
    }

    public function renderDetail($id, $email = NULL) {

        $this->template->ticket = $this->ticket;
        $this->template->chairs = $this->context->getService("chairs")->getAll();
    }

}