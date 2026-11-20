<?php
/**
 * V&M Catalog — UTR "details list" YML (export from the ord24 cabinet):
 * photos (S3 links), characteristics, descriptions, weight and dimensions.
 * Import: the admin uploads the file in chunks, then step() reads it in time-limited passes.
 * Photos are queued and downloaded by runPhotos() (admin button and the cron URL).
 */
class VmCatalogYml {
	private $db;
	private $config;
	private $registry;
	private $memo = array();
	public $error = '';

	public function __construct($registry) {
		$this->registry = $registry;
		$this->db = $registry->get('db');
		$this->config = $registry->get('config');
	}

	public static function dir() {
		$dir = DIR_UPLOAD . 'vm_catalog/';

		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}

		return $dir;
	}

	public static function file() {
		return self::dir() . 'utr_details.yml';
	}

	private static function jobFile() {
		return self::dir() . 'yml_job.json';
	}

	public function install() {
		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "vm_catalog_photo` (
			`product_id` INT(11) NOT NULL,
			`pos` TINYINT(3) NOT NULL DEFAULT 0,
			`url` VARCHAR(500) NOT NULL,
			`status` TINYINT(1) NOT NULL DEFAULT 0,
			`date_added` DATETIME NOT NULL,
			PRIMARY KEY (`product_id`, `pos`),
			KEY `status` (`status`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
	}

	private function setting($key, $default = '') {
		$v = $this->config->get('module_vm_catalog_' . $key);

		return ($v === null || $v === '') ? $default : $v;
	}

	public static function key($s) {
		return mb_substr(mb_strtoupper(preg_replace('/[\s\-\.\/\\\\]+/u', '', (string)$s), 'UTF-8'), 0, 64, 'UTF-8');
	}

	// ------------------------------------------------------------ upload in chunks

	public function chunk($index, $data) {
		$file = self::file();

		if ($index == 0) {
			@unlink($file);
			@unlink(self::jobFile());
		}

		if (@file_put_contents($file, $data, FILE_APPEND | LOCK_EX) === false) {
			return array('error' => 'Не вдалося записати файл у ' . self::dir());
		}

		return array('ok' => 1, 'size' => filesize($file));
	}

	// ------------------------------------------------------------ job

	public function getJob() {
		$j = is_file(self::jobFile()) ? json_decode(file_get_contents(self::jobFile()), true) : null;

		return is_array($j) ? $j : null;
	}

	private function saveJob($j) {
		file_put_contents(self::jobFile(), json_encode($j, JSON_UNESCAPED_UNICODE));
	}

	public function start() {
		$file = self::file();

		if (!is_file($file) || filesize($file) < 200) {
			return array('error' => 'Файл не завантажився');
		}

		$head = file_get_contents($file, false, null, 0, 4096);

		if (strpos($head, '<yml_catalog') === false && strpos($head, '<offers') === false) {
			return array('error' => 'Це не YML-файл UTR (немає <yml_catalog>)');
		}

		// count offers quickly for the progress bar
		$total = 0;
		$fh = fopen($file, 'r');

		while (!feof($fh)) {
			$total += substr_count(fread($fh, 4 * 1048576), '<offer ');
		}

		fclose($fh);

		$this->install();
		$job = array('done' => 0, 'total' => $total, 'finished' => false, 'started' => date('Y-m-d H:i:s'),
			'stats' => array('matched' => 0, 'unmatched' => 0, 'photos' => 0, 'props' => 0, 'desc' => 0, 'dims' => 0));
		$this->saveJob($job);

		return $job;
	}

	public function step($seconds = 20) {
		$job = $this->getJob();

		if (!$job) {
			return array('error' => 'Немає активного імпорту');
		}

		if ($job['finished']) {
			return $job;
		}

		if (!class_exists('XMLReader')) {
			return array('error' => 'На хостингу немає PHP XMLReader');
		}

		@set_time_limit($seconds + 60);
		$start = microtime(true);
		$r = new XMLReader();

		if (!$r->open(self::file(), 'UTF-8', LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
			return array('error' => 'Не вдалося відкрити файл');
		}

		// move to the first offer, then skip the ones already done
		while ($r->read() && !($r->nodeType == XMLReader::ELEMENT && $r->name === 'offer')) {
		}

		for ($i = 0; $i < $job['done'] && $r->name === 'offer'; $i++) {
			$r->next('offer');
		}

		$buf = array();
		$more = false;

		while ($r->nodeType == XMLReader::ELEMENT && $r->name === 'offer') {
			$x = @simplexml_load_string($r->readOuterXml());
			$r->next('offer');
			$job['done']++;

			if ($x) {
				$buf[] = $this->offer($x);
			}

			if (count($buf) >= 300) {
				$this->flush($buf, $job['stats']);
				$buf = array();

				if (microtime(true) - $start > $seconds) {
					$more = $r->name === 'offer';
					break;
				}
			}
		}

		if ($buf) {
			$this->flush($buf, $job['stats']);
		}

		$r->close();

		if (!$more) {
			$job['finished'] = true;
			$job['done'] = max($job['done'], $job['total']);
			$job['date'] = date('Y-m-d H:i:s');
		}

		$this->saveJob($job);

		return $job;
	}

	private function offer($x) {
		$o = array(
			'article' => trim((string)$x->vendorCode),
			'brand'   => trim((string)$x->vendor),
			'pics'    => array(),
			'params'  => array(),
			'desc'    => trim((string)$x->description_ua) !== '' ? trim((string)$x->description_ua) : trim((string)$x->description),
			'name'    => trim((string)$x->name_ua),
			'weight'  => 0,
			'dims'    => array()
		);

		foreach ($x->picture as $p) {
			$u = trim((string)$p);

			if (preg_match('#^https://[a-z0-9.\-]+/#i', $u) && strlen($u) < 500) {
				$o['pics'][] = $u;
			}
		}

		$o['pics'] = array_values(array_unique($o['pics']));

		foreach ($x->param as $p) {
			$n = self::paramName((string)$p['name']);
			$v = trim(preg_replace('/\s+/u', ' ', (string)$p));

			if ($n !== '' && $v !== '' && mb_strlen($v) <= 128) {
				$o['params'][$n] = $v;
			}
		}

		if (isset($x->dimensions)) {
			$d = $x->dimensions;
			$o['weight'] = (float)$d->weight;
			$o['dims'] = array('l' => (float)$d->length, 'w' => (float)$d->width, 'h' => (float)$d->height);
		}

		return $o;
	}

	// "Довжина mm" -> "Довжина, мм", "Напруга [В]" -> "Напруга, В"
	public static function paramName($n) {
		$n = trim(preg_replace('/\s+/u', ' ', $n));
		$units = array('mm' => 'мм', 'cm' => 'см', 'm' => 'м', 'l' => 'л', 'ml' => 'мл', 'kg' => 'кг', 'g' => 'г', 'Pc' => 'шт', 'pcs' => 'шт', 'V' => 'В', 'W' => 'Вт', 'A' => 'А', 'Ah' => 'А·год', 'bar' => 'бар', 'Nm' => 'Н·м');

		if (preg_match('/^(.+?)\s*\[([^\]]+)\]$/u', $n, $m)) {
			$n = trim($m[1]) . ', ' . trim($m[2]);
		} elseif (preg_match('/^(.+?)\s+([A-Za-z]{1,4})$/u', $n, $m) && isset($units[$m[2]])) {
			$n = trim($m[1]) . ', ' . $units[$m[2]];
		}

		return mb_substr($n, 0, 64);
	}

	private function flush($buf, &$stats) {
		$p = DB_PREFIX;
		$keys = array();

		foreach ($buf as $o) {
			if ($o['article'] !== '') {
				$keys[self::key($o['article'])] = true;
			}
		}

		if (!$keys) {
			return;
		}

		$in = implode(',', array_map(function ($k) {
			return "'" . $this->db->escape($k) . "'";
		}, array_keys($keys)));

		$map = array();

		foreach ($this->db->query("SELECT DISTINCT i.art_key, i.brand_key, i.product_id, p.image, p.weight, p.length, (SELECT COUNT(*) FROM `{$p}product_image` pi WHERE pi.product_id = i.product_id) AS gallery
			FROM `{$p}vm_catalog_item` i INNER JOIN `{$p}product` p ON (p.product_id = i.product_id) WHERE i.art_key IN (" . $in . ")")->rows as $row) {
			$map[$row['art_key']][$row['brand_key']] = $row;
		}

		$max = max(1, min(6, (int)$this->setting('yml_photos', 1)));
		$enricher = $this->enricher();
		$langs = $this->db->query("SELECT language_id FROM `{$p}language`")->rows;

		foreach ($buf as $o) {
			$ak = self::key($o['article']);
			$bk = self::key($o['brand']);
			$row = null;

			if (isset($map[$ak][$bk])) {
				$row = $map[$ak][$bk];
			} elseif (isset($map[$ak]) && count($map[$ak]) == 1) {
				// same article, brand written differently ("NTN-SNR" / "SNR")
				$row = reset($map[$ak]);
			}

			if (!$row) {
				$stats['unmatched']++;
				continue;
			}

			$stats['matched']++;
			$pid = (int)$row['product_id'];

			// photos: main if missing, gallery if empty
			$need_main = trim((string)$row['image']) === '' || strpos($row['image'], 'placeholder') !== false || strpos($row['image'], 'no_image') !== false;
			$need_gallery = (int)$row['gallery'] === 0;

			if ($o['pics'] && ($need_main || $need_gallery)) {
				foreach (array_slice($o['pics'], 0, $max) as $pos => $url) {
					if ($pos == 0 && !$need_main && !$need_gallery) {
						continue;
					}

					$this->db->query("INSERT IGNORE INTO `{$p}vm_catalog_photo` SET product_id = '" . $pid . "', pos = '" . (int)$pos . "', url = '" . $this->db->escape($url) . "', status = 0, date_added = NOW()");
					$stats['photos'] += $this->db->countAffected();
				}
			}

			if ($o['params'] && $enricher) {
				$enricher->saveProps($pid, array_slice($o['params'], 0, 30, true));
				$stats['props']++;
			}

			// description only where the shop has none, and only if it says more than the name
			if ($o['desc'] !== '' && mb_strlen($o['desc']) > 25 && trim($o['desc'], '_ ') !== trim($o['name'], '_ ')) {
				$html = htmlspecialchars(nl2br(trim(strip_tags($o['desc'], '<br><p><ul><li><b><strong>'))), ENT_COMPAT, 'UTF-8');

				foreach ($langs as $l) {
					$this->db->query("UPDATE `{$p}product_description` SET description = '" . $this->db->escape($html) . "' WHERE product_id = '" . $pid . "' AND language_id = '" . (int)$l['language_id'] . "' AND description = ''");
					$stats['desc'] += $this->db->countAffected() > 0 ? 1 : 0;
				}
			}

			if ($o['weight'] > 0 && (float)$row['weight'] == 0) {
				$this->db->query("UPDATE `{$p}product` SET weight = '" . (float)$o['weight'] . "', weight_class_id = '" . $this->classId('weight', array('kg', 'кг')) . "'"
					. (((float)$row['length'] == 0 && !empty($o['dims']['l'])) ? ", length = '" . (float)$o['dims']['l'] . "', width = '" . (float)$o['dims']['w'] . "', height = '" . (float)$o['dims']['h'] . "', length_class_id = '" . $this->classId('length', array('cm', 'см')) . "'" : '')
					. " WHERE product_id = '" . $pid . "'");
				$stats['dims']++;
			}
		}
	}

	private function classId($type, $units) {
		if (!isset($this->memo[$type])) {
			$this->memo[$type] = (int)$this->config->get('config_' . $type . '_class_id');
			$in = implode(',', array_map(function ($u) {
				return "'" . $this->db->escape($u) . "'";
			}, $units));
			$q = $this->db->query("SELECT " . $type . "_class_id AS id FROM `" . DB_PREFIX . $type . "_class_description` WHERE unit IN (" . $in . ") LIMIT 1");

			if ($q->num_rows) {
				$this->memo[$type] = (int)$q->row['id'];
			}
		}

		return $this->memo[$type];
	}

	private function enricher() {
		if (!array_key_exists('enricher', $this->memo)) {
			$f = dirname(__FILE__) . '/enricher.php';
			$this->memo['enricher'] = null;

			if (is_file($f)) {
				require_once $f;
				$this->memo['enricher'] = new VmCatalogEnricher($this->registry);
			}
		}

		return $this->memo['enricher'];
	}

	// ------------------------------------------------------------ photo download

	public function photoStats() {
		$this->install();
		$r = $this->db->query("SELECT SUM(status = 0) AS pending, SUM(status = 1) AS done, SUM(status = 2) AS failed, COUNT(DISTINCT IF(status = 1, product_id, NULL)) AS products FROM `" . DB_PREFIX . "vm_catalog_photo`")->row;

		return array('pending' => (int)$r['pending'], 'done' => (int)$r['done'], 'failed' => (int)$r['failed'], 'products' => (int)$r['products'], 'error' => (string)$this->setting('yml_photo_error'));
	}

	public function runPhotos($seconds = 20) {
		$this->install();
		$p = DB_PREFIX;
		$start = microtime(true);
		$res = array('done' => 0, 'failed' => 0);
		$lock = DIR_CACHE . 'vm_catalog_photos.lock';

		if (is_file($lock) && filemtime($lock) > time() - 90) {
			$res['busy'] = 1;

			return $res;
		}

		@touch($lock);
		@set_time_limit($seconds + 60);

		while (microtime(true) - $start < $seconds) {
			// products in the local warehouse first, main photos before gallery
			$rows = $this->db->query("SELECT ph.product_id, ph.pos, ph.url, pr.image FROM `{$p}vm_catalog_photo` ph INNER JOIN `{$p}product` pr ON (pr.product_id = ph.product_id)
				WHERE ph.status = 0 ORDER BY (pr.quantity > 0) DESC, ph.pos ASC, ph.product_id ASC LIMIT 20")->rows;

			if (!$rows) {
				break;
			}

			foreach ($rows as $row) {
				if (microtime(true) - $start >= $seconds) {
					break 2;
				}

				@touch($lock);
				$pid = (int)$row['product_id'];
				$sub = 'catalog/utr/' . substr(md5($pid), 0, 2) . '/';

				if (!is_dir(DIR_IMAGE . $sub)) {
					@mkdir(DIR_IMAGE . $sub, 0755, true);
				}

				$rel = $sub . $pid . ($row['pos'] ? '-' . ((int)$row['pos'] + 1) : '') . '.jpg';
				$ok = $this->download($row['url'], DIR_IMAGE . $rel);

				if ($ok) {
					$img = $this->db->query("SELECT image FROM `{$p}product` WHERE product_id = '" . $pid . "'")->row;
					$cur = isset($img['image']) ? trim((string)$img['image']) : '';

					if ($cur === '' || strpos($cur, 'placeholder') !== false || strpos($cur, 'no_image') !== false) {
						$this->db->query("UPDATE `{$p}product` SET image = '" . $this->db->escape($rel) . "' WHERE product_id = '" . $pid . "'");
					} elseif ($cur !== $rel && !$this->db->query("SELECT 1 FROM `{$p}product_image` WHERE product_id = '" . $pid . "' AND image = '" . $this->db->escape($rel) . "'")->num_rows) {
						$this->db->query("INSERT INTO `{$p}product_image` SET product_id = '" . $pid . "', image = '" . $this->db->escape($rel) . "', sort_order = '" . ((int)$row['pos'] + 1) . "'");
					}

					$res['done']++;
				} else {
					$res['failed']++;
				}

				$this->db->query("UPDATE `{$p}vm_catalog_photo` SET status = '" . ($ok ? 1 : 2) . "' WHERE product_id = '" . $pid . "' AND pos = '" . (int)$row['pos'] . "'");
			}
		}

		@unlink($lock);

		if ($res['failed'] && !$res['done']) {
			$this->saveSetting('yml_photo_error', date('d.m H:i') . ' ' . $this->error);
		} elseif ($res['done'] && $this->setting('yml_photo_error') !== '') {
			$this->saveSetting('yml_photo_error', '');
		}

		return $res;
	}

	private function saveSetting($key, $value) {
		$k = 'module_vm_catalog_' . $key;
		$this->db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE store_id = 0 AND `code` = 'module_vm_catalog' AND `key` = '" . $this->db->escape($k) . "'");
		$this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET store_id = 0, `code` = 'module_vm_catalog', `key` = '" . $this->db->escape($k) . "', `value` = '" . $this->db->escape($value) . "', serialized = 0");
		$this->config->set($k, $value);
	}

	private function download($url, $file) {
		$fh = @fopen($file . '.tmp', 'w');

		if (!$fh) {
			$this->error = 'немає прав на запис у ' . dirname($file);

			return false;
		}

		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_FILE           => $fh,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS      => 3,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; VMAutoParts/1.0)'
		));
		curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err = curl_error($ch);
		curl_close($ch);
		fclose($fh);
		$size = (int)@filesize($file . '.tmp');

		if ($code == 200 && $size > 300 && @getimagesize($file . '.tmp')) {
			$this->shrink($file . '.tmp');
			rename($file . '.tmp', $file);

			return true;
		}

		$this->error = $url . ' → ' . ($err ? $err : 'HTTP ' . $code . ', ' . $size . ' байт');
		@unlink($file . '.tmp');

		return false;
	}

	// max 800 px, JPEG 82, transparent -> white
	private function shrink($file) {
		if (!function_exists('imagecreatefromstring')) {
			return;
		}

		$info = @getimagesize($file);

		if (!$info || $info[0] * $info[1] > 25000000) {
			return;
		}

		if ($info[2] == IMAGETYPE_JPEG && max($info[0], $info[1]) <= 800 && filesize($file) < 120000) {
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
		imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
		imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
		$tmp = $file . '.jpg';

		if (@imagejpeg($dst, $tmp, 82)) {
			rename($tmp, $file);
		} else {
			@unlink($tmp);
		}

		imagedestroy($src);
		imagedestroy($dst);
	}
}
