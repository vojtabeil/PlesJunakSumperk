<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Model\Admin\AdminUsers;
use Nette\Application\UI\Form;


/** Login, name and password fields shared by the setup wizard and the administrator forms. */
final class AccountFields
{
	public static function add(Form $form, bool $passwordRequired): void
	{
		$form->addText('login', 'Přihlašovací jméno')
			->setHtmlAttribute('autocomplete', 'username')
			->setRequired('Zadejte přihlašovací jméno.')
			->addRule($form::Pattern, 'Přihlašovací jméno: 3–64 znaků, jen malá písmena bez diakritiky, číslice a . _ -', AdminUsers::LoginPattern);
		$form->addText('name', 'Jméno a příjmení')
			->setRequired('Vyplňte jméno.')
			->setMaxLength(255);

		$password = $form->addPassword('password', $passwordRequired ? 'Heslo' : 'Nové heslo')
			->setHtmlAttribute('autocomplete', 'new-password');
		$again = $form->addPassword('passwordAgain', $passwordRequired ? 'Heslo znovu' : 'Nové heslo znovu')
			->setHtmlAttribute('autocomplete', 'new-password');
		$minLength = sprintf('Heslo musí mít alespoň %d znaků.', AdminUsers::MinPasswordLength);
		if ($passwordRequired) {
			$password->setRequired('Zadejte heslo.')->addRule($form::MinLength, $minLength, AdminUsers::MinPasswordLength);
			$again->setRequired('Zadejte heslo ještě jednou.');
		} else {
			$password->setOption('description', 'Nechte prázdné, pokud se heslo nemění.')
				->addCondition($form::Filled)->addRule($form::MinLength, $minLength, AdminUsers::MinPasswordLength);
		}
		$again->addConditionOn($password, $form::Filled)
			->setRequired('Zadejte nové heslo ještě jednou.')
			->addRule($form::Equal, 'Hesla se neshodují.', $password);
	}
}
