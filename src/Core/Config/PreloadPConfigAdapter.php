<?php

namespace Friendica\Core\Config;

use Exception;
use Friendica\Core\PConfig;
use Friendica\Database\DBA;

/**
 * Preload User Configuration Adapter
 *
 * Minimizes the number of database queries to retrieve configuration values at the cost of memory.
 *
 * @author Hypolite Petovan <hypolite@mrpetovan.com>
 */
class PreloadPConfigAdapter implements IPConfigAdapter
{
	private $config_loaded = false;

	public function __construct($uid)
	{
		$this->load($uid, 'config');
	}

	public function load($uid, $family)
	{
		if ($this->config_loaded) {
			return;
		}

		if (empty($uid)) {
			return;
		}

		$pconfigs = DBA::select('pconfig', ['cat', 'v', 'k'], ['uid' => $uid]);
		while ($pconfig = DBA::fetch($pconfigs)) {
			PConfig::setPConfigValue($uid, $pconfig['cat'], $pconfig['k'], $pconfig['v']);
		}
		DBA::close($pconfigs);

		$this->config_loaded = true;
	}

	public function get($uid, $cat, $k, $default_value = null, $refresh = false)
	{
		if (!$this->config_loaded) {
			$this->load($uid, $cat);
		}

		if ($refresh) {
			$config = DBA::selectFirst('pconfig', ['v'], ['uid' => $uid, 'cat' => $cat, 'k' => $k]);
			if (DBA::isResult($config)) {
				PConfig::setPConfigValue($uid, $cat, $k, $config['v']);
			} else {
				PConfig::deletePConfigValue($uid, $cat, $k);
			}
		}

		$return = PConfig::getPConfigValue($uid, $cat, $k, $default_value);

		return $return;
	}

	public function set($uid, $cat, $k, $value)
	{
		if (!$this->config_loaded) {
			$this->load($uid, $cat);
		}
		// We store our setting values as strings.
		// So we have to do the conversion here so that the compare below works.
		// The exception are array values.
		$compare_value = !is_array($value) ? (string)$value : $value;

		if (PConfig::getPConfigValue($uid, $cat, $k) === $compare_value) {
			return true;
		}

		PConfig::setPConfigValue($uid, $cat, $k, $value);

		// manage array value
		$dbvalue = is_array($value) ? serialize($value) : $value;

		$result = DBA::update('pconfig', ['v' => $dbvalue], ['uid' => $uid, 'cat' => $cat, 'k' => $k], true);
		if (!$result) {
			throw new Exception('Unable to store config value in [' . $uid . '][' . $cat . '][' . $k . ']');
		}

		return true;
	}

	public function delete($uid, $cat, $k)
	{
		if (!$this->config_loaded) {
			$this->load($uid, $cat);
		}

		PConfig::deletePConfigValue($uid, $cat, $k);

		$result = DBA::delete('pconfig', ['uid' => $uid, 'cat' => $cat, 'k' => $k]);

		return $result;
	}
}
