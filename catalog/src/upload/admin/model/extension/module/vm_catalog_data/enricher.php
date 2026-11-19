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

		$this->db->query("CREATE TABLE IF NOT EXISTS `{$p}vm_catalog_enrich` (
			`product_id` INT(11) NOT NULL, `source` VARCHAR(16) NOT NULL DEFAULT 'bm', `ext_id` VARCHAR(64) NOT NULL DEFAULT '',
			`status` TINYINT(1) NOT NULL DEFAULT 0, `cars` INT(11) NOT NULL DEFAULT 0, `oe` INT(11) NOT NULL DEFAULT 0, `analogs` INT(11) NOT NULL DEFAULT 0,
			`images` INT(11) NOT NULL DEFAULT 0, `node` VARCHAR(255) NOT NULL DEFAULT '', `cars_json` MEDIUMTEXT NOT NULL, `date_checked` DATETIME NOT NULL,
			PRIMARY KEY (`product_id`), KEY `status` (`status`, `date_checked`)) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4");
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

	private function download($url, $file) {
		$ch = curl_init($url);
		$fh = fopen($file . '.tmp', 'w');
		curl_setopt_array($ch, array(
			CURLOPT_FILE           => $fh,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS      => 3,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_USERAGENT      => 'VM-Catalog vmautoparts.com.ua'
		));
		curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
		curl_close($ch);
		fclose($fh);

		if ($code == 200 && stripos($type, 'image/') === 0 && filesize($file . '.tmp') > 500 && @getimagesize($file . '.tmp')) {
			rename($file . '.tmp', $file);

			return true;
		}

		@unlink($file . '.tmp');

		return false;
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

	private function saveCars($product_id, $cars) {
		$n = 0;
		$this->db->query("DELETE FROM `" . DB_PREFIX . "vm_product_vehicle` WHERE product_id = '" . (int)$product_id . "' AND source = 'bm'");

		foreach ((array)$cars as $car) {
			if (!is_array($car)) {
				continue;
			}

			$make = self::str(isset($car['brand']) ? $car['brand'] : (isset($car['name']) ? $car['name'] : ''));
			$make_id = $this->makeId($make);

			if (!$make_id) {
				continue;
			}

			$models = isset($car['models']) && is_array($car['models']) ? $car['models'] : array();

			foreach ($models as $m) {
				$raw = is_array($m) ? $m : array();
				$name = self::str(isset($raw['model']) ? $raw['model'] : $m);
				list($name, $yf, $yt) = self::splitYears($name, isset($raw['model']) && is_array($raw['model']) ? $raw['model'] : $raw);
				$model_id = $this->modelId($make_id, $name);

				if ($model_id) {
					$this->db->query("INSERT INTO `" . DB_PREFIX . "vm_product_vehicle` SET product_id = '" . (int)$product_id . "', model_id = '" . $model_id . "', year_from = '" . (int)$yf . "', year_to = '" . (int)$yt . "', source = 'bm' ON DUPLICATE KEY UPDATE year_from = IF(source = 'bm', VALUES(year_from), year_from), year_to = IF(source = 'bm', VALUES(year_to), year_to)");
					$n++;
				}
			}
		}

		return $n;
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

		foreach ($this->imageUrls($paths[0]) as $i => $url) {
			$ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
			$ext = in_array($ext, array('jpg', 'jpeg', 'png', 'webp', 'gif'), true) ? $ext : 'jpg';
			$rel = $sub . (int)$product_id . '.' . $ext;

			if ($this->download($url, DIR_IMAGE . $rel)) {
				if ((int)$this->setting('bm_img_pattern', -1) !== $i) {
					$this->saveSetting('bm_img_pattern', (string)$i);
				}

				$this->db->query("UPDATE `" . DB_PREFIX . "product` SET image = '" . $this->db->escape($rel) . "' WHERE product_id = '" . (int)$product_id . "'");

				return 1;
			}
		}

		return 0;
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
			'images'  => 0,
			'node'    => isset($p['nodes']) ? mb_substr(is_array($p['nodes']) ? implode(' / ', array_map(array(__CLASS__, 'str'), $p['nodes'])) : (string)$p['nodes'], 0, 255) : '',
			'cars_json' => json_encode(isset($p['cars']) ? $p['cars'] : array(), JSON_UNESCAPED_UNICODE)
		);

		$mode = $this->setting('bm_photos', 'local');

		if ($mode === 'all' || ($mode === 'local' && $local_qty > 0)) {
			$imgs = !empty($p['images']) ? $p['images'] : (!empty($p['default_image']) ? array($p['default_image']) : array());
			$res['images'] = $this->saveImage($product_id, $imgs);
		}

		if (!empty($p['weight']) && (float)$p['weight'] > 0) {
			$this->db->query("UPDATE `" . DB_PREFIX . "product` SET weight = '" . (float)$p['weight'] . "' WHERE product_id = '" . (int)$product_id . "' AND weight = 0");
		}

		return $res;
	}

	// ------------------------------------------------------------ batch

	private function mark($product_id, $status, $uuid = '', $res = array()) {
		$this->db->query("REPLACE INTO `" . DB_PREFIX . "vm_catalog_enrich` SET product_id = '" . (int)$product_id . "', source = 'bm', ext_id = '" . $this->db->escape($uuid) . "', status = '" . (int)$status . "', cars = '" . (int)(isset($res['cars']) ? $res['cars'] : 0) . "', oe = '" . (int)(isset($res['oe']) ? $res['oe'] : 0) . "', analogs = '" . (int)(isset($res['analogs']) ? $res['analogs'] : 0) . "', images = '" . (int)(isset($res['images']) ? $res['images'] : 0) . "', node = '" . $this->db->escape(isset($res['node']) ? $res['node'] : '') . "', cars_json = '" . $this->db->escape(isset($res['cars_json']) ? $res['cars_json'] : '') . "', date_checked = NOW()");
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
		$r = $this->db->query("SELECT COUNT(*) AS checked, SUM(status = 1) AS found, SUM(status = 0) AS notfound, SUM(status = 2) AS errors, SUM(images) AS images, SUM(cars > 0) AS with_cars, SUM(analogs > 0) AS with_analogs FROM `{$p}vm_catalog_enrich`")->row;
		$total = (int)$this->db->query("SELECT COUNT(DISTINCT product_id) AS n FROM `{$p}vm_catalog_item` WHERE local_qty > 0 OR remote_qty > 0")->row['n'];
		$r['total'] = $total;
		$r['links'] = (int)$this->db->query("SELECT COUNT(*) AS n FROM `{$p}vm_product_vehicle` WHERE source = 'bm'")->row['n'];
		$r['models'] = (int)$this->db->query("SELECT COUNT(DISTINCT model_id) AS n FROM `{$p}vm_product_vehicle` WHERE source = 'bm'")->row['n'];

		foreach ($r as $k => $v) {
			$r[$k] = (int)$v;
		}

		return $r;
	}

	public function sample() {
		return $this->db->query("SELECT e.product_id, e.ext_id, e.node, e.cars_json, pd.name FROM `" . DB_PREFIX . "vm_catalog_enrich` e LEFT JOIN `" . DB_PREFIX . "product_description` pd ON (pd.product_id = e.product_id AND pd.language_id = '" . (int)$this->config->get('config_language_id') . "') WHERE e.status = 1 AND e.cars > 0 ORDER BY e.date_checked DESC LIMIT 1")->row;
	}
}
