<?php

namespace App\Models;

use Nette\Database\Context;
use Nette\Utils\Image;

class GameModel {

	const STATE_LOGIN = 'login';
	const STATE_DRAWING = 'drawing';
	const STATE_SUGGESTING = 'suggesting';
	const STATE_GUESSING = 'guessing';
	const STATE_END = 'end';


	const GOOD_DRAWN_PICTURE = 3;
	const GOOD_MISLED_SUGGESTION = 2;
	const GOOD_GUESS = 1;

	/** @var Context  */
	protected $db;

	/** @var string  */
	protected $imageStoragePath;

	public function __construct(string $imageStoragePath, Context $db) {

		$this->db = $db;
		$this->imageStoragePath = $imageStoragePath;

	}

	public function fetch(int $id) {

		return $this->db
			->table('game')
			->wherePrimary($id)
			->fetch();

	}

	public function fetchDrawing(int $gameId, int $userId) {

		return $this->db
			->table(\Table::DRAWING)
			->where('game_id', $gameId)
			->where('user_id', $userId)
			->where('image IS NULL')
			->fetch();

	}

	public function fetchSuggestion(int $gameId, int $userId) {

		return $this->db
			->table(\Table::SUGGESTIONS)
			->select('suggestions.*, drawing_id.image')
			->where('drawing_id.game_id', $gameId)
			->where('suggestions.user_id', $userId)
			->where('suggestions.word IS NULL')
			->fetch();

	}

	public function fetchUserGuess(int $userId, int $drawingId) {

		return $this->db
			->table(\Table::GUESSES)
			->where('user_id', $userId)
			->where('drawing_id', $drawingId)
			->fetch();

	}

	public function fetchGuessDrawing(int $gameId) {

		$game = $this->db
			->table(\Table::GAME)
			->select('guessing_id')
			->wherePrimary($gameId)
			->fetch();

		$drawingId = $game['guessing_id'];
		if (!$drawingId) {
			return;
		}

		$suggestions = $this->db
			->table(\Table::SUGGESTIONS)
			->select('suggestions.*, drawing_id.image')
			->where('drawing_id', $drawingId)
			->fetchAll();

		$res = [
			'guesses' => [],
		];
		foreach ($suggestions as $suggestion) {
			$res['guesses'][] = [
				'id' => $suggestion['id'],
				'word' => $suggestion['word'],
				'correct' => $suggestion['correct'],
			];
		}

		if ($suggestions) {
			$res['image'] = $suggestion['image'];
			$res['id'] = $suggestion['drawing_id'];
		}

		return $res;

	}

	public function fetchGuessAnswers(int $drawingId) {

		return $this->db
			->table(\Table::GUESSES)
			->select('user_id.name, suggestion_id.word')
			->where('guesses.drawing_id', $drawingId)
			->fetchAll();

	}


	public function fetchSuggestions(int $gameId) {

		return $this->db
			->table(\Table::SUGGESTIONS)
			->select('suggestions.*, user_id.name, drawing_id.image, drawing_id.word AS correct_word')
			->where('drawing_id.game_id', $gameId)
			->order('suggestions.drawing_id')
			->order('suggestions.correct DESC')
			->fetchAll();

	}

	public function fetchResults(int $gameId) {

		$allUsers = $this->db
			->table('user')
			->fetchPairs('id', 'name');

		$allUsers = array_map(function($name) {
			return [
				'name' => $name,
				'points' => 0,
			];
		}, $allUsers);

		$rounds = $this->db
			->table(\Table::GUESSES)
			->select('guesses.id, guesses.user_id, user_id.name, suggestion_id.correct, suggestion_id.user_id AS suggestor, drawing_id.user_id AS author_id')
			->fetchAll();

		foreach($rounds as $round) {

			if ($round['user_id'] == $round['suggestor']) {
				//dump('user cant select his suggestion');
				continue;
			}

			if ($round['correct'] && $round['user_id'] == $round['author_id']) {
				// dump('user cant select correct answer within his image');
				continue;
			}

			if ($round['correct']) {
				//dump('its correct, author gest 3 points, guesser 1');
				$allUsers[$round['author_id']]['points'] += self::GOOD_DRAWN_PICTURE;
				$allUsers[$round['user_id']]['points'] += self::GOOD_GUESS;
			} else if ($round['suggestor']) {
				//dump('its not correct, suggester gets 2 points');
				$allUsers[$round['suggestor']]['points'] += self::GOOD_MISLED_SUGGESTION;
			} else {
				//dump('suggestor is null, he gets nothing');
			}

		}

		usort($allUsers, function($a, $b) {
			return $b['points'] <=> $a['points'];
		});

		return $allUsers;

	}

	public function setState(int $gameId, string $newState) {

		$this->db
			->table('game')
			->wherePrimary($gameId)
			->update([
				'state' => $newState,
			]);

	}

	public function generateDrawings(int $gameId, int $questions) {

		$this->db
			->table(\Table::DRAWING)
			->where('game_id', $gameId)
			->delete();

		$users = $this->db
			->table('game_users')
			->where('game_id', $gameId)
			->fetchAll();

		$bank = $this->db
			->table('words_bank')
			->order('RAND()')
			->fetchPairs('id', 'word');

		shuffle($bank);

		foreach ($users as $user) {
			for ($i = 0; $i < $questions; $i++) {

				$word = array_pop($bank);

				$this->db
					->table(\Table::DRAWING)
					->insert([
						'game_id' => $gameId,
						'user_id' => $user['user_id'],
						'word' => $word,
					]);

			}
		}

	}


	public function generateSuggestions(int $gameId) {

		$this->db
			->table(\Table::SUGGESTIONS)
			->delete();

		$gameUsers = $this->db
			->table(\Table::GAME_USERS)
			->where('game_id' , $gameId)
			->fetchPairs('user_id', 'user_id');

		foreach($gameUsers as $userId) {

			$drawings = $this->db
				->table(\Table::DRAWING)
				->where('game_id' ,$gameId)
				->where('user_id', $userId)
				->fetchAll();

			$index = 1;
			foreach ($drawings as $drawing) {

				// Insert correct answer
				$this->db
					->table(\Table::SUGGESTIONS)
					->insert([
						'drawing_id' => $drawing['id'],
						'user_id' => null,
						'word' => $drawing['word'],
						'correct' => true,
					]);

				for ($i = 0; $i < 2; $i++) {
					$this->db
						->table(\Table::SUGGESTIONS)
						->insert([
							'drawing_id' => $drawing['id'],
							'user_id' => self::getNthUserId($userId, $gameUsers, $index),
							'correct' => false,
						]);
					$index++;
				}

			}
		}

		// Generate random suggestions
		$bank = $this->db
			->table('words_bank')
			->order('RAND()')
			->fetchPairs('id', 'word');

		$drawings = $this->db
			->table(\Table::DRAWING)
			->where('game_id', $gameId)
			->fetchAll();

		foreach ($drawings as $drawing) {

			do {
				shuffle($bank);
				$word = $bank[0];
			} while ($word == $drawing['word']);

			$this->db
				->table(\Table::SUGGESTIONS)
				->insert([
					'drawing_id' => $drawing['id'],
					'user_id' => null,
					'word' => $word,
					'correct' => false,
				]);
		}

	}

	public function selectNextGuess(int $gameId) {

		$allDrawings = $this->db
			->table(\Table::DRAWING)
			->where('game_id', $gameId)
			->fetchPairs('id', 'id');

		$allDrawings = array_values($allDrawings);

		$alreadyGuessed = $this->db
			->table(\Table::GUESSES)
			->select('drawing_id')
			->group('drawing_id')
			->fetchPairs('drawing_id', 'drawing_id');
		$alreadyGuessed = array_values($alreadyGuessed);

		$toGuess = array_diff($allDrawings, $alreadyGuessed);

		shuffle($toGuess);
		$nextGuess = array_pop($toGuess);
		if ($nextGuess) {
			$this->db
				->table(\Table::GAME)
				->wherePrimary($gameId)
				->update([
					'guessing_id' => $nextGuess,
				]);
		} else {
			$this->db
				->table(\Table::GAME)
				->wherePrimary($gameId)
				->update([
					'state' => self::STATE_END,
				]);
		}

	}

	protected static function getNthUserId(int $userId, array $allUsers, int $n): int {

		$allUsers = array_values($allUsers);
		$index = array_search($userId, $allUsers);

		$newIndex = $index;
		for ($i = 1; $i <= $n; $i++) {
			$newIndex += 1;
			$newIndex = $newIndex % count($allUsers);
			if ($newIndex == $index) {
				$newIndex++;
			}
			$newIndex = $newIndex % count($allUsers);

		}

		return $allUsers[$newIndex];

	}

	public function storeDrawing(int $id, string $imageData) {

		$image_string=str_replace("data:image/png;base64,","",$imageData);
		$image_string = base64_decode($image_string);

		$i = Image::fromString($image_string);

		$ext = '.png';
		$name = bin2hex(random_bytes(10));
		$newPath = '/images/drawings/' . $name . $ext;

		$i->save($this->imageStoragePath . '/' . $newPath,1, $i::PNG);

		$this->db
			->table(\Table::DRAWING)
			->wherePrimary($id)
			->update([
				'image' => $newPath,
			]);

	}

	public function saveSuggestion(int $id, string $word) {

		$this->db
			->table(\Table::SUGGESTIONS)
			->wherePrimary($id)
			->update([
				'word' => $word,
			]);

	}

	public function saveGuess(int $userId, int $drawingId, int $suggestionId) {

		$this->db
			->table(\Table::GUESSES)
			->insert([
				'user_id' => $userId,
				'drawing_id' => $drawingId,
				'suggestion_id' => $suggestionId ?: null,
			]);

	}

	public function addUserIntoGame(int $gameId, int $userId) {

		$row = $this->db
			->table('game_users')
			->where('game_id', $gameId)
			->where('user_id', $userId)
			->fetch();

		if ($row) {
			return;
		}

		$this->db
			->table('game_users')
			->insert([
				'game_id' => $gameId,
				'user_id' => $userId,
			]);

	}

}
