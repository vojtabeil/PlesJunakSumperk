<?php

namespace FrontModule;

use Nette\Application\UI\Form;

class ContentPresenter extends \BasePresenter {
    
    
    public function renderDefault() {
        $this->template->price = $this->context->settings->getVariable("price", 120);
        $this->template->lowerPrice = $this->context->settings->getVariable("lowerPrice", 110);
        $this->template->secondPrice = $this->context->settings->getVariable("secondPrice", 120);
        $this->template->secondLowerPrice = $this->context->settings->getVariable("secondLowerPrice", 110);
        $this->template->capacity = count($this->context->chairs->getAll());
        $this->template->free = count($this->context->chairs->getAll()->where("ticket_id", NULL)) + count($this->context->chairs->getAll()->where("ticket.state", "rejected"));
        $this->template->boughtCount = $this->context->tickets->getBoughtTicketsCount();
        $this->template->reservedCount = $this->context->tickets->getPendingTicketsCount();
        $this->template->maxTicketsCount = $this->maxTicketsCount();
    }
    
    public function createComponentReservationForm() {
    	$form = new Form;

    	$form->addText("name", "Jméno a příjmení")->setRequired("Musíte zadat jméno pro rezervaci.");
    	$form->addText("email", "Email")->AddRule(Form::EMAIL, "Email není ve správném formátu, opravte ho.")->setRequired("Musíte zadat jméno pro rezervaci.");
    	$form->addText("price", "Cena")->setRequired("Musíte si rezervovat alespoň jeden lístek.")->setDisabled()->getControlPrototype()->class[] = "price-field";

        $chairs = $this->context->getService("chairs")->getVisible();
        $seats = $form->addContainer("seats");
        foreach($chairs as $chair) {
            $seats->addCheckbox($chair->id, "Místo {$chair->id}");
            $seats[$chair->id]->setAttribute("data-x", $chair->x);
            $seats[$chair->id]->setAttribute("data-y", $chair->y);

            $status = $chair->ticket_id == NULL ? "free" : ($chair->ticket->state === "rejected" ? "free" : "taken");
            $seats[$chair->id]->setOption("status", $status);
            $seats[$chair->id]->setOption("id", $chair->id);
            $seats[$chair->id]->setOption("lower-price", $chair->is_lower_price);
            if($status === "taken") {
                $seats[$chair->id]->setDisabled();
            }
        }

        $form->addSelect("alcoholStripCount", "Počet osob ve věku 18 - 30 let (počet pásků pro možnost kupování alkoholu):", array( 0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10 ));        
        $form->addSelect("payment_method", "Způsob platby", array(
            "online"  => "Platba převodem"
        ));
    	$form->addSubmit("send", "Dokončit rezervaci")
                ->getControlPrototype()->class[] = "btn btn-large btn-success";
    	$form->onSuccess[] = callback($this, "finishReservation");

    	return $form;
    }

    public function finishReservation($form) {
    	$values = $form->values;
        $max = $this->maxTicketsCount();

        $count = 0;
        $lowerCount = 0;

        if(time() < strtotime("2020-02-01")) {
            $price = intval($this->context->settings->getVariable("price", 120));
            $lowerPrice = intval($this->context->settings->getVariable("lowerPrice", 110)); 
        } else {
            $price = $this->context->settings->getVariable("secondPrice", 120);
            $lowerPrice = $this->context->settings->getVariable("secondLowerPrice", 110);
        }

        $selected = array();
        foreach($form->values->seats as $id => $checked) {
            if($checked === TRUE) {
                $selected[] = $id;
                if($form["seats"][$id]->getOption("lower-price") == TRUE) {
                    $lowerCount++;
                } else {
                    $count++;
                }
            }
        }

        if ($count + $lowerCount == 0) {
            $form->addError("Rezervujte si, prosím, alespoň jeden lístek. Vyberete ho kliknutím na zelený nebo modrý čtvereček na plánku.");
            return FALSE;
        }

        if ($count + $lowerCount !== count($selected)) { // hack!!
            $form->addError("Tolik míst není možné označit.");
            return FALSE;
        }

        if (intval($values->alcoholStripCount) > $count + $lowerCount) {
            $form->addError("Počet osob v rozmezí 18 - 30 let nemůže být větší než celkový počet míst.");
            return FALSE;
        }

        if($this->context->tickets->countOfReservations($values->email) >= $max) {
            $form->addError("Počet lístků, které je možné rezervovat na jeden email, byl vyčerpán.", "warning");
            return FALSE;
        } elseif ($this->context->tickets->countOfReservations($values->email) + $count > $this->maxTicketsCount()) {
            $form->addError("Tolik lístků si již nemůžete rezerovat. Maximální počet na osobu je {$this->maxTicketsCount()} a Vy už jich máte {$this->context->tickets->countOfReservations($values->email)} využitých.", "warning");
            return FALSE;
        } else {
            $ticket = $this->context->tickets->orderTickets($values->name, $values->email, $count, $values->alcoholStripCount, $lowerCount, $price, $lowerPrice, $values->payment_method, $this->context->settings->getVariable("sender_email", "plesskaut@gmail.com"));
            if($ticket !== FALSE) {
                foreach($selected as $chairId) {
                    $this->context->getService("chairs")->take($chairId, $ticket->id);
                }

                $this->flashMessage("Rezervace proběhla v pořádku. Na uvedený email Vám byly odeslány další pokyny.", "success");
            } else {
                $this->flashMessage("Stala se chyba. Zkuste to znovu za chvíli. Pokud chyba přetrvává, kontaktujte správce emailem: simon@rozsival.com.", "warning");
            }    
        }

    	$this->redirect("this");
    }
    

    private function maxTicketsCount() {
    	$max = intval($this->context->settings->getVariable("max_tickets", 10));
    	$capacity = intval($this->context->settings->getVariable("capacity", 200));

        $bought = intval($this->context->tickets->getBoughtTicketsCount());
        $reserved = intval($this->context->tickets->getPendingTicketsCount());

    	return min($max, $capacity - ($bought + $reserved));
    }
    

    
}