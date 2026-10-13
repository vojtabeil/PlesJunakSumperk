<?php

namespace AdminModule;

use Nette\Application\UI\Form;

class UsersPresenter extends \SecuredPresenter {
    
    /**
     ** CRUD - Users
     **/
     
     
    /** Display the list **/
    public function renderDefault() {
        $this->template->users = $this->context->users->getAll();
    } 
     
     
     
    

    private function prepareUserForm() {
        $form = new Form;
        $form->addText('username', 'Uživatelské jméno:')
                ->setRequired('Zadejte uživatelské jméno.');
                
        $form->addPassword('password', 'Heslo:');
                
        $form->addPassword('password2', 'Heslo znovu')
                ->addConditionOn($form["password"], Form::FILLED)            
                    ->addRule(Form::FILLED, 'Přepište heslo ještě jednou.')
                    ->addRule(Form::EQUAL, 'Hesla se neshodují.', $form["password"]);
                
        $form->addText('role', 'Role:')
                ->setRequired('Vyplňte roli.');
                
                
        return $form;
    }
    
    /** Add user **/
    public function createComponentAddUser() {
        $form = $this->prepareUserForm();
        $form["password"]->setRequired("Musíte zvolit nějaké heslo.");
        $form->addSubmit('send', 'Přidat uživatele');
        $form->onSuccess[] = callback($this, "addUser");
        
        return $form;
    }
    
    public function addUser($form) {        
        $values = $form->values;
        if($this->context->users->addUser($values->username, $values->password, $values->role)) {
            $this->flashMessage("Uživatelský účet byl úspěšně založen.", "success");
        } else {
            $this->flashMessage("Stala se chyba při přidávání uživatelského účtu.", "error");
        }
        
        $this->redirect("default");
    }
    
    
    /** Update user **/
    private $userRow;    
    public function actionUpdate($id) {
        $this->userRow = $this->context->users->get($id);
        $this["updateUser"]->setDefaults($this->userRow);
    }
    
    public function createComponentUpdateUser() {
        $form = $this->prepareUserForm();
        $form->addSubmit('send', 'Upravit uživatele');
        $form->onSuccess[] = callback($this, "updateUser");
        
        return $form;
    }
    
    public function updateUser($form) {
        $values = $form->values;        
                
        if($this->context->users->updateUser($this->userRow, $values->username, $values->password, $values->role)) {
            $this->flashMessage("Uživatelský účet byl úspěšně upraven.", "success");
        } else {
            $this->flashMessage("Stala se chyba při upravování uživatelského účtu.", "error");
        }
        
        $this->redirect("default");
    }
    
    
    /** Delete user **/
    public function handleDelete($id) {
        $user = $this->context->users->get($id);
        if($user->delete()) {
            $this->flashMessage("Uživatel byl smazán.", "success");            
        } else {
            $this->flashMessage("Nepodařilo se uživatele smazat.", "error");
        }
        
        $this->redirect('default');
    }
    
}