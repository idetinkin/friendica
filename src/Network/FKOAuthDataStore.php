<?php

/**
 * @file src/Network/FKOAuthDataStore.php
 * OAuth server
 * Based on oauth2-php <http://code.google.com/p/oauth2-php/>
 *
 */

namespace Friendica\Network;

use Friendica\Core\Config;
use Friendica\Core\Logger;
use Friendica\Database\DBA;
use OAuthConsumer;
use OAuthDataStore;
use OAuthToken;

define('REQUEST_TOKEN_DURATION', 300);
define('ACCESS_TOKEN_DURATION', 31536000);

/**
 * @brief OAuthDataStore class
 */
class FKOAuthDataStore extends OAuthDataStore
{
	/**
	 * @return string
	 */
	private static function genToken()
	{
		return Friendica\Util\Strings::getRandomHex(32);
	}

	/**
	 * @param string $consumer_key key
	 * @return mixed
	 * @throws \Exception
	 */
	public function lookup_consumer($consumer_key)
	{
		Logger::log(__function__ . ":" . $consumer_key);

		$s = DBA::select('clients', ['client_id', 'pw', 'redirect_uri'], ['client_id' => $consumer_key]);
		$r = DBA::toArray($s);

		if (DBA::isResult($r)) {
			return new OAuthConsumer($r[0]['client_id'], $r[0]['pw'], $r[0]['redirect_uri']);
		}

		return null;
	}

	/**
	 * @param string $consumer   consumer
	 * @param string $token_type type
	 * @param string $token      token
	 * @return mixed
	 * @throws \Exception
	 */
	public function lookup_token($consumer, $token_type, $token)
	{
		Logger::log(__function__ . ":" . $consumer . ", " . $token_type . ", " . $token);

		$s = DBA::select('tokens', ['id', 'secret', 'scope', 'expires', 'uid'], ['client_id' => $consumer->key, 'scope' => $token_type, 'id' => $token]);
		$r = DBA::toArray($s);

		if (DBA::isResult($r)) {
			$ot = new OAuthToken($r[0]['id'], $r[0]['secret']);
			$ot->scope = $r[0]['scope'];
			$ot->expires = $r[0]['expires'];
			$ot->uid = $r[0]['uid'];
			return $ot;
		}

		return null;
	}

	/**
	 * @param string $consumer  consumer
	 * @param string $token     token
	 * @param string $nonce     nonce
	 * @param string $timestamp timestamp
	 * @return mixed
	 * @throws \Exception
	 */
	public function lookup_nonce($consumer, $token, $nonce, $timestamp)
	{
		$token = DBA::selectFirst('tokens', ['id', 'secret'], ['client_id' => $consumer->key, 'id' => $nonce, 'expires' => $timestamp]);
		if (DBA::isResult($token)) {
			return new OAuthToken($token['id'], $token['secret']);
		}

		return null;
	}

	/**
	 * @param string $consumer consumer
	 * @param string $callback optional, default null
	 * @return mixed
	 * @throws \Exception
	 */
	public function new_request_token($consumer, $callback = null)
	{
		Logger::log(__function__ . ":" . $consumer . ", " . $callback);
		$key = self::genToken();
		$sec = self::genToken();

		if ($consumer->key) {
			$k = $consumer->key;
		} else {
			$k = $consumer;
		}

		$r = DBA::insert(
			'tokens',
			[
				'id' => $key,
				'secret' => $sec,
				'client_id' => $k,
				'scope' => 'request',
				'expires' => time() + REQUEST_TOKEN_DURATION
			]
		);

		if (!$r) {
			return null;
		}

		return new OAuthToken($key, $sec);
	}

	/**
	 * @param string $token    token
	 * @param string $consumer consumer
	 * @param string $verifier optional, defult null
	 * @return object
	 * @throws HTTPException\InternalServerErrorException
	 */
	public function new_access_token($token, $consumer, $verifier = null)
	{
		Logger::log(__function__ . ":" . $token . ", " . $consumer . ", " . $verifier);

		// return a new access token attached to this consumer
		// for the user associated with this token if the request token
		// is authorized
		// should also invalidate the request token

		$ret = null;

		// get user for this verifier
		$uverifier = Config::get("oauth", $verifier);
		Logger::log(__function__ . ":" . $verifier . "," . $uverifier);

		if (is_null($verifier) || ($uverifier !== false)) {
			$key = self::genToken();
			$sec = self::genToken();
			$r = DBA::insert(
				'tokens',
				[
					'id' => $key,
					'secret' => $sec,
					'client_id' => $consumer->key,
					'scope' => 'access',
					'expires' => time() + ACCESS_TOKEN_DURATION,
					'uid' => $uverifier
				]
			);

			if ($r) {
				$ret = new OAuthToken($key, $sec);
			}
		}

		DBA::delete('tokens', ['id' => $token->key]);

		if (!is_null($ret) && !is_null($uverifier)) {
			Config::delete("oauth", $verifier);
		}

		return $ret;
	}
}
