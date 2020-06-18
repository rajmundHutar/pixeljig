<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\GameModel;
use Nette;


final class HomepagePresenter extends Nette\Application\UI\Presenter {

	const PASS = '125874';
	const GAME_ID = 1;
	const QUESTIONS = 3;

	/** @var GameModel  */
	protected $gameModel;

	/** @var array */
	protected $guessDrawing;

	public function __construct(GameModel $gameModel) {
		$this->gameModel = $gameModel;
	}

	public function actionGuessing() {

		if (!$this->user->isLoggedIn()) {
			$this->redirect('default');
		}

		$game = $this->gameModel->fetch(self::GAME_ID);

		if ($game['state'] != GameModel::STATE_GUESSING) {
			$this->redirect($game['state']);
		}

		$this->template->game = $game;

		$this->guessDrawing = $this->gameModel->fetchGuessDrawing(self::GAME_ID);
		$this->template->guessDrawing = $this->guessDrawing;
		$this->template->userGuess = $this->gameModel->fetchUserGuess($this->user->getId(), $this->guessDrawing['id']);

	}

	public function actionLogout() {

		$this->user->logout(true);
		$this->redirect('default');

	}

	public function handleDrawingSubmit() {

		$id = $this->getHttpRequest()->getPost('id');
		$image = $this->getHttpRequest()->getPost('image');

		$this->gameModel->storeDrawing((int) $id, $image);

		$this->sendJson([
			'id' => $id,
			'image' => $image,
		]);

	}

	public function createComponentUserForm() {

		$f = new Nette\Application\UI\Form();

		$f->addText('name', 'Zadej jméno:')
			->setRequired(true);

		$f->addPassword('pass' ,'Heslo')
			->setRequired(false);

		$f->addSubmit('ok', 'Přidat do hry');

		$f->onSuccess[] = function(Nette\Application\UI\Form $form) {

			$values = $form->getValues(true);

			if (($values['pass'] ?? null) === self::PASS) {

				$values = $form->getValues(true);

				$this->user->login($values['name'], 'admin');

				$this->redirect('Admin:');

			}

			$this->user->login($values['name'], null);

			$this->gameModel->addUserIntoGame(self::GAME_ID, $this->user->getId());

			$this->redirect('login');

		};

		return $f;

	}

	public function createComponentSuggestForm() {

		$f = new Nette\Application\UI\Form();

		$f->addText('suggestedWord', 'Tvůj návrh:');
		$f->addSubmit('ok', 'Odeslat');
		$f->addHidden('suggestion_id');

		$f->onSuccess[] = function (Nette\Application\UI\Form $form) {
			$values = $form->getValues(true);

			$this->gameModel->saveSuggestion(
				(int) $values['suggestion_id'],
				(string) $values['suggestedWord']
			);

			$this->redirect('suggesting');

		};

		return $f;

	}

	public function createComponentGuessForm() {

		$f = new Nette\Application\UI\Form();

		$radio = [];
		foreach ($this->guessDrawing['guesses'] as $guess) {
			$radio[$guess['id']] = $guess['word'];
		}

		$radio = assoc_array_shuffle($radio);

		$f->addRadioList('guess', 'Co mysíš že to opravu je?', $radio);

		$f->addSubmit('ok', 'Odeslat');

		$f->onSuccess[] = function (Nette\Application\UI\Form $form) {

			$values = $form->getValues(true);
			$this->gameModel->saveGuess((int)$this->user->getId(), (int)$this->guessDrawing['id'], (int) $values['guess']);
			$this->redirect('guessing');

		};

		return $f;

	}

	public function renderDefault($displayAdminLogin = false) {

		$this->template->displayAdminLogin = $displayAdminLogin;

	}


	public function renderLogin() {

		if (!$this->user->isLoggedIn()) {
			$this->redirect('default');
		}

		$game = $this->gameModel->fetch(self::GAME_ID);

		if ($game['state'] != GameModel::STATE_LOGIN) {

			$this->redirect($game['state']);

		}

		$this->template->game = $game;
		$this->template->gameUsers = $game->related('game_users');

	}

	public function renderDrawing() {

		if (!$this->user->isLoggedIn()) {
			$this->redirect('default');
		}

		$game = $this->gameModel->fetch(self::GAME_ID);

		if ($game['state'] != GameModel::STATE_DRAWING) {
			$this->redirect($game['state']);
		}

		$this->template->game = $game;
		$drawing = $this->gameModel->fetchDrawing(self::GAME_ID, $this->user->getId());

		$this->template->drawing = $drawing;

	}

	public function renderSuggesting() {

		if (!$this->user->isLoggedIn()) {
			$this->redirect('default');
		}

		$game = $this->gameModel->fetch(self::GAME_ID);

		if ($game['state'] != GameModel::STATE_SUGGESTING) {
			$this->redirect($game['state']);
		}

		$this->template->game = $game;
		$suggestion = $this->gameModel->fetchSuggestion(self::GAME_ID, $this->user->getId());


		if($suggestion['id']) {
			$this['suggestForm']->setDefaults([
				'suggestion_id' => $suggestion['id'],
			]);
		}

		$this->template->suggestion = $suggestion;

	}

	public function renderEnd() {

		if (!$this->user->isLoggedIn()) {
			$this->redirect('default');
		}

		$game = $this->gameModel->fetch(self::GAME_ID);
		$this->template->game = $game;

		if ($game['state'] != GameModel::STATE_END) {
			$this->redirect($game['state']);
		}

		$this->template->results = $this->gameModel->fetchResults(self::GAME_ID);

		$allSuggestions = $this->gameModel->fetchSuggestions(self::GAME_ID);

		$suggestions = [];
		foreach ($allSuggestions as $suggestion) {
			if ($suggestions[$suggestion['drawing_id']] ?? null) {
				$suggestions[$suggestion['drawing_id']]['suggestions'][] = [
					'word' => $suggestion['word'],
					'user' => $suggestion['user_id'],
					'name' => $suggestion['name'],
					'correct' => $suggestion['correct'],
				];
			} else {
				$suggestions[$suggestion['drawing_id']] = [
					'image' => $suggestion['image'],
					'suggestions' => [[
						'word' => $suggestion['word'],
						'user' => $suggestion['user_id'],
						'name' => $suggestion['name'],
						'correct' => $suggestion['correct'],
					]],
				];
			}
		}

		$this->template->suggestions = $suggestions;

	}

}

function assoc_array_shuffle($array) {
	$orig = array_flip($array);
	shuffle($array);
	foreach($array AS $key=>$n)
	{
		$data[$n] = $orig[$n];
	}
	return array_flip($data);
}