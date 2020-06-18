<?php

namespace App\Models;

use Nette\Database\Context;
use Nette\Security\AuthenticationException;
use Nette\Security\IAuthenticator;
use Nette\Security\Identity;
use Nette\Security\IIdentity;
use Nette\Security\Passwords;

class UserModel implements IAuthenticator {

	/** @var Context  */
	protected $db;

	/** @var Passwords  */
	protected $passwords;

	public function __construct(Context $db, Passwords $passwords) {

		$this->db = $db;
		$this->passwords = $passwords;

	}

	public function fetch(int $userId) {

		return $this->db
			->table('user')
			->wherePrimary($userId)
			->fetch();

	}

	public function fetchAll() {

		return $this->db
			->table('user')
			->fetchAll();

	}

	public function save(array $data) {

		$data['password'] = $this->passwords->hash($data['password']);

		if ($data['id'] ?? null) {

			$this->db
				->table('user')
				->wherePrimary($data['id'])
				->update($data);

		} else {

			$data['role'] = 'user';
			$this->db
				->table('user')
				->insert($data);

		}

	}

	public function authenticate(array $credentials): IIdentity {

		[$name, $password] = $credentials;

		$row = $this->db
			->table('user')
			->where('name', $name)
			->fetch();

		if (!$row) {
			$this->db
				->table('user')
				->insert([
					'name' => $name,
					'role' => 'guest',
				]);
			$row = $this->db
				->table('user')
				->where('name', $name)
				->fetch();
		}

		return new Identity($row['id'], $row['role'], [
			'name' => $row['name'],
		]);

	}
}
