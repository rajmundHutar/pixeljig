<?php

namespace App\Presenters;

use App\Models\GameModel;
use Nette\Application\UI\Presenter;

final class AdminPresenter extends Presenter {

	const GAME_ID = 1;
	const QUESTIONS = 3;

	/** @var GameModel */
	protected $gameModel;

	public function __construct(GameModel $gameModel) {
		$this->gameModel = $gameModel;
	}

	public function handleStart() {

		$this->gameModel->generateDrawings(self::GAME_ID, self::QUESTIONS);
		$this->gameModel->setState(self::GAME_ID, GameModel::STATE_DRAWING);
		$this->redirect('Admin:');

	}

	public function handleEndDrawing() {

		$this->gameModel->generateSuggestions(self::GAME_ID);
		$this->gameModel->setState(self::GAME_ID, GameModel::STATE_SUGGESTING);
		$this->redirect('Admin:');

	}

	public function handleEndSuggesting() {

		$this->gameModel->setState(self::GAME_ID, GameModel::STATE_GUESSING);
		$this->gameModel->selectNextGuess(self::GAME_ID);
		$this->redirect('Admin:');

	}

	public function handleNextGuess() {

		$this->gameModel->selectNextGuess(self::GAME_ID);
		$this->redirect('Admin:');

	}

	public function renderDefault() {

		if (!$this->user->isInRole('admin')) {
			$this->redirect('default');
		}

		$game = $this->gameModel->fetch(self::GAME_ID);

		$this->template->game = $game;

		$gameUsers = [];
		foreach ($game->related('game_users') as $gu) {
			$user = $gu->ref('user');

			$drawings = [];
			foreach($user->related('drawing') as $item) {
				$drawings[$item['id']] = [
					'id' => $item['id'],
					'image' => $item['image'],
					'word' => $item['word'],
				];
			}

			$gameUsers[$user->id] = [
				'id' => $user->id,
				'name' => $user->name,
				'drawings' => $drawings,
			];


		}
		$this->template->gameUsers = $gameUsers;


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

		$guessDrawing = $this->gameModel->fetchGuessDrawing(self::GAME_ID);

		$this->template->guessDrawing = $guessDrawing;
		$this->template->guessAnswers = $guessDrawing ? $this->gameModel->fetchGuessAnswers($guessDrawing['id']) : [];

	}

}