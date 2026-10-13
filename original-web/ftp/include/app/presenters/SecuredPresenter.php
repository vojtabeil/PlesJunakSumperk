<?php

class SecuredPresenter extends BasePresenter {
    
    
    public function startup() {
        parent::startup();
        
        if(!$this->user->isLoggedIn()) {
            $this->flashMessage("Do této sekce nemáte přísutp, přihlašte se.", "warning");
            $this->redirect(':Admin:Sign:in');
        }
    }
    
    
}