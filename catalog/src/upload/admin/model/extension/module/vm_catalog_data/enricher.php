<?php
/**
 * V&M Catalog — enrichment of products with BM Parts data:
 * vehicle applicability, OE numbers, analogs, photos.
 * Used by the admin page (manual run) and by the storefront cron URL.
 */
class VmCatalogEnricher {
	const BM_API = 'https://api.bm.parts/';
	const CDN = 'https://cdn.bm.parts/';

	private $db;
	private $config;
	private $requests = 0;
	private $makes = null;
	private $models = array();
	public $log = array();

	public function __construct($registry) {
		$this->db = $registry->get('db');
		$this->config = $registry->get('config');
	}

	// ------------------------------------------------------------ schema

	public function install() {
		$p = DB_PREFIX;

		// same tables as the car filter module: the theme already reads them
		$this->db->query("CREATE TABLE IF NOT EXISTS `{$p}vm_make` (
			`make_id` INT(11) NOT NULL AUTO_INCREMENT, `name` VARCHAR(64) NOT NULL, `aliases` TEXT NOT NULL,
			`image` VARCHAR(255) NOT NULL DEFAULT '', `sort_order` INT(3) NOT NULL DEFAULT 0, `status` TINYINT(1) NOT NULL DEFAULT 1,
			PRIMARY KEY (`make_id`), UNIQUE KEY `name` (`name`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
		$this->db->query("CREATE TABLE IF NOT EXISTS `{$p}vm_model` (
			`model_id` INT(11) NOT NULL AUTO_INCREMENT, `make_id` INT(11) NOT NULL, `name` VARCHAR(64) NOT NULL, `aliases` TEXT NOT NULL,
			`sort_order` INT(3) NOT NULL DEFAULT 0, PRIMARY KEY (`model_id`), UNIQUE KEY `make_model` (`make_id`, `name`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
		$this->db->query("CREATE TABLE IF NOT EXISTS `{$p}vm_product_vehicle` (
			`product_id` INT(11) NOT NULL, `model_id` INT(11) NOT NULL, `year_from` SMALLINT(4) NOT NULL DEFAULT 0, `year_to` SMALLINT(4) NOT NULL DEFAULT 0,
			`source` VARCHAR(8) NOT NULL DEFAULT 'auto', PRIMARY KEY (`product_id`, `model_id`), KEY `model_id` (`model_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

		// engines per supplier model ("1.4 TSI 140 л.с." 2012-now) and which engines a part fits (1.8.1)
		$this->db->query("CREATE TABLE IF NOT EXISTS `{$p}vm_engine` (
			`engine_id` INT(11) NOT NULL AUTO_INCREMENT, `model_id` INT(11) NOT NULL, `name` VARCHAR(128) NOT NULL,
			`year_from` SMALLINT(4) NOT NULL DEFAULT 0, `year_to` SMALLINT(4) NOT NULL DEFAULT 0,
			PRIMARY KEY (`engine_id`), UNIQUE KEY `model_name` (`model_id`, `name`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
		$this->db->query("CREATE TABLE IF NOT EXISTS `{$p}vm_product_engine` (
			`product_id` INT(11) NOT NULL, `engine_id` INT(11) NOT NULL,
			PRIMARY KEY (`product_id`, `engine_id`), KEY `engine_id` (`engine_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		$this->db->query("CREATE TABLE IF NOT EXISTS `{$p}vm_catalog_enrich` (
			`product_id` INT(11) NOT NULL, `source` VARCHAR(16) NOT NULL DEFAULT 'bm', `ext_id` VARCHAR(64) NOT NULL DEFAULT '',
			`status` TINYINT(1) NOT NULL DEFAULT 0, `cars` INT(11) NOT NULL DEFAULT 0, `oe` INT(11) NOT NULL DEFAULT 0, `analogs` INT(11) NOT NULL DEFAULT 0,
			`images` INT(11) NOT NULL DEFAULT 0, `node` VARCHAR(255) NOT NULL DEFAULT '', `cars_json` MEDIUMTEXT NOT NULL, `date_checked` DATETIME NOT NULL,
			PRIMARY KEY (`product_id`), KEY `status` (`status`, `date_checked`)) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4");
		$cols = array();

		foreach ($this->db->query("SHOW COLUMNS FROM `{$p}vm_catalog_enrich`")->rows as $c) {
			$cols[] = $c['Field'];
		}

		if (!in_array('extra_json', $cols)) {
			$this->db->query("ALTER TABLE `{$p}vm_catalog_enrich` ADD `extra_json` MEDIUMTEXT NOT NULL AFTER `cars_json`");
		}

		$this->db->query("CREATE TABLE IF NOT EXISTS `{$p}vm_catalog_oe` (
			`product_id` INT(11) NOT NULL, `oe_key` VARCHAR(64) NOT NULL, `oe` VARCHAR(64) NOT NULL,
			PRIMARY KEY (`product_id`, `oe_key`), KEY `oe_key` (`oe_key`)) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4");
		$this->db->query("CREATE TABLE IF NOT EXISTS `{$p}vm_catalog_analog` (
			`product_id` INT(11) NOT NULL, `art_key` VARCHAR(64) NOT NULL, `brand_key` VARCHAR(64) NOT NULL, `article` VARCHAR(64) NOT NULL, `brand` VARCHAR(64) NOT NULL,
			PRIMARY KEY (`product_id`, `art_key`, `brand_key`), KEY `art_key` (`art_key`)) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4");
	}

	// ------------------------------------------------------------ helpers

	public static function key($s) {
		return mb_substr(mb_strtoupper(preg_replace('/[^\p{L}\p{N}]+/u', '', (string)$s), 'UTF-8'), 0, 64, 'UTF-8');
	}

	private static $brandAlias = array(
		'FEBIBILSTEIN' => 'FEBI', 'MANNFILTER' => 'MANN', 'MAHLEKNECHT' => 'KNECHT', 'KNECHTMAHLE' => 'KNECHT', 'MAHLEFILTER' => 'KNECHT',
		'NTNSNR' => 'SNR', 'HELLAPAGID' => 'PAGID', 'TRWAUTOMOTIVE' => 'TRW', 'ZFSACHS' => 'SACHS', 'SACHSZF' => 'SACHS', 'VICTORREINZ' => 'REINZ',
		'CONTITECH' => 'CONTINENTAL', 'CONTINENTALCTAM' => 'CONTINENTAL', 'BOSCHSERVICE' => 'BOSCH', 'SKFVKBA' => 'SKF', 'INASCHAEFFLER' => 'INA',
		'LUKSCHAEFFLER' => 'LUK', 'MAGNETIMARELLI' => 'MARELLI', 'KYBKAYABA' => 'KYB', 'KAYABA' => 'KYB', 'SWAGFEBI' => 'SWAG', 'TOPRANHANSPRIES' => 'TOPRAN',
		'HANSPRIES' => 'TOPRAN', 'MOOGHD' => 'MOOG'
	);

	public static function brandKey($b) {
		$k = self::key($b);

		return isset(self::$brandAlias[$k]) ? self::$brandAlias[$k] : $k;
	}

	public static function sameBrand($a, $b) {
		$a = self::brandKey($a);
		$b = self::brandKey($b);

		if ($a === '' || $b === '') {
			return false;
		}

		if ($a === $b) {
			return true;
		}

		// "BLUE PRINT" vs "BLUEPRINT ADL", "LEMFORDER" vs "LEMFOERDER" are rare; prefix rule covers "SATO" vs "SATOTECH"
		return (strlen($a) >= 3 && strpos($b, $a) === 0) || (strlen($b) >= 3 && strpos($a, $b) === 0);
	}

	private function setting($key, $default = '') {
		$v = $this->config->get('module_vm_catalog_' . $key);

		return ($v === null || $v === '') ? $default : $v;
	}

	private function saveSetting($key, $value) {
		$k = 'module_vm_catalog_' . $key;
		$this->db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE store_id = 0 AND `code` = 'module_vm_catalog' AND `key` = '" . $this->db->escape($k) . "'");
		$this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET store_id = 0, `code` = 'module_vm_catalog', `key` = '" . $this->db->escape($k) . "', `value` = '" . $this->db->escape($value) . "', serialized = 0");
		$this->config->set($k, $value);
	}

	// ------------------------------------------------------------ HTTP

	const HOURLY = 9500; // BM Parts allows 10 000 requests per hour per token

	// shared by the admin page and cron: requests in the current hour
	private function rate($add = 0) {
		$file = DIR_CACHE . 'vm_catalog_bm_rate.json';
		$hour = date('YmdH');
		$d = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;

		if (!$d || $d['hour'] !== $hour) {
			$d = array('hour' => $hour, 'count' => 0);
		}

		if ($add) {
			$d['count'] += $add;
			@file_put_contents($file, json_encode($d), LOCK_EX);
		}

		return $d['count'];
	}

	public function bm($method, $path, $query = array()) {
		if ($this->rate() >= self::HOURLY) {
			return array('code' => 429, 'json' => null, 'error' => 'hourly limit');
		}

		$token = trim((string)$this->setting('bm_token'));

		if ($token === '') {
			return array('code' => 0, 'json' => null, 'error' => 'Не вказано API-токен BM Parts');
		}

		$url = self::BM_API . ltrim($path, '/');

		if ($query) {
			$parts = array();

			foreach ($query as $k => $v) {
				foreach ((array)$v as $one) {
					$parts[] = rawurlencode($k) . '=' . rawurlencode($one);
				}
			}

			$url .= '?' . implode('&', $parts);
		}

		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_TIMEOUT        => 45,
			CURLOPT_USERAGENT      => 'VM-Catalog vmautoparts.com.ua',
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_HTTPHEADER     => array('Accept: application/json', 'Authorization: ' . $token, 'Accept-Language: uk')
		));
		$res = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err = curl_error($ch);
		curl_close($ch);
		$this->requests++;
		$this->rate(1);

		if ($res === false) {
			return array('code' => 0, 'json' => null, 'error' => $err);
		}

		return array('code' => $code, 'json' => json_decode($res, true));
	}

	public $imgError = '';

	private function download($url, $file) {
		$tmpf = $file . '.' . getmypid() . '-' . mt_rand(1000, 9999) . '.tmp';
		$fh = @fopen($tmpf, 'w');

		if (!$fh) {
			$this->imgError = 'немає прав на запис у ' . dirname($file);

			return false;
		}

		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_FILE           => $fh,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
			CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
			CURLOPT_MAXREDIRS      => 3,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_SSL_VERIFYPEER => true,
			// the photo CDN refuses requests that do not look like a browser
			CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36',
			CURLOPT_HTTPHEADER     => array('Accept: image/avif,image/webp,image/*,*/*;q=0.8', 'Referer: https://b2b.bm.parts/')
		));
		curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
		$err = curl_error($ch);
		curl_close($ch);
		fclose($fh);

		$size = (int)@filesize($tmpf);

		if ($code == 200 && $size > 500 && @getimagesize($tmpf)) {
			$this->shrink($tmpf);
			if (!@rename($tmpf, $file)) {
				@unlink($tmpf);
			}
			$this->imgError = '';

			return true;
		}

		$this->imgError = $url . ' → ' . ($err ? $err : 'HTTP ' . $code . ', ' . ($type ?: 'без типу') . ', ' . $size . ' байт');
		@unlink($tmpf);

		return false;
	}

	// big supplier photos -> max 800 px, JPEG quality 82 (keeps the hosting disk small)
	private function shrink($file) {
		if (!function_exists('imagecreatefromstring') || !is_file($file) || @filesize($file) < 70000) {
			return;
		}

		$info = @getimagesize($file);

		if (!$info || $info[0] * $info[1] > 25000000) {
			return;
		}

		$src = @imagecreatefromstring(file_get_contents($file));

		if (!$src) {
			return;
		}

		$w = imagesx($src);
		$h = imagesy($src);
		$k = min(1, 800 / max($w, $h));
		$nw = max(1, (int)round($w * $k));
		$nh = max(1, (int)round($h * $k));
		$dst = imagecreatetruecolor($nw, $nh);
		imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // transparent PNG -> white
		imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
		$tmp = $file . '.s.jpg';

		if (@imagejpeg($dst, $tmp, 82) && is_file($file) && @filesize($tmp) < @filesize($file)) {
			@rename($tmp, $file);
		} else {
			@unlink($tmp);
		}

		imagedestroy($src);
		imagedestroy($dst);
	}

	// ------------------------------------------------------------ finding the product in BM

	private function productsOf($json) {
		if (!is_array($json) || empty($json['products']) || !is_array($json['products'])) {
			return array();
		}

		$out = array();

		foreach ($json['products'] as $k => $p) {
			if (is_array($p)) {
				if (empty($p['uuid']) && is_string($k)) {
					$p['uuid'] = $k;
				}

				$out[] = $p;
			}
		}

		return $out;
	}

	public function findBm($article, $brand) {
		$r = $this->bm('GET', 'search/products', array('q' => $article, 'search_mode' => 'extended', 'products_as' => 'obj', 'per_page' => 30));

		if ($r['code'] != 200) {
			return array('error' => $r['code'] ? 'HTTP ' . $r['code'] : (isset($r['error']) ? $r['error'] : 'network'), 'code' => $r['code']);
		}

		$ak = self::key($article);

		foreach ($this->productsOf($r['json']) as $p) {
			if (isset($p['article'], $p['brand']) && self::key($p['article']) === $ak && self::sameBrand($p['brand'], $brand)) {
				return array('uuid' => $p['uuid']);
			}
		}

		return array('uuid' => '');
	}

	// ------------------------------------------------------------ saving

	private function makeId($name) {
		$name = trim($name);

		if ($name === '') {
			return 0;
		}

		if ($this->makes === null) {
			$this->makes = array();

			foreach ($this->db->query("SELECT make_id, name, aliases FROM `" . DB_PREFIX . "vm_make`")->rows as $m) {
				$this->makes[self::key($m['name'])] = (int)$m['make_id'];

				foreach (explode(',', (string)$m['aliases']) as $a) {
					if (trim($a) !== '' && !isset($this->makes[self::key($a)])) {
						$this->makes[self::key($a)] = (int)$m['make_id'];
					}
				}
			}
		}

		$k = self::key($name);

		if (isset($this->makes[$k])) {
			return $this->makes[$k];
		}

		$nice = strlen($name) <= 3 ? strtoupper($name) : mb_convert_case(mb_strtolower($name, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
		$this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . "vm_make` SET name = '" . $this->db->escape(mb_substr($nice, 0, 64)) . "', aliases = '" . $this->db->escape($name) . "', sort_order = 0, status = 1");
		$id = (int)$this->db->getLastId();

		if (!$id) {
			$id = (int)$this->db->query("SELECT make_id FROM `" . DB_PREFIX . "vm_make` WHERE name = '" . $this->db->escape(mb_substr($nice, 0, 64)) . "'")->row['make_id'];
		}

		return $this->makes[$k] = $id;
	}

	private function modelId($make_id, $name) {
		$name = trim(preg_replace('/\s+/u', ' ', $name));
		$k = $make_id . ':' . self::key($name);

		if ($name === '' || !$make_id) {
			return 0;
		}

		if (isset($this->models[$k])) {
			return $this->models[$k];
		}

		$q = $this->db->query("SELECT model_id FROM `" . DB_PREFIX . "vm_model` WHERE make_id = '" . (int)$make_id . "' AND name = '" . $this->db->escape(mb_substr($name, 0, 64)) . "'");

		if ($q->num_rows) {
			return $this->models[$k] = (int)$q->row['model_id'];
		}

		$this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . "vm_model` SET make_id = '" . (int)$make_id . "', name = '" . $this->db->escape(mb_substr($name, 0, 64)) . "', aliases = '', sort_order = 0");

		return $this->models[$k] = (int)$this->db->getLastId();
	}

	private static function str($v) {
		if (is_array($v)) {
			foreach (array('name', 'title', 'model', 'value') as $k) {
				if (isset($v[$k]) && is_string($v[$k])) {
					return $v[$k];
				}
			}

			return '';
		}

		return (string)$v;
	}

	// "6 (GJ, GL) 2012-" / "A4 (B8) 11.2007-12.2015" / ['name'=>..., 'year_from'=>...]
	public static function splitYears($model, $raw = array()) {
		$from = $to = 0;

		foreach (array('year_from', 'yearFrom', 'from', 'start_year') as $k) {
			if (!empty($raw[$k])) {
				$from = (int)substr((string)$raw[$k], 0, 4);
			}
		}

		foreach (array('year_to', 'yearTo', 'to', 'end_year') as $k) {
			if (!empty($raw[$k])) {
				$to = (int)substr((string)$raw[$k], 0, 4);
			}
		}

		if (preg_match('/(?:\d{1,2}[.\/])?((?:19|20)\d{2})\s*[-–—]\s*(?:(?:\d{1,2}[.\/])?((?:19|20)\d{2}))?/u', $model, $m)) {
			$from = $from ?: (int)$m[1];
			$to = $to ?: (isset($m[2]) ? (int)$m[2] : 0);
			$model = trim(str_replace($m[0], '', $model), " \t-–,");
		}

		return array(trim(preg_replace('/\s+/u', ' ', $model)), $from, $to);
	}

	// "1.4 TSI 140 л.с. (2012-н.в.)" -> array("1.4 TSI 140 к.с.", 2012, 0); a plain value without years -> years 0
	public static function parseEngine($e) {
		if (is_array($e) && isset($e['engine']) && is_scalar($e['engine']) && trim((string)$e['engine']) !== '') {
			// BM: {"engine":"1.4 55 л.с. (1993-1999)","begin_release":"1993-07-01","end_release":"1999-04-01","horse_power":55,...}
			$txt = trim((string)$e['engine']);

			if (!preg_match('/\(\s*(?:19|20)\d{2}/', $txt)) {
				$b = isset($e['begin_release']) ? (int)substr((string)$e['begin_release'], 0, 4) : 0;
				$en = isset($e['end_release']) ? (int)substr((string)$e['end_release'], 0, 4) : 0;

				if ($b >= 1900) {
					$txt .= ' (' . $b . '-' . ($en >= 1900 ? $en : 'н.в.') . ')';
				}
			}

			$e = $txt;
		} elseif (is_array($e)) {
			// {"name":"2.0 SKYACTIV-G","power":"165 л.с.","years":"2012-2018"}
			$name = self::str($e);
			$power = isset($e['power']) && is_scalar($e['power']) ? trim((string)$e['power']) : '';
			$years = '';

			foreach (array('years', 'year', 'period') as $k) {
				if (isset($e[$k]) && is_scalar($e[$k]) && trim((string)$e[$k]) !== '') {
					$years = trim((string)$e[$k]);
					break;
				}
			}

			$e = $name . ($power !== '' && strpos($name, $power) === false ? ' ' . $power : '') . ($years !== '' ? ' (' . $years . ')' : '');
		}

		$e = trim(preg_replace('/\s+/u', ' ', self::str($e)));

		if ($e === '') {
			return null;
		}

		$from = $to = 0;

		if (preg_match('/\(\s*(?:\d{1,2}[.\/])?((?:19|20)\d{2})\s*[-–—]\s*(?:(?:\d{1,2}[.\/])?((?:19|20)\d{2})|н\.?\s*в\.?|now|наст\.?\s*вр\.?)?\s*\)\s*$/u', $e, $m)) {
			$from = (int)$m[1];
			$to = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 0;
			$e = trim(str_replace($m[0], '', $e));
		}

		$e = preg_replace('/(?<!\p{L})л\.\s*с\.?/u', 'к.с.', $e);

		return array(mb_substr($e, 0, 128), $from, $to);
	}

	private function engineId($model_id, $name, $from, $to) {
		$k = $model_id . ':' . mb_strtolower($name, 'UTF-8');

		if (isset($this->engines[$k])) {
			return $this->engines[$k];
		}

		$this->db->query("INSERT INTO `" . DB_PREFIX . "vm_engine` SET model_id = '" . (int)$model_id . "', name = '" . $this->db->escape($name) . "', year_from = '" . (int)$from . "', year_to = '" . (int)$to . "'
			ON DUPLICATE KEY UPDATE engine_id = LAST_INSERT_ID(engine_id), year_to = IF(year_from = 0, VALUES(year_to), year_to), year_from = IF(year_from = 0, VALUES(year_from), year_from)");

		return $this->engines[$k] = (int)$this->db->getLastId();
	}

	private $engines = array();

	const MAX_ENGINE_LINKS = 1500; // universal parts (bulbs, wipers) list thousands of engines: keep it bounded

	private function saveCars($product_id, $cars) {
		$p = DB_PREFIX;
		$product_id = (int)$product_id;
		$this->db->query("DELETE FROM `{$p}vm_product_vehicle` WHERE product_id = '" . $product_id . "' AND source = 'bm'");
		$this->db->query("DELETE FROM `{$p}vm_product_engine` WHERE product_id = '" . $product_id . "'");

		$pv = array();   // model_id => array(yf, yt)
		$links = array();

		foreach ((array)$cars as $car) {
			if (!is_array($car)) {
				continue;
			}

			$make = self::str(isset($car['brand']) ? $car['brand'] : (isset($car['name']) ? $car['name'] : ''));
			$make_id = $this->makeId($make);

			if (!$make_id) {
				continue;
			}

			foreach ((isset($car['models']) && is_array($car['models']) ? $car['models'] : array()) as $m) {
				$raw = is_array($m) ? $m : array();
				$name = self::str(isset($raw['model']) ? $raw['model'] : $m);
				list($name, $yf, $yt) = self::splitYears($name, isset($raw['model']) && is_array($raw['model']) ? $raw['model'] : $raw);
				$engines = array();

				foreach ((isset($raw['engines']) && is_array($raw['engines']) ? $raw['engines'] : array()) as $e) {
					$pe = self::parseEngine($e);

					if ($pe && $pe[0] !== '') {
						$engines[] = $pe;
					}
				}

				if (!$yf && $engines) {
					$open = false;

					foreach ($engines as $pe) {
						if ($pe[1]) {
							$yf = $yf ? min($yf, $pe[1]) : $pe[1];
							$open = $open || !$pe[2];
							$yt = max($yt, $pe[2]);
						}
					}

					if ($open) {
						$yt = 0;
					}
				}

				$model_id = $this->modelId($make_id, $name);

				if (!$model_id) {
					continue;
				}

				$pv[$model_id] = array((int)$yf, (int)$yt);

				foreach ($engines as $pe) {
					if (count($links) >= self::MAX_ENGINE_LINKS) {
						break;
					}

					$eid = $this->engineId($model_id, $pe[0], $pe[1], $pe[2]);

					if ($eid) {
						$links[$eid] = true;
					}
				}
			}
		}

		// one statement per 400 rows instead of one per row
		foreach (array_chunk($pv, 400, true) as $chunk) {
			$vals = array();

			foreach ($chunk as $model_id => $y) {
				$vals[] = "('" . $product_id . "','" . (int)$model_id . "','" . $y[0] . "','" . $y[1] . "','bm')";
			}

			$this->db->query("INSERT INTO `{$p}vm_product_vehicle` (product_id, model_id, year_from, year_to, source) VALUES " . implode(',', $vals) . "
				ON DUPLICATE KEY UPDATE year_to = IF(source = 'bm' OR year_from = 0, VALUES(year_to), year_to), year_from = IF(source = 'bm' OR year_from = 0, VALUES(year_from), year_from)");
		}

		foreach (array_chunk(array_keys($links), 500) as $chunk) {
			$this->db->query("INSERT IGNORE INTO `{$p}vm_product_engine` (product_id, engine_id) VALUES ('" . $product_id . "','" . implode("'),('" . $product_id . "','", array_map('intval', $chunk)) . "')");
		}

		if (count($this->engines) > 50000) {
			$this->engines = array(); // keep memory bounded on long runs
		}

		return count($pv);
	}

	// re-read the supplier fitment already stored for enriched products (no API calls): years from engines,
	// engine links. Resumable; position kept in a small file.
	public function rebuildCars($seconds = 20) {
		$p = DB_PREFIX;
		$file = DIR_STORAGE . 'upload/vm_catalog/cars_rebuild.json';
		$st = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
		// v2: BM engines come as objects {"engine": "..."}; earlier passes read none of them, so run again
		if (!is_array($st) || (int)(isset($st['v']) ? $st['v'] : 1) < 2) {
			$st = array('pos' => 0, 'done' => 0, 'finished' => 0, 'v' => 2);
		}

		if (!empty($st['finished'])) {
			return $st;
		}

		if ($seconds <= 0) {
			return $st; // status only
		}

		// never two rebuilds at once (cron + admin button)
		$lock = @fopen(DIR_STORAGE . 'upload/vm_catalog/cars_rebuild.lock', 'c');

		if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
			$st['busy'] = 1;

			return $st;
		}

		$deadline = microtime(true) + max(3, min(25, $seconds));

		if (empty($st['total'])) {
			$st['total'] = (int)$this->db->query("SELECT COUNT(*) AS n FROM `{$p}vm_catalog_enrich` WHERE cars > 0")->row['n'];
		}

		$total = $st['total'];

		while (microtime(true) < $deadline) {
			$rows = $this->db->query("SELECT product_id, cars_json FROM `{$p}vm_catalog_enrich` WHERE cars > 0 AND product_id > '" . (int)$st['pos'] . "' ORDER BY product_id LIMIT 20")->rows;

			if (!$rows) {
				$st['finished'] = time();
				break;
			}

			foreach ($rows as $r) {
				$cars = json_decode((string)$r['cars_json'], true);

				if (is_array($cars) && $cars) {
					$this->saveCars((int)$r['product_id'], $cars);
				}

				$st['pos'] = (int)$r['product_id'];
				$st['done']++;

				if (microtime(true) >= $deadline) {
					break 2;
				}
			}

			usleep(50000); // let other requests breathe
		}

		$st['total'] = $total;
		@file_put_contents($file, json_encode($st));
		flock($lock, LOCK_UN);
		fclose($lock);

		return $st;
	}

	private function saveOe($product_id, $oe) {
		$this->db->query("DELETE FROM `" . DB_PREFIX . "vm_catalog_oe` WHERE product_id = '" . (int)$product_id . "'");
		$n = 0;

		foreach ((array)$oe as $o) {
			$num = is_array($o) ? self::str(isset($o['number']) ? $o['number'] : (isset($o['oe']) ? $o['oe'] : (isset($o['article']) ? $o['article'] : ''))) : (string)$o;
			$k = self::key($num);

			if (strlen($k) >= 3) {
				$this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . "vm_catalog_oe` SET product_id = '" . (int)$product_id . "', oe_key = '" . $this->db->escape($k) . "', oe = '" . $this->db->escape(mb_substr($num, 0, 64)) . "'");
				$n++;
			}
		}

		return $n;
	}

	private function saveAnalogs($product_id, $analogs) {
		$this->db->query("DELETE FROM `" . DB_PREFIX . "vm_catalog_analog` WHERE product_id = '" . (int)$product_id . "'");
		$n = 0;

		foreach ((array)$analogs as $a) {
			if (!is_array($a) || empty($a['article']) || empty($a['brand'])) {
				continue;
			}

			$this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . "vm_catalog_analog` SET product_id = '" . (int)$product_id . "', art_key = '" . $this->db->escape(self::key($a['article'])) . "', brand_key = '" . $this->db->escape(self::brandKey($a['brand'])) . "', article = '" . $this->db->escape(mb_substr($a['article'], 0, 64)) . "', brand = '" . $this->db->escape(mb_substr($a['brand'], 0, 64)) . "'");
			$n++;
		}

		return $n;
	}

	public function imageUrls($path) {
		$path = str_replace('\\', '/', trim((string)$path));

		if ($path === '') {
			return array();
		}

		if (preg_match('#^https?://#i', $path)) {
			return array($path);
		}

		$path = ltrim($path, '/');
		$list = array(self::CDN . $path, self::CDN . 'photos/' . preg_replace('#^photos?/#', '', $path), self::CDN . preg_replace('#^photos?/#', '', $path));
		$known = (int)$this->setting('bm_img_pattern', -1);

		if ($known >= 0 && isset($list[$known])) {
			return array($known => $list[$known]);
		}

		return $list;
	}

	private function saveImage($product_id, $paths) {
		$paths = array_values(array_filter((array)$paths, 'is_string'));

		if (!$paths) {
			return 0;
		}

		$has = $this->db->query("SELECT image FROM `" . DB_PREFIX . "product` WHERE product_id = '" . (int)$product_id . "'")->row;

		if (!empty($has['image'])) {
			return 0;
		}

		$sub = 'catalog/bm/' . substr(md5($product_id), 0, 2) . '/';

		if (!is_dir(DIR_IMAGE . $sub)) {
			@mkdir(DIR_IMAGE . $sub, 0755, true);
		}

		if (!is_dir(DIR_IMAGE . $sub) || !is_writable(DIR_IMAGE . $sub)) {
			$this->saveSetting('bm_img_error', 'немає прав на запис у папку image/' . $sub);

			return -1;
		}

		foreach ($this->imageUrls($paths[0]) as $i => $url) {
			$ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
			$ext = function_exists('imagecreatefromstring') ? 'jpg' : (in_array($ext, array('jpg', 'jpeg', 'png', 'webp', 'gif'), true) ? $ext : 'jpg');
			$rel = $sub . (int)$product_id . '.' . $ext;

			if ($this->download($url, DIR_IMAGE . $rel)) {
				if ((int)$this->setting('bm_img_pattern', -1) !== $i) {
					$this->saveSetting('bm_img_pattern', (string)$i);
				}

				$this->db->query("UPDATE `" . DB_PREFIX . "product` SET image = '" . $this->db->escape($rel) . "' WHERE product_id = '" . (int)$product_id . "'");

				if ((string)$this->setting('bm_img_error') !== '') {
					$this->saveSetting('bm_img_error', '');
				}

				return 1;
			}
		}

		$this->saveSetting('bm_img_error', date('d.m H:i') . ' ' . $this->imgError);

		return -1;
	}

	// additional photos -> product_image (gallery), up to $max in total with the main one
	private function saveMoreImages($product_id, $paths, $max = 6) {
		$paths = array_values(array_filter((array)$paths, 'is_string'));

		if (count($paths) < 2) {
			return 0;
		}

		$have = (int)$this->db->query("SELECT COUNT(*) AS n FROM `" . DB_PREFIX . "product_image` WHERE product_id = '" . (int)$product_id . "'")->row['n'];

		if ($have > 0) {
			return 0;
		}

		$sub = 'catalog/bm/' . substr(md5($product_id), 0, 2) . '/';
		$n = 0;

		foreach (array_slice($paths, 1, $max - 1) as $k => $path) {
			$this->keepLock();

			foreach ($this->imageUrls($path) as $url) {
				$rel = $sub . (int)$product_id . '-' . ($k + 2) . '.jpg';

				if ($this->download($url, DIR_IMAGE . $rel)) {
					$this->db->query("INSERT INTO `" . DB_PREFIX . "product_image` SET product_id = '" . (int)$product_id . "', image = '" . $this->db->escape($rel) . "', sort_order = '" . ($k + 1) . "'");
					$n++;
					break;
				}
			}
		}

		return $n;
	}

	// brands BM Parts does not carry (e.g. SATO TECH): after 25 misses and no hits, skip the rest without requests
	private function skipMissingBrands() {
		$p = DB_PREFIX;
		$rows = $this->db->query("SELECT i.brand FROM `{$p}vm_catalog_enrich` e JOIN `{$p}vm_catalog_item` i ON (i.product_id = e.product_id)
			GROUP BY i.brand HAVING SUM(e.status = 1) = 0 AND SUM(e.status = 0) >= 25")->rows;
		$n = 0;

		foreach ($rows as $r) {
			$this->db->query("INSERT IGNORE INTO `{$p}vm_catalog_enrich` (product_id, source, ext_id, status, node, cars_json, extra_json, date_checked)
				SELECT DISTINCT i.product_id, 'bm', '', 0, 'brand not in BM', '', '', NOW() FROM `{$p}vm_catalog_item` i
				LEFT JOIN `{$p}vm_catalog_enrich` e ON (e.product_id = i.product_id)
				WHERE i.brand = '" . $this->db->escape($r['brand']) . "' AND e.product_id IS NULL");
			$n += $this->db->countAffected();
		}

		return $n;
	}

	// products found before 1.4.0: fetch the card again to add engines/characteristics/gallery
	private $deadline = 0;
	private $lockFile = '';

	private function timeLeft() {
		return $this->deadline - microtime(true);
	}

	private function keepLock() {
		if ($this->lockFile) {
			@touch($this->lockFile);
		}
	}

	private function refreshOld($limit, &$stats, $until = 0) {
		$q = $this->db->query("SELECT e.product_id, e.ext_id, MAX(i.local_qty) AS local_qty FROM `" . DB_PREFIX . "vm_catalog_enrich` e
			JOIN `" . DB_PREFIX . "vm_catalog_item` i ON (i.product_id = e.product_id)
			WHERE e.status = 1 AND e.ext_id <> '' AND e.extra_json = '' GROUP BY e.product_id LIMIT " . (int)$limit);

		foreach ($q->rows as $row) {
			if ($until && microtime(true) > $until) {
				return;
			}

			$this->keepLock();
			$res = $this->saveBm($row['product_id'], $row['ext_id'], (int)$row['local_qty']);

			if (isset($res['error'])) {
				if ($res['code'] == 429) {
					return;
				}

				continue;
			}

			$keep = $this->db->query("SELECT images FROM `" . DB_PREFIX . "vm_catalog_enrich` WHERE product_id = '" . (int)$row['product_id'] . "'")->row;
			$res['images'] = max((int)$res['images'], isset($keep['images']) ? (int)$keep['images'] : 0);
			$this->mark($row['product_id'], 1, $row['ext_id'], $res);
			$stats['refreshed'] = (isset($stats['refreshed']) ? $stats['refreshed'] : 0) + 1;
		}
	}

	// photos for products found earlier that still have none (e.g. after a failed download)
	private function backfillPhotos($limit, &$stats, $until = 0) {
		$mode = $this->setting('bm_photos', 'all');

		if ($mode === 'none') {
			return;
		}

		$q = $this->db->query("SELECT e.product_id, e.ext_id, MAX(i.local_qty) AS local_qty FROM `" . DB_PREFIX . "vm_catalog_enrich` e
			JOIN `" . DB_PREFIX . "product` p ON (p.product_id = e.product_id AND p.image = '')
			JOIN `" . DB_PREFIX . "vm_catalog_item` i ON (i.product_id = e.product_id)
			WHERE e.status = 1 AND e.images = 0 AND e.ext_id <> ''" . ($mode === 'local' ? " AND i.local_qty > 0" : "") . "
			GROUP BY e.product_id LIMIT " . (int)$limit);

		foreach ($q->rows as $row) {
			if ($until && microtime(true) > $until) {
				return;
			}

			$this->keepLock();
			$r = $this->bm('GET', 'product/' . rawurlencode($row['ext_id']) . '/images');

			if ($r['code'] == 429) {
				return;
			}

			$imgs = isset($r['json']['images']) ? $r['json']['images'] : array();
			$got = $imgs ? $this->saveImage($row['product_id'], $imgs) : -1;
			$this->db->query("UPDATE `" . DB_PREFIX . "vm_catalog_enrich` SET images = '" . (int)$got . "' WHERE product_id = '" . (int)$row['product_id'] . "'");

			if ($got > 0) {
				$stats['photos'] = (isset($stats['photos']) ? $stats['photos'] : 0) + 1;
			}
		}
	}

	// ------------------------------------------------------------ characteristics -> OpenCart attributes (1.5.0)
	// BM returns technical parameters in different shapes depending on the group; read the usual ones.
	public static function extractProps($p) {
		$out = array();
		$skip = '/^(id|uuid|article|brand|name|title|description|images?|default_image|cars|oe|analogs|nodes|weight|barcodes|multiplicity|price|prices|currency|warehouses|stock|quantity|badges|url|slug)$/i';

		foreach (array('properties', 'characteristics', 'characteristic', 'specifications', 'specs', 'params', 'parameters', 'attributes', 'criteria', 'details', 'technical', 'tech', 'group_filters', 'filters', 'gf') as $key) {
			if (empty($p[$key]) || !is_array($p[$key])) {
				continue;
			}

			foreach ($p[$key] as $k => $v) {
				$name = $val = '';

				if (is_array($v)) {
					foreach (array('name', 'title', 'key', 'label', 'parameter', 'property') as $nk) {
						if (isset($v[$nk]) && is_scalar($v[$nk])) {
							$name = (string)$v[$nk];
							break;
						}
					}

					foreach (array('value', 'val', 'text', 'values', 'value_name') as $vk) {
						if (isset($v[$vk])) {
							$val = is_array($v[$vk]) ? implode(', ', array_filter(array_map(function ($x) {
								return is_array($x) ? (isset($x['value']) && is_scalar($x['value']) ? $x['value'] : (isset($x['name']) && is_scalar($x['name']) ? $x['name'] : '')) : (is_scalar($x) ? $x : '');
							}, $v[$vk]))) : (is_scalar($v[$vk]) ? (string)$v[$vk] : '');
							break;
						}
					}

					if ($val !== '' && isset($v['unit']) && is_scalar($v['unit']) && $v['unit'] !== '') {
						$val .= ' ' . $v['unit'];
					}
				} elseif (is_string($k) && is_scalar($v)) {
					$name = $k;
					$val = (string)$v;
				} elseif (is_string($v) && strpos($v, ':') !== false) {
					list($name, $val) = array_map('trim', explode(':', $v, 2));
				}

				$name = trim(preg_replace('/\s+/u', ' ', strip_tags($name)), " :\t");
				$val = trim(preg_replace('/\s+/u', ' ', strip_tags($val)));

				if ($name === '' || $val === '' || preg_match($skip, $name) || mb_strlen($name) > 64 || mb_strlen($val) > 128) {
					continue;
				}

				$name = mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
				$out[$name] = $val;

				if (count($out) >= 30) {
					break 2;
				}
			}
		}

		return $out;
	}

	private function attrGroupId() {
		$p = DB_PREFIX;
		$name = 'Характеристики';
		$q = $this->db->query("SELECT attribute_group_id FROM `{$p}attribute_group_description` WHERE name = '" . $this->db->escape($name) . "' LIMIT 1");

		if ($q->num_rows) {
			return (int)$q->row['attribute_group_id'];
		}

		$this->db->query("INSERT INTO `{$p}attribute_group` SET sort_order = 1");
		$id = (int)$this->db->getLastId();

		foreach ($this->db->query("SELECT language_id FROM `{$p}language`")->rows as $l) {
			$this->db->query("INSERT INTO `{$p}attribute_group_description` SET attribute_group_id = '" . $id . "', language_id = '" . (int)$l['language_id'] . "', name = '" . $this->db->escape($name) . "'");
		}

		return $id;
	}

	private function attrId($group, $name) {
		$p = DB_PREFIX;

		if (!isset($this->models['attr'][$name])) {
			$q = $this->db->query("SELECT a.attribute_id FROM `{$p}attribute` a JOIN `{$p}attribute_description` ad ON (ad.attribute_id = a.attribute_id) WHERE a.attribute_group_id = '" . (int)$group . "' AND ad.name = '" . $this->db->escape($name) . "' LIMIT 1");

			if ($q->num_rows) {
				$id = (int)$q->row['attribute_id'];
			} else {
				$this->db->query("INSERT INTO `{$p}attribute` SET attribute_group_id = '" . (int)$group . "', sort_order = 0");
				$id = (int)$this->db->getLastId();

				foreach ($this->db->query("SELECT language_id FROM `{$p}language`")->rows as $l) {
					$this->db->query("INSERT INTO `{$p}attribute_description` SET attribute_id = '" . $id . "', language_id = '" . (int)$l['language_id'] . "', name = '" . $this->db->escape($name) . "'");
				}
			}

			$this->models['attr'][$name] = $id;
		}

		return $this->models['attr'][$name];
	}

	public function saveProps($product_id, $props) {
		if (!$props) {
			return 0;
		}

		$p = DB_PREFIX;
		$group = $this->attrGroupId();
		$this->db->query("DELETE pa FROM `{$p}product_attribute` pa JOIN `{$p}attribute` a ON (a.attribute_id = pa.attribute_id) WHERE pa.product_id = '" . (int)$product_id . "' AND a.attribute_group_id = '" . $group . "'");
		$langs = $this->db->query("SELECT language_id FROM `{$p}language`")->rows;

		foreach ($props as $name => $val) {
			$aid = $this->attrId($group, $name);

			foreach ($langs as $l) {
				$this->db->query("REPLACE INTO `{$p}product_attribute` SET product_id = '" . (int)$product_id . "', attribute_id = '" . $aid . "', language_id = '" . (int)$l['language_id'] . "', text = '" . $this->db->escape($val) . "'");
			}
		}

		return count($props);
	}

	public function saveBm($product_id, $uuid, $local_qty) {
		$r = $this->bm('GET', 'product/' . rawurlencode($uuid), array('products_as' => 'obj', 'oe' => 'short', 'output_field' => 'all'));

		if ($r['code'] == 429) {
			return array('error' => 'limit', 'code' => 429);
		}

		if ($r['code'] != 200 || empty($r['json']['product'])) {
			return array('error' => 'HTTP ' . $r['code'], 'code' => $r['code']);
		}

		$p = $r['json']['product'];
		$res = array(
			'cars'    => $this->saveCars($product_id, isset($p['cars']) ? $p['cars'] : array()),
			'oe'      => $this->saveOe($product_id, isset($p['oe']) ? $p['oe'] : array()),
			'analogs' => $this->saveAnalogs($product_id, isset($p['analogs']) ? $p['analogs'] : array()),
			'props'   => $this->saveProps($product_id, self::extractProps($p)),
			'images'  => 0,
			'node'    => isset($p['nodes']) ? mb_substr(is_array($p['nodes']) ? implode(' / ', array_map(array(__CLASS__, 'str'), $p['nodes'])) : (string)$p['nodes'], 0, 255) : '',
			'cars_json' => json_encode(isset($p['cars']) ? $p['cars'] : array(), JSON_UNESCAPED_UNICODE)
		);

		$imgs = !empty($p['images']) && is_array($p['images']) ? $p['images'] : (!empty($p['default_image']) ? array($p['default_image']) : array());
		$res['extra_json'] = json_encode(array(
			'article'      => isset($p['article']) ? $p['article'] : '',
			'brand'        => isset($p['brand']) ? $p['brand'] : '',
			'weight'       => isset($p['weight']) ? (float)$p['weight'] : 0,
			'barcodes'     => isset($p['barcodes']) && is_array($p['barcodes']) ? array_slice(array_values(array_filter($p['barcodes'], 'is_string')), 0, 5) : array(),
			'multiplicity' => isset($p['multiplicity']) ? (int)$p['multiplicity'] : 1,
			'images'       => count($imgs),
			'keys'         => array_slice(array_keys($p), 0, 60),
			'description'  => isset($p['description']) && is_string($p['description']) ? mb_substr(trim(strip_tags($p['description'])), 0, 4000) : ''
		), JSON_UNESCAPED_UNICODE);

		$mode = $this->setting('bm_photos', 'all');

		if ($mode === 'all' || ($mode === 'local' && $local_qty > 0)) {
			$res['images'] = $this->saveImage($product_id, $imgs);
			$this->saveMoreImages($product_id, $imgs);
		}

		// supplier description into an empty product description
		if (!empty($p['description']) && is_string($p['description']) && trim(strip_tags($p['description'])) !== '') {
			$this->db->query("UPDATE `" . DB_PREFIX . "product_description` SET description = '" . $this->db->escape(htmlspecialchars(nl2br(trim(preg_replace('#<(/?)(br|p|ul|li|b|strong)\b[^>]*>#i', '<$1$2>', strip_tags($p['description'], '<br><p><ul><li><b><strong>')))), ENT_COMPAT, 'UTF-8')) . "' WHERE product_id = '" . (int)$product_id . "' AND description = ''");
		}

		if (!empty($p['weight']) && (float)$p['weight'] > 0) {
			$this->db->query("UPDATE `" . DB_PREFIX . "product` SET weight = '" . (float)$p['weight'] . "' WHERE product_id = '" . (int)$product_id . "' AND weight = 0");
		}

		return $res;
	}

	// ------------------------------------------------------------ batch

	private function mark($product_id, $status, $uuid = '', $res = array()) {
		$this->db->query("REPLACE INTO `" . DB_PREFIX . "vm_catalog_enrich` SET product_id = '" . (int)$product_id . "', source = 'bm', ext_id = '" . $this->db->escape($uuid) . "', status = '" . (int)$status . "', cars = '" . (int)(isset($res['cars']) ? $res['cars'] : 0) . "', oe = '" . (int)(isset($res['oe']) ? $res['oe'] : 0) . "', analogs = '" . (int)(isset($res['analogs']) ? $res['analogs'] : 0) . "', images = '" . (int)(isset($res['images']) ? $res['images'] : 0) . "', node = '" . $this->db->escape(isset($res['node']) ? $res['node'] : '') . "', cars_json = '" . $this->db->escape(isset($res['cars_json']) ? $res['cars_json'] : '') . "', extra_json = '" . $this->db->escape(isset($res['extra_json']) ? $res['extra_json'] : '') . "', date_checked = NOW()");
	}

	/**
	 * Processes products until the time or request budget is used.
	 * status: 1 found, 0 not in BM, 2 error (retried next day)
	 */
	public function run($seconds = 50, $max_requests = 600) {
		$start = microtime(true);
		$stats = array('checked' => 0, 'found' => 0, 'notfound' => 0, 'errors' => 0, 'requests' => 0, 'stopped' => '');

		if (trim((string)$this->setting('bm_token')) === '') {
			$stats['stopped'] = 'Не вказано API-токен BM Parts';

			return $stats;
		}

		$lock = DIR_CACHE . 'vm_catalog_enrich.lock';

		if (is_file($lock) && filemtime($lock) > time() - $seconds - 30) {
			$stats['stopped'] = 'Інша обробка вже йде';

			return $stats;
		}

		@touch($lock);
		@set_time_limit($seconds + 60);

		// cron may run before the module page is opened after an update: make sure new columns exist
		$this->install();

		$this->lockFile = $lock;
		$stats['brand_skipped'] = $this->skipMissingBrands();
		$this->backfillPhotos(40, $stats, $start + $seconds * 0.25);
		$this->refreshOld(40, $stats, $start + $seconds * 0.6);

		while (microtime(true) - $start < $seconds && $this->requests < $max_requests) {
			$q = $this->db->query("SELECT i.product_id, i.article, i.brand, MAX(i.local_qty) AS local_qty
				FROM `" . DB_PREFIX . "vm_catalog_item` i
				LEFT JOIN `" . DB_PREFIX . "vm_catalog_enrich` e ON (e.product_id = i.product_id)
				WHERE (i.local_qty > 0 OR i.remote_qty > 0) AND (e.product_id IS NULL OR (e.status = 2 AND e.date_checked < DATE_SUB(NOW(), INTERVAL 1 DAY)))
				GROUP BY i.product_id ORDER BY MAX(i.local_qty > 0) DESC, i.product_id ASC LIMIT 20");

			if (!$q->num_rows) {
				$stats['stopped'] = 'done';
				break;
			}

			foreach ($q->rows as $row) {
				if (microtime(true) - $start >= $seconds || $this->requests >= $max_requests) {
					break 2;
				}

				$stats['checked']++;
				$f = $this->findBm($row['article'], $row['brand']);

				if (isset($f['error'])) {
					if ($f['code'] == 429 || $f['code'] == 401 || $f['code'] == 403) {
						$stats['stopped'] = $f['code'] == 429 ? 'Ліміт запитів BM Parts — продовжу пізніше' : 'BM Parts відхилив токен (' . $f['code'] . ')';
						$stats['checked']--;
						break 2;
					}

					$this->mark($row['product_id'], 2);
					$stats['errors']++;
					continue;
				}

				if ($f['uuid'] === '') {
					$this->mark($row['product_id'], 0);
					$stats['notfound']++;
					continue;
				}

				$res = $this->saveBm($row['product_id'], $f['uuid'], (int)$row['local_qty']);

				if (isset($res['error']) && $res['code'] == 429) {
					$stats['stopped'] = 'Ліміт запитів BM Parts — продовжу пізніше';
					break 2;
				}

				if (isset($res['error'])) {
					$this->mark($row['product_id'], 2, $f['uuid']);
					$stats['errors']++;
				} else {
					$this->mark($row['product_id'], 1, $f['uuid'], $res);
					$stats['found']++;
				}
			}

			@touch($lock);
		}

		@unlink($lock);
		$stats['requests'] = $this->requests;

		return $stats;
	}

	public function stats() {
		$p = DB_PREFIX;
		$r = $this->db->query("SELECT COUNT(*) AS checked, SUM(status = 1) AS found, SUM(status = 0) AS notfound, SUM(status = 2) AS errors, SUM(images > 0) AS images, SUM(images < 0) AS img_failed, SUM(cars > 0) AS with_cars, SUM(analogs > 0) AS with_analogs FROM `{$p}vm_catalog_enrich`")->row;
		$total = (int)$this->db->query("SELECT COUNT(DISTINCT product_id) AS n FROM `{$p}vm_catalog_item` WHERE local_qty > 0 OR remote_qty > 0")->row['n'];
		$r['total'] = $total;
		$r['links'] = (int)$this->db->query("SELECT COUNT(*) AS n FROM `{$p}vm_product_vehicle` WHERE source = 'bm'")->row['n'];
		$r['models'] = (int)$this->db->query("SELECT COUNT(DISTINCT model_id) AS n FROM `{$p}vm_product_vehicle` WHERE source = 'bm'")->row['n'];

		foreach ($r as $k => $v) {
			$r[$k] = (int)$v;
		}

		$r['img_error'] = (string)$this->setting('bm_img_error');

		return $r;
	}

	// for support: tries one photo of a found product and reports every step
	public function photoTest() {
		$out = array();
		$out[] = 'mode=' . $this->setting('bm_photos', 'local') . ' pattern=' . $this->setting('bm_img_pattern', '-') . ' last_error=' . $this->setting('bm_img_error', '-');
		$out[] = 'DIR_IMAGE=' . DIR_IMAGE . ' writable=' . (is_writable(DIR_IMAGE . 'catalog/') ? 'yes' : 'NO');
		$cols = DIR_UPLOAD . 'vm_catalog/last_columns.json';
		$out[] = 'last import columns: ' . (is_file($cols) ? file_get_contents($cols) : '(no record yet)');
		$st = $this->db->query("SELECT supplier, COUNT(*) AS n, SUM(local_qty > 0) AS l, SUM(local_qty = 0 AND remote_qty > 0) AS r FROM `" . DB_PREFIX . "vm_catalog_item` GROUP BY supplier")->rows;
		$out[] = 'items: ' . json_encode($st, JSON_UNESCAPED_UNICODE);
		$row = $this->db->query("SELECT e.product_id, e.ext_id, e.images, p.image, MAX(i.local_qty) AS lq FROM `" . DB_PREFIX . "vm_catalog_enrich` e JOIN `" . DB_PREFIX . "product` p ON (p.product_id = e.product_id) LEFT JOIN `" . DB_PREFIX . "vm_catalog_item` i ON (i.product_id = e.product_id) WHERE e.status = 1 AND e.ext_id <> '' GROUP BY e.product_id ORDER BY (p.image = '') DESC, MAX(i.local_qty) DESC LIMIT 1")->row;

		if (!$row) {
			return implode("\n", array_merge($out, array('no found products yet')));
		}

		$out[] = 'product=' . $row['product_id'] . ' uuid=' . $row['ext_id'] . ' enrich.images=' . $row['images'] . ' product.image="' . $row['image'] . '" local_qty=' . $row['lq'];
		$r = $this->bm('GET', 'product/' . rawurlencode($row['ext_id']) . '/images');
		$out[] = 'GET product/{uuid}/images -> ' . $r['code'] . ' ' . mb_substr(json_encode($r['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 300);
		$imgs = isset($r['json']['images']) ? (array)$r['json']['images'] : array();

		if ($imgs) {
			$first = is_string($imgs[0]) ? $imgs[0] : json_encode($imgs[0]);
			$out[] = 'first image raw: ' . $first;
			$tmp = DIR_CACHE . 'vm_photo_test.img';

			foreach ($this->imageUrls(is_string($imgs[0]) ? $imgs[0] : '') as $i => $url) {
				$ok = $this->download($url, $tmp);
				$out[] = 'try #' . $i . ' ' . $url . ' -> ' . ($ok ? 'OK ' . filesize($tmp) . ' bytes' : 'FAIL ' . $this->imgError);
				@unlink($tmp);

				if ($ok) {
					// downloads work now: let the backfill retry products whose photo failed before
					$this->db->query("UPDATE `" . DB_PREFIX . "vm_catalog_enrich` SET images = 0 WHERE images < 0");
					$this->saveSetting('bm_img_pattern', (string)$i);
					$out[] = 'reset failed photos for retry';
					break;
				}
			}
		}

		return implode("\n", $out);
	}

	public function sample() {
		return $this->db->query("SELECT e.product_id, e.ext_id, e.node, e.cars_json, pd.name FROM `" . DB_PREFIX . "vm_catalog_enrich` e LEFT JOIN `" . DB_PREFIX . "product_description` pd ON (pd.product_id = e.product_id AND pd.language_id = '" . (int)$this->config->get('config_language_id') . "') WHERE e.status = 1 AND e.cars > 0 ORDER BY e.date_checked DESC LIMIT 1")->row;
	}
}
