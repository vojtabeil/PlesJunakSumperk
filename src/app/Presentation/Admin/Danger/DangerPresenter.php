<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Danger;

use App\Model\Hall\HallChanges;
use App\Model\Hall\HallError;
use App\Model\Hall\HallPlan;
use App\Model\Hall\HallPlanParser;
use App\Model\Hall\HallSync;
use App\Model\Hall\HallUploads;
use App\Model\Reservation\ReservationAdmin;
use App\Presentation\Accessory\SvgResponse;
use App\Presentation\Admin\BasePresenter;
use Nette\Application\UI\Form;
use Nette\Forms\Helpers;
use Nette\Http\FileUpload;
use Nette\Http\Session;


/**
 * Nebezpečná zóna (/admin/nebezpecne): actions that cannot be undone.
 *   default  overview, deleting test reservations
 *   plan     the hall plan: download, upload a new one, preview (incl. reservations to be cancelled), apply
 *   svg      the plan as a file (?stahnout=1 download, ?nahled=<token> = the uploaded one waiting for confirmation)
 * @property-read DangerTemplate $template
 */
final class DangerPresenter extends BasePresenter
{
	private const MaxUploadBytes = 5 * 1024 * 1024;

	private ?string $uploadToken = null;
	private ?HallPlan $uploaded = null;
	private ?HallChanges $changes = null;


	public function __construct(
		private readonly HallSync $sync,
		private readonly HallUploads $uploads,
		private readonly ReservationAdmin $reservationAdmin,
		private readonly Session $session,
	) {
		parent::__construct();
	}


	public function renderDefault(): void
	{
		$this->template->plan = $this->sync->plan();
		$this->template->testCount = $this->reservationAdmin->testCount();
	}


	public function actionPlan(?string $upload = null): void
	{
		if ($upload === null) {
			return;
		}
		$svg = $this->uploads->load($upload);
		if ($svg === null) {
			$this->flashMessage('Nahraný plánek už vypršel (platí hodinu). Nahrajte ho prosím znovu.', 'error');
			$this->redirect('plan', ['upload' => null]);
		}
		$this->uploadToken = $upload;
		$this->uploaded = HallPlanParser::parse($svg);
		$this->changes = $this->uploaded->isValid() ? $this->sync->preview($this->uploaded) : null;
	}


	public function renderPlan(): void
	{
		$t = $this->template;
		$t->plan = $this->sync->plan();
		$t->uploadToken = $this->uploadToken;
		$t->uploadName = $this->uploadToken !== null ? $this->uploadName($this->uploadToken) : null;
		$t->uploaded = $this->uploaded;
		$t->changes = $this->changes;
	}


	/** @param bool $stahnout download the plan as it is stored (the backup before a replacement) */
	public function actionSvg(?string $nahled = null, bool $stahnout = false): void
	{
		if ($nahled !== null) {
			$svg = $this->uploads->load($nahled) ?? $this->error('Nahraný plánek už vypršel.');
			$this->sendResponse(new SvgResponse(self::colourSeats(HallPlanParser::parse($svg)->svg), $this->uploadName($nahled)));
		}
		$plan = $this->sync->plan() ?? $this->error('Plánek sálu zatím není nahraný.');
		$this->sendResponse(new SvgResponse($stahnout ? $plan['svg'] : self::colourSeats($plan['svg']), $plan['file_name'], download: $stahnout));
	}


	/** Seats have no colour of their own (the seat picker colours them): show them in the previews. */
	private static function colourSeats(string $svg): string
	{
		return (string) preg_replace('~^(<svg\b[^>]*>)~', '$1<style>.seat, .seat * { fill: #287234; }</style>', $svg, 1);
	}


	protected function createComponentUploadForm(): Form
	{
		// The hosting's PHP may allow less than our limit.
		$limit = min(array_filter(
			[self::MaxUploadBytes, Helpers::iniGetSize('upload_max_filesize'), Helpers::iniGetSize('post_max_size')],
			static fn(int $size): bool => $size > 0, // 0 = no limit
		));
		$form = $this->formFactory->create();
		$form->addUpload('file', 'Nový plánek (SVG z CorelDRAW)')
			->setRequired('Vyberte soubor SVG.')
			->addRule($form::MaxFileSize, sprintf('Soubor je větší než %s MB.', round($limit / 1024 / 1024, 1)), $limit)
			->setHtmlAttribute('accept', '.svg,image/svg+xml');
		$form->addSubmit('upload', 'Nahrát a zkontrolovat');
		$form->onSuccess[] = function (Form $form, \stdClass $data): void {
			$file = $data->file;
			assert($file instanceof FileUpload);
			if (!$file->isOk()) {
				$this->flashMessage('Soubor se nepodařilo nahrát.', 'error');
				$this->redirect('this');
			}
			$token = $this->uploads->store($file->getContents() ?? '');
			$this->session->getSection('hallUploads')->set($token, $file->getUntrustedName());
			$this->redirect('plan', ['upload' => $token]);
		};
		return $form;
	}


	/** Replaces the plan; the affected reservations the organizer saw go with the form. */
	protected function createComponentApplyForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addHidden('token', $this->uploadToken);
		$form->addHidden('affected', implode(',', $this->changes?->affectedIds() ?? []));
		$form->addCheckbox('replace', 'Rozumím, že současný plánek bude nenávratně nahrazen.')
			->setRequired('Potvrďte nahrazení plánku.');
		$count = count($this->changes->affected ?? []);
		if ($count > 0) {
			$form->addCheckbox('cancel', sprintf(
				'Rozumím, že se zruší rezervací: %d (zaplaceno celkem %s). Zákazníkům nepřijde e-mail.',
				$count,
				number_format($this->changes?->affectedPaid() ?? 0, 0, ',', "\u{A0}") . "\u{A0}Kč",
			))->setRequired('Potvrďte zrušení rezervací.');
		}
		$form->addSubmit('apply', 'Použít nový plánek');
		$form->onSuccess[] = function (Form $form, \stdClass $data): void {
			$token = (string) $data->token;
			$svg = $this->uploads->load($token);
			if ($svg === null) {
				$this->flashMessage('Nahraný plánek už vypršel. Nahrajte ho prosím znovu.', 'error');
				$this->redirect('plan', ['upload' => null]);
			}
			$confirmed = array_map('intval', array_filter(explode(',', (string) $data->affected)));
			try {
				$changes = $this->sync->apply(HallPlanParser::parse($svg), $this->uploadName($token), $confirmed);
			} catch (HallError $e) {
				$this->flashMessage($e->getMessage(), 'error');
				$this->redirect('plan', ['upload' => $token]);
			}
			$this->uploads->delete($token);
			$this->flashMessage(
				'Nový plánek je použitý.' . ($changes->affected ? ' Zrušeno rezervací: ' . count($changes->affected) . '.' : ''),
				'success',
			);
			$this->redirect('plan', ['upload' => null]);
		};
		return $form;
	}


	protected function createComponentDiscardForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addHidden('token', $this->uploadToken);
		$form->addSubmit('discard', 'Zahodit nahraný plánek')
			->setHtmlAttribute('class', 'button');
		$form->onSuccess[] = function (Form $form, \stdClass $data): void {
			if ($this->uploads->load((string) $data->token) !== null) {
				$this->uploads->delete((string) $data->token);
			}
			$this->redirect('plan', ['upload' => null]);
		};
		return $form;
	}


	protected function createComponentDeleteTestsForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addCheckbox('confirm', 'Opravdu smazat všechny testovací rezervace (místa se uvolní)')
			->setRequired('Akci potvrďte zaškrtnutím.');
		$form->addSubmit('delete', 'Smazat testovací rezervace');
		$form->onSuccess[] = function (): void {
			$count = $this->reservationAdmin->deleteTestReservations();
			$this->flashMessage("Smazáno testovacích rezervací: $count.", 'success');
			$this->redirect('this');
		};
		return $form;
	}


	private function uploadName(string $token): string
	{
		$name = $this->session->getSection('hallUploads')->get($token);
		return is_string($name) && $name !== '' ? $name : 'plan-salu.svg';
	}
}
