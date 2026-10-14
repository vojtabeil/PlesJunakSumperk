<?php

namespace AdminModule;

use Nette\Application\UI\Form;
use Nette\Application\UI\Multiplier;

class SettingsPresenter extends \SecuredPresenter {
    
    /**
     ** CRUD - Users
     **/
     
     
    private $settings = array();
    public function actionDefault() {
        $this->settings = $this->context->settings->getAll();
    }

    /** Display the list **/
    public function renderDefault() {
        $this["settingsForm"]->setDefaults($this->settings->fetchPairs("name", "value"));
    }

    public function createComponentSettingsForm() {
        $form = new Form;

        foreach ($this->settings->fetchPairs("name", "value") as $name => $value) {
            $form->addText($name, $name);    
        }

        $form->addSubmit("send", "Odeslat");

        $form->onSuccess[] = $this->updateSettings;

        return $form;
    }

    public function updateSettings($form) {
        $values = $form->values;
        unset($values["send"]);

        $settings = $this->settings->fetchPairs("name");
        $total = 0;
        foreach ($values as $name => $value) {
            $settings[$name]->value = $value;
            $total += $settings[$name]->update();
        }

        if($total > 0) {
            $this->flashMessage("Upraveno $total nastavení.", "success");
        } else {
            $this->flashMessage("Nedošlo k žádné změně", "warning");
        }

        $this->redirect("this");
    }
}