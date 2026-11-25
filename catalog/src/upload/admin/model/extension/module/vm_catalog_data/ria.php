<?php
/**
 * Car reference from the official AUTO.RIA API (developers.ria.com, own API key of the shop):
 * makes -> models -> generations (years). The free package allows 30 requests per hour and 1000 in total,
 * so only makes/models we have parts for are requested, at most PER_HOUR per hour, full refresh every 180 days.
 *
 * Only what the shop needs is requested: models of the makes we have parts for, generations of the models
 * that match a model family from the supplier fitment data. Requests are spread over time (small steps).
 * Terms of AUTO.RIA: a link to AUTO.RIA must be shown on the site (the theme puts it in the footer).
 */
class VmCatalogRia {
	const API = 'https://developers.ria.com/';

	private $db;
	private $config;
	public $error = '';

	public function __construct($registry) {
		$this->db = $registry->get('db');
		$this->config = $registry->get('config');
	}

	public static function dir() {
		return DIR_STORAGE . 'upload/vm_catalog/';
	}

	private static function jobFile() {
		return self::dir() . 'ria_job.json';
	}

	public function install() {
		$p = DB_PREFIX;
		$this->db->query("CREATE TABLE IF NOT EXISTS `{$p}vm_ria_make` (
			`ria_id` int(11) NOT NULL, `name` varchar(64) NOT NULL, `make_id` int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY (`ria_id`), KEY `make_id` (`make_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
		$this->db->query("CREATE TABLE IF NOT EXISTS `{$p}vm_ria_model` (
			`ria_id` int(11) NOT NULL, `ria_make_id` int(11) NOT NULL, `name` varchar(96) NOT NULL, `fkey` varchar(64) NOT NULL DEFAULT '',
			`gens_at` int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY (`ria_id`), KEY `ria_make_id` (`ria_make_id`), KEY `fkey` (`fkey`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
		$this->db->query("CREATE TABLE IF NOT EXISTS `{$p}vm_ria_gen` (
			`gen_id` int(11) NOT NULL, `ria_model_id` int(11) NOT NULL, `name` varchar(128) NOT NULL,
			`year_from` smallint(4) NOT NULL DEFAULT 0, `year_to` smallint(4) NOT NULL DEFAULT 0, `mods_at` int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY (`gen_id`), KEY `ria_model_id` (`ria_model_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
		$this->db->query("CREATE TABLE IF NOT EXISTS `{$p}vm_ria_mod` (
			`mod_id` int(11) NOT NULL, `gen_id` int(11) NOT NULL, `name` varchar(128) NOT NULL,
			PRIMARY KEY (`mod_id`), KEY `gen_id` (`gen_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	}

	// ------------------------------------------------------------ name keys (same rules as in the theme)

	public static function nameKey($s) {
		$s = mb_strtolower(trim((string)$s), 'UTF-8');
		$s = strtr($s, array('š' => 's', 'ë' => 'e', 'é' => 'e', 'è' => 'e', 'ö' => 'o', 'ü' => 'u', 'ä' => 'a', 'ç' => 'c', 'ã' => 'a', 'á' => 'a', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ø' => 'o', 'å' => 'a', 'ž' => 'z', 'č' => 'c', 'ř' => 'r'));

		return preg_replace('/[^a-z0-9а-яіїєґ]+/u', '', $s);
	}

	// "C-Class" / "C-KLASSE (W204)" -> "c"; "3 Series" / "3 (E90)" -> "3"; "Golf IV Variant (1J5)" -> "golf"
	public static function familyKey($name) {
		$base = trim(preg_replace('/\(.*$/u', '', (string)$name));
		$tokens = preg_split('/\s+/u', $base);
		$out = array();
		$body = '/^(variant|avant|turnier|kombi|combi|estate|wagon|touring|sportback|sportwagen|sw|break|bus|van|kasten|box|platform|pickup|pick-up|hatchback|sedan|saloon|liftback|coupe|coupé|cabrio|cabriolet|convertible|roadster|limousine|tourer|grandtour|universal|shooting|allroad|cross|plus|classic)$/iu';

		foreach ($tokens as $i => $tk) {
			if ($tk === '') {
				continue;
			}

			if ($i > 0 && (preg_match('/^(?=[MDCLXVI])M*(C[MD]|D?C{0,3})(X[CL]|L?X{0,3})(I[XV]|V?I{0,3})$/i', $tk) || preg_match('/^[A-Z]{0,2}\d/u', $tk) || preg_match($body, $tk))) {
				break;
			}

			$out[] = $tk;

			if (count($out) >= 3) {
				break;
			}
		}

		$fam = $out ? implode(' ', $out) : $base;
		$fam = preg_replace('/[\s\-]*(class|klasse|series|serie|серія|клас)\b/iu', '', $fam);

		return self::nameKey($fam);
	}

	// ------------------------------------------------------------ API

	private function key() {
		return trim((string)$this->config->get('module_vm_catalog_ria_key'));
	}

	// free package of developers.ria.com: 30 requests per hour, 1000 in total; stay under it
	const PER_HOUR = 25;

	private $budget = null;

	private function budgetLeft(&$job) {
		$hour = (int)floor(time() / 3600);

		if (empty($job['hour']) || (int)$job['hour'] !== $hour) {
			$job['hour'] = $hour;
			$job['hour_n'] = 0;
		}

		return self::PER_HOUR - (int)$job['hour_n'];
	}

	public function get($path) {
		$this->error = '';
		$key = $this->key();

		if ($key === '') {
			$this->error = 'Не вказано API-ключ AUTO.RIA';

			return null;
		}

		$ch = curl_init(self::API . ltrim($path, '/') . (strpos($path, '?') === false ? '?' : '&') . 'api_key=' . rawurlencode($key));
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 20,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
			CURLOPT_HTTPHEADER     => array('Accept: application/json')
		));
		$res = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err = curl_error($ch);
		curl_close($ch);

		if ($res === false || $code === 0) {
			$this->error = 'Немає з\'єднання з developers.ria.com: ' . $err;

			return null;
		}

		if ($code === 403 || $code === 401) {
			$this->error = 'AUTO.RIA відхилив ключ (HTTP ' . $code . '). Перевірте ключ і дозволи в кабінеті developers.ria.com';

			return null;
		}

		if ($code === 429) {
			$this->error = 'AUTO.RIA: перевищено ліміт запитів, продовжимо пізніше';

			return null;
		}

		if ($code !== 200) {
			$this->error = 'AUTO.RIA: HTTP ' . $code;

			return null;
		}

		$j = json_decode($res, true);

		if (!is_array($j)) {
			$this->error = 'AUTO.RIA: незрозуміла відповідь';

			return null;
		}

		return $j;
	}

	// ------------------------------------------------------------ job

	public function getJob() {
		$j = is_file(self::jobFile()) ? json_decode((string)file_get_contents(self::jobFile()), true) : null;

		return is_array($j) ? $j : null;
	}

	private function saveJob($j) {
		if (!is_dir(self::dir())) {
			@mkdir(self::dir(), 0755, true);
		}

		@file_put_contents(self::jobFile(), json_encode($j, JSON_UNESCAPED_UNICODE), LOCK_EX);
	}

	public function start() {
		$this->install();
		$old = $this->getJob();
		$job = array('stage' => 'makes', 'queue' => array(), 'requests' => 0, 'started' => time(), 'finished' => 0, 'error' => '', 'pause' => 0, 'hour' => $old && isset($old['hour']) ? $old['hour'] : 0, 'hour_n' => $old && isset($old['hour_n']) ? $old['hour_n'] : 0, 'waiting' => 0);
		$this->saveJob($job);

		return $job;
	}

	// due when never imported or the last full import is older than 30 days
	public function due() {
		if ($this->key() === '') {
			return false;
		}

		$j = $this->getJob();

		if (!$j) {
			return true;
		}

		if ($j['stage'] !== 'done') {
			return (empty($j['pause']) || $j['pause'] < time()) && (empty($j['waiting']) || (int)$j['hour'] !== (int)floor(time() / 3600));
		}

		return $j['finished'] < time() - 180 * 86400;
	}

	public function step($seconds = 20) {
		$this->install();
		$job = $this->getJob();

		if (!$job || $job['stage'] === 'done') {
			$job = $this->start();
		}

		if (!empty($job['pause']) && $job['pause'] > time()) {
			return $job + array('paused' => 1);
		}

		$job['pause'] = 0;
		$job['error'] = '';
		$deadline = microtime(true) + max(3, $seconds);
		$p = DB_PREFIX;

		while (microtime(true) < $deadline && $job['stage'] !== 'done') {
			if ($job['stage'] !== 'done' && $this->budgetLeft($job) <= 0) {
				$job['waiting'] = 1; // hourly limit used up: the next cron run in the next hour continues
				break;
			}

			$job['waiting'] = 0;

			if ($job['stage'] === 'makes') {
				$list = $this->get('auto/categories/1/marks');
				$job['requests']++;
				$job['hour_n']++;

				if ($list === null) {
					return $this->fail($job);
				}

				$this->saveMakes($list);
				// only makes we actually have parts for
				$job['queue'] = array_map('intval', array_column($this->db->query("SELECT DISTINCT rm.ria_id FROM `{$p}vm_ria_make` rm
					INNER JOIN `{$p}vm_model` md ON (md.make_id = rm.make_id) INNER JOIN `{$p}vm_product_vehicle` pv ON (pv.model_id = md.model_id)
					WHERE rm.make_id > 0 ORDER BY rm.ria_id")->rows, 'ria_id'));
				$job['stage'] = 'models';
				continue;
			}

			if ($job['stage'] === 'models') {
				if (!$job['queue']) {
					$job['queue'] = $this->modelsNeedingGens();
					$job['stage'] = 'gens';
					continue;
				}

				$mk = (int)$job['queue'][0];
				$list = $this->get('auto/categories/1/marks/' . $mk . '/models');
				$job['requests']++;
				$job['hour_n']++;

				if ($list === null) {
					return $this->fail($job);
				}

				foreach ($list as $m) {
					if (!isset($m['value'], $m['name'])) {
						continue;
					}

					$this->db->query("INSERT INTO `{$p}vm_ria_model` SET ria_id = '" . (int)$m['value'] . "', ria_make_id = '" . $mk . "', name = '" . $this->db->escape(mb_substr(trim($m['name']), 0, 96)) . "', fkey = '" . $this->db->escape(mb_substr(self::familyKey($m['name']), 0, 64)) . "'
						ON DUPLICATE KEY UPDATE ria_make_id = VALUES(ria_make_id), name = VALUES(name), fkey = VALUES(fkey)");
				}

				array_shift($job['queue']);
				usleep(250000);
				continue;
			}

			if ($job['stage'] === 'gens') {
				if (!$job['queue']) {
					$job['stage'] = 'done';
					$job['finished'] = time();
					break;
				}

				$md = (int)$job['queue'][0];
				$list = $this->get('generations/by/models/' . $md . '/generations');
				$job['requests']++;
				$job['hour_n']++;

				if ($list === null) {
					return $this->fail($job);
				}

				foreach ($list as $block) {
					foreach ((isset($block['generations']) && is_array($block['generations'])) ? $block['generations'] : array() as $g) {
						if (empty($g['generationId'])) {
							continue;
						}

						$this->db->query("INSERT INTO `{$p}vm_ria_gen` SET gen_id = '" . (int)$g['generationId'] . "', ria_model_id = '" . $md . "', name = '" . $this->db->escape(mb_substr(trim((string)(isset($g['name']) ? $g['name'] : '')), 0, 128)) . "',
							year_from = '" . (int)(isset($g['yearFrom']) ? $g['yearFrom'] : 0) . "', year_to = '" . (int)(isset($g['yearTo']) ? $g['yearTo'] : 0) . "'
							ON DUPLICATE KEY UPDATE ria_model_id = VALUES(ria_model_id), name = VALUES(name), year_from = VALUES(year_from), year_to = VALUES(year_to)");
					}
				}

				$this->db->query("UPDATE `{$p}vm_ria_model` SET gens_at = '" . time() . "' WHERE ria_id = '" . $md . "'");
				array_shift($job['queue']);
				usleep(250000);
			}
		}

		$this->saveJob($job);

		return $job;
	}

	private function fail($job) {
		$job['error'] = date('d.m H:i') . ' ' . $this->error;

		// a refused key does not get better by retrying every minute
		$job['pause'] = strpos($this->error, 'ліміт') !== false ? (int)(floor(time() / 3600) + 1) * 3600 + 120 : time() + 6 * 3600;
		$this->saveJob($job);

		return $job;
	}

	// AUTO.RIA makes -> our makes (by name and the aliases kept in vm_make, e.g. "ВАЗ / Lada" -> Lada)
	private function saveMakes($list) {
		$p = DB_PREFIX;
		$ours = array();

		foreach ($this->db->query("SELECT make_id, name, aliases FROM `{$p}vm_make`")->rows as $m) {
			foreach (array_merge(array($m['name']), explode(',', (string)$m['aliases'])) as $n) {
				$k = self::nameKey($n);

				if ($k !== '' && !isset($ours[$k])) {
					$ours[$k] = (int)$m['make_id'];
				}
			}
		}

		$extra = array('mercedesbenz' => 'mercedes', 'вазlada' => 'lada', 'заз' => 'zaz', 'газ' => 'gaz', 'уаз' => 'uaz');

		foreach ($list as $m) {
			if (!isset($m['value'], $m['name'])) {
				continue;
			}

			$make_id = 0;
			$names = array_merge(array($m['name']), preg_split('/\s*\/\s*/u', $m['name']));

			foreach ($names as $n) {
				$k = self::nameKey($n);

				if (isset($ours[$k])) {
					$make_id = $ours[$k];
					break;
				}

				if (isset($extra[$k], $ours[$extra[$k]])) {
					$make_id = $ours[$extra[$k]];
					break;
				}
			}

			$this->db->query("INSERT INTO `{$p}vm_ria_make` SET ria_id = '" . (int)$m['value'] . "', name = '" . $this->db->escape(mb_substr(trim($m['name']), 0, 64)) . "', make_id = '" . (int)$make_id . "'
				ON DUPLICATE KEY UPDATE name = VALUES(name), make_id = VALUES(make_id)");
		}
	}

	// models of our makes whose family appears in the supplier fitment data
	private function modelsNeedingGens() {
		$p = DB_PREFIX;
		$families = array();

		foreach ($this->db->query("SELECT DISTINCT md.make_id, md.name FROM `{$p}vm_model` md INNER JOIN `{$p}vm_product_vehicle` pv ON (pv.model_id = md.model_id)")->rows as $r) {
			$families[(int)$r['make_id'] . ':' . self::familyKey($r['name'])] = true;
		}

		$ids = array();

		foreach ($this->db->query("SELECT rm.ria_id, rm.fkey, mk.make_id FROM `{$p}vm_ria_model` rm INNER JOIN `{$p}vm_ria_make` mk ON (mk.ria_id = rm.ria_make_id AND mk.make_id > 0)")->rows as $r) {
			if (isset($families[(int)$r['make_id'] . ':' . $r['fkey']])) {
				$ids[] = (int)$r['ria_id'];
			}
		}

		return $ids;
	}

	public function stats() {
		$p = DB_PREFIX;
		$this->install();
		$job = $this->getJob();

		return array(
			'makes'   => (int)$this->db->query("SELECT COUNT(*) AS n FROM `{$p}vm_ria_make` WHERE make_id > 0")->row['n'],
			'models'  => (int)$this->db->query("SELECT COUNT(*) AS n FROM `{$p}vm_ria_model` WHERE gens_at > 0")->row['n'],
			'gens'    => (int)$this->db->query("SELECT COUNT(*) AS n FROM `{$p}vm_ria_gen`")->row['n'],
			'mods'    => (int)$this->db->query("SELECT COUNT(*) AS n FROM `{$p}vm_ria_mod`")->row['n'],
			'stage'   => $job ? $job['stage'] : '',
			'left'    => $job ? count($job['queue']) : 0,
			'finished'=> $job && !empty($job['finished']) ? date('d.m.Y H:i', $job['finished']) : '',
			'error'   => $job ? (string)$job['error'] : '',
			'has_key' => $this->key() !== '',
			'waiting' => $job && !empty($job['waiting']),
			'requests'=> $job ? (int)$job['requests'] : 0
		);
	}
}
