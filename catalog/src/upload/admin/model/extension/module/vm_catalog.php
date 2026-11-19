<?php
/**
 * V&M Catalog: category tree + supplier price-list import for OpenCart 3.
 */
class ModelExtensionModuleVmCatalog extends Model {
	const BATCH = 400;

	public static function dataDir() {
		return DIR_APPLICATION . 'model/extension/module/vm_catalog_data/';
	}

	public function workDir() {
		$dir = DIR_UPLOAD . 'vm_catalog/';

		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}

		if (!is_file($dir . 'index.html')) {
			@file_put_contents($dir . 'index.html', '');
			@file_put_contents($dir . '.htaccess', "Deny from all\n");
		}

		return $dir;
	}

	// ------------------------------------------------------------ install

	public function install() {
		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "vm_catalog_item` (
			`supplier` VARCHAR(32) NOT NULL,
			`art_key` VARCHAR(64) NOT NULL,
			`brand_key` VARCHAR(64) NOT NULL,
			`article` VARCHAR(64) NOT NULL,
			`brand` VARCHAR(64) NOT NULL,
			`product_id` INT(11) NOT NULL,
			`price_in` DECIMAL(15,4) NOT NULL DEFAULT '0.0000',
			`local_qty` INT(11) NOT NULL DEFAULT '0',
			`remote_qty` INT(11) NOT NULL DEFAULT '0',
			`stock` TEXT NOT NULL,
			`date_seen` DATETIME NOT NULL,
			PRIMARY KEY (`supplier`,`art_key`,`brand_key`),
			KEY `product_id` (`product_id`),
			KEY `art_key` (`art_key`)
		) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4");

		// lookups of existing products by article
		$idx = $this->db->query("SHOW INDEX FROM `" . DB_PREFIX . "product` WHERE Key_name = 'vm_model'");

		if (!$idx->num_rows) {
			$this->db->query("ALTER TABLE `" . DB_PREFIX . "product` ADD INDEX `vm_model` (`model`)");
		}
	}

	public function stats() {
		$q = $this->db->query("SELECT supplier, COUNT(*) AS total, SUM(local_qty > 0) AS local_cnt, SUM(local_qty = 0 AND remote_qty > 0) AS remote_cnt, MAX(date_seen) AS last FROM `" . DB_PREFIX . "vm_catalog_item` GROUP BY supplier");

		return $q->rows;
	}

	// ------------------------------------------------------------ categories

	public function categoryPreset() {
		$file = self::dataDir() . 'categories.json';

		if (!is_file($file)) {
			return array();
		}

		$tree = json_decode(file_get_contents($file), true);

		return is_array($tree) ? $tree : array();
	}

	private function catKey($name) {
		$name = mb_strtolower(trim(html_entity_decode($name, ENT_QUOTES, 'UTF-8')), 'UTF-8');

		return preg_replace('/\s+/u', ' ', $name);
	}

	private function findCategory($name, $parent_id) {
		$q = $this->db->query("SELECT c.category_id, c.parent_id, cd.name FROM `" . DB_PREFIX . "category` c LEFT JOIN `" . DB_PREFIX . "category_description` cd ON (cd.category_id = c.category_id AND cd.language_id = '" . (int)$this->config->get('config_language_id') . "')" . ($parent_id === null ? '' : " WHERE c.parent_id = '" . (int)$parent_id . "'"));
		$key = $this->catKey($name);

		foreach ($q->rows as $row) {
			if ($this->catKey((string)$row['name']) === $key) {
				return $row;
			}
		}

		return null;
	}

	private function slug($text) {
		$map = array('а'=>'a','б'=>'b','в'=>'v','г'=>'h','ґ'=>'g','д'=>'d','е'=>'e','є'=>'ie','ж'=>'zh','з'=>'z','и'=>'y','і'=>'i','ї'=>'i','й'=>'i','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'kh','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'shch','ь'=>'','ю'=>'iu','я'=>'ia','ы'=>'y','э'=>'e','ё'=>'e','ъ'=>'',"'"=>'','’'=>'');
		$text = mb_strtolower($text, 'UTF-8');
		$text = strtr($text, $map);
		$text = preg_replace('/[^a-z0-9]+/', '-', $text);

		return trim($text, '-');
	}

	private function uniqueKeyword($base) {
		$base = $base !== '' ? substr($base, 0, 60) : 'category';
		$kw = $base;
		$i = 2;

		while ($this->db->query("SELECT 1 FROM `" . DB_PREFIX . "seo_url` WHERE keyword = '" . $this->db->escape($kw) . "' LIMIT 1")->num_rows) {
			$kw = $base . '-' . $i++;
		}

		return $kw;
	}

	private function addCat($name, $parent_id, $sort, $top) {
		$this->load->model('catalog/category');
		$this->load->model('localisation/language');

		$desc = array();
		$seo = array();
		$base = $this->slug($name);

		foreach ($this->model_localisation_language->getLanguages() as $lang) {
			$desc[$lang['language_id']] = array(
				'name'             => $name,
				'description'      => '',
				'meta_title'       => $name . ' — купити в Україні',
				'meta_description' => $name . ': підбір за авто, наявність і доставка по Україні.',
				'meta_keyword'     => ''
			);
			$seo[0][$lang['language_id']] = $this->uniqueKeyword($base . (count($seo[0] ?? array()) ? '-' . $lang['code'] : ''));
		}

		return $this->model_catalog_category->addCategory(array(
			'parent_id'            => (int)$parent_id,
			'category_description' => $desc,
			'category_store'       => array(0),
			'category_seo_url'     => $seo,
			'category_layout'      => array(),
			'category_filter'      => array(),
			'image'                => '',
			'top'                  => $top ? 1 : 0,
			'column'               => 1,
			'sort_order'           => (int)$sort,
			'status'               => 1
		));
	}

	private function moveCat($category_id, $parent_id) {
		$this->load->model('catalog/category');

		$c = $this->model_catalog_category->getCategory($category_id);

		if (!$c) {
			return;
		}

		$c['parent_id'] = (int)$parent_id;
		$c['top'] = 0;
		$c['category_description'] = $this->model_catalog_category->getCategoryDescriptions($category_id);
		$c['category_store'] = $this->model_catalog_category->getCategoryStores($category_id);
		$c['category_seo_url'] = $this->model_catalog_category->getCategorySeoUrls($category_id);
		$c['category_layout'] = $this->model_catalog_category->getCategoryLayouts($category_id);
		$c['category_filter'] = $this->model_catalog_category->getCategoryFilters($category_id);

		$this->model_catalog_category->editCategory($category_id, $c);
	}

	public function createCategories() {
		$tree = $this->categoryPreset();

		if (!$tree) {
			return array('error' => 'preset');
		}

		@set_time_limit(300);

		$created = $existing = $moved = 0;

		foreach ($tree as $i => $root) {
			$row = $this->findCategory($root['name'], 0);

			if ($row) {
				$root_id = (int)$row['category_id'];
				$existing++;
			} else {
				$root_id = $this->addCat($root['name'], 0, $i + 1, true);
				$created++;
			}

			foreach ($root['children'] as $j => $child) {
				if ($this->findCategory($child, $root_id)) {
					$existing++;
					continue;
				}

				// a category with this name already sits at the top level (e.g. "Амортизатори"):
				// move it under the new section so its products stay where they are
				$loose = $this->findCategory($child, 0);

				if ($loose && (int)$loose['category_id'] !== $root_id) {
					$this->moveCat((int)$loose['category_id'], $root_id);
					$moved++;
					continue;
				}

				$this->addCat($child, $root_id, $j + 1, false);
				$created++;
			}
		}

		$this->cache->delete('category');

		return array('created' => $created, 'existing' => $existing, 'moved' => $moved);
	}

	// ------------------------------------------------------------ price import: reading files

	private function colName($ref) {
		$letters = preg_replace('/\d+/', '', $ref);
		$n = 0;

		for ($i = 0; $i < strlen($letters); $i++) {
			$n = $n * 26 + (ord($letters[$i]) - 64);
		}

		return $n - 1;
	}

	// XLSX (first sheet) -> TSV, streaming: works with ~100k rows on shared hosting
	public function xlsxToTsv($xlsx, $tsv) {
		if (!class_exists('ZipArchive') || !class_exists('XMLReader')) {
			return array('error' => 'Немає розширень PHP ZipArchive/XMLReader на хостингу');
		}

		$zip = new ZipArchive();

		if ($zip->open($xlsx) !== true) {
			return array('error' => 'Файл не схожий на Excel (.xlsx)');
		}

		$sheet = 'xl/worksheets/sheet1.xml';
		$rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
		$wb = $zip->getFromName('xl/workbook.xml');

		if ($rels && $wb && preg_match('/<sheet [^>]*r:id="([^"]+)"/', $wb, $m) && preg_match('/<Relationship [^>]*Id="' . preg_quote($m[1], '/') . '"[^>]*Target="([^"]+)"/', $rels, $t)) {
			$sheet = 'xl/' . ltrim(str_replace('/xl/', '', $t[1]), '/');
		}

		$strings = array();
		$ss = $zip->getFromName('xl/sharedStrings.xml');
		$zip->close();

		if ($ss !== false) {
			$r = new XMLReader();
			$r->XML($ss);
			$cur = null;

			while ($r->read()) {
				if ($r->nodeType == XMLReader::ELEMENT && $r->name === 'si') {
					$cur = '';
				} elseif ($r->nodeType == XMLReader::ELEMENT && $r->name === 't' && $cur !== null) {
					$cur .= $r->readString();
				} elseif ($r->nodeType == XMLReader::END_ELEMENT && $r->name === 'si') {
					$strings[] = $cur;
					$cur = null;
				}
			}

			$r->close();
			unset($ss);
		}

		$r = new XMLReader();

		if (!$r->open('zip://' . $xlsx . '#' . $sheet)) {
			return array('error' => 'Не вдалося прочитати аркуш Excel');
		}

		$out = fopen($tsv, 'w');
		$rows = 0;
		$row = null;

		while ($r->read()) {
			if ($r->nodeType == XMLReader::ELEMENT && $r->name === 'row') {
				$row = array();
			} elseif ($r->nodeType == XMLReader::ELEMENT && $r->name === 'c' && $row !== null) {
				$ref = $r->getAttribute('r');
				$type = $r->getAttribute('t');
				$idx = $ref ? $this->colName($ref) : count($row);
				$val = '';

				if (!$r->isEmptyElement) {
					$depth = $r->depth;

					while ($r->read() && !($r->nodeType == XMLReader::END_ELEMENT && $r->name === 'c' && $r->depth == $depth)) {
						if ($r->nodeType == XMLReader::ELEMENT && ($r->name === 'v' || $r->name === 't')) {
							$val .= $r->readString();
						}
					}
				}

				if ($type === 's') {
					$val = isset($strings[(int)$val]) ? $strings[(int)$val] : '';
				}

				$row[$idx] = $val;
			} elseif ($r->nodeType == XMLReader::END_ELEMENT && $r->name === 'row' && $row !== null) {
				if ($row) {
					$max = max(array_keys($row));
					$line = array();

					for ($i = 0; $i <= $max; $i++) {
						$line[] = isset($row[$i]) ? str_replace(array("\t", "\r", "\n"), ' ', $row[$i]) : '';
					}

					fwrite($out, implode("\t", $line) . "\n");
					$rows++;
				}

				$row = null;
			}
		}

		$r->close();
		fclose($out);

		return array('rows' => $rows);
	}

	// CSV from Excel (; or , or tab; UTF-8 or Windows-1251) -> TSV
	public function csvToTsv($csv, $tsv) {
		$in = fopen($csv, 'r');
		$first = fgets($in);
		rewind($in);

		$delim = ';';

		foreach (array("\t", ';', ',') as $d) {
			if (substr_count((string)$first, $d) >= 2) {
				$delim = $d;
				break;
			}
		}

		$out = fopen($tsv, 'w');
		$rows = 0;

		while (($cells = fgetcsv($in, 0, $delim)) !== false) {
			foreach ($cells as &$c) {
				if ($rows === 0) {
					$c = preg_replace('/^\xEF\xBB\xBF/', '', $c);
				}

				if (!mb_check_encoding($c, 'UTF-8')) {
					$c = mb_convert_encoding($c, 'UTF-8', 'Windows-1251');
				}

				$c = str_replace(array("\t", "\r", "\n"), ' ', $c);
			}

			unset($c);
			fwrite($out, implode("\t", $cells) . "\n");
			$rows++;
		}

		fclose($in);
		fclose($out);

		return array('rows' => $rows);
	}

	// Finds columns by header names
	public function mapColumns($header, $local_name) {
		$norm = function ($s) {
			return mb_strtolower(trim(str_replace("\xC2\xA0", ' ', $s)), 'UTF-8');
		};

		$find = function ($names) use ($header, $norm) {
			foreach ($header as $i => $h) {
				if (in_array($norm($h), $names, true)) {
					return $i;
				}
			}

			return -1;
		};

		$map = array(
			'article' => $find(array('артикул', 'код', 'код товару', 'код запчастини', 'article', 'sku', 'номер')),
			'name'    => $find(array('найменування', 'назва', 'наименование', 'название', 'name', 'назва товару')),
			'brand'   => $find(array('бренд', 'виробник', 'производитель', 'brand', 'марка')),
			'price'   => $find(array('ціна', 'цена', 'price', 'ціна, грн', 'ціна грн')),
			'local'   => -1,
			'stock'   => array()
		);

		$skip = array($map['article'], $map['name'], $map['brand'], $map['price'], $find(array('валюта', 'currency')));

		foreach ($header as $i => $h) {
			if (in_array($i, $skip, true) || trim($h) === '') {
				continue;
			}

			$map['stock'][$i] = trim($h);

			if ($norm($h) === $norm($local_name)) {
				$map['local'] = $i;
			}
		}

		return $map;
	}

	private function qty($v) {
		$v = trim(str_replace(array('>', '<', '+', ' '), '', (string)$v));

		if ($v === '' || $v === '-') {
			return 0;
		}

		if (is_numeric(str_replace(',', '.', $v))) {
			return max(0, (int)floor((float)str_replace(',', '.', $v)));
		}

		return 1; // "є", "в наявності"
	}

	// ------------------------------------------------------------ price import: job

	public function jobFile() {
		return $this->workDir() . 'job.json';
	}

	public function getJob() {
		$f = $this->jobFile();

		return is_file($f) ? json_decode(file_get_contents($f), true) : null;
	}

	public function saveJob($job) {
		file_put_contents($this->jobFile(), json_encode($job, JSON_UNESCAPED_UNICODE));
	}

	public function startJob($tsv, $opt) {
		$fh = fopen($tsv, 'r');
		$header = explode("\t", rtrim((string)fgets($fh), "\n"));
		$offset = ftell($fh);
		$total = 0;

		while (fgets($fh) !== false) {
			$total++;
		}

		fclose($fh);

		$map = $this->mapColumns($header, $opt['local']);

		foreach (array('article' => 'Артикул', 'name' => 'Найменування', 'brand' => 'Бренд', 'price' => 'Ціна') as $k => $label) {
			if ($map[$k] < 0) {
				return array('error' => 'У файлі не знайдено колонку «' . $label . '»');
			}
		}

		if (!$map['stock']) {
			return array('error' => 'У файлі не знайдено колонок із залишками складів');
		}

		$job = array(
			'tsv'      => $tsv,
			'offset'   => $offset,
			'total'    => $total,
			'done'     => 0,
			'map'      => $map,
			'opt'      => $opt,
			'started'  => date('Y-m-d H:i:s'),
			'stats'    => array('created' => 0, 'updated' => 0, 'skipped' => 0, 'local' => 0, 'remote' => 0, 'hidden' => 0),
			'finished' => false
		);

		$this->saveJob($job);

		return $job;
	}

	// ------------------------------------------------------------ price import: products

	private $memo = array();

	private function languages() {
		if (!isset($this->memo['lang'])) {
			$this->memo['lang'] = $this->db->query("SELECT language_id, code FROM `" . DB_PREFIX . "language`")->rows;
		}

		return $this->memo['lang'];
	}

	private function manufacturerId($brand) {
		$key = mb_strtolower(trim($brand), 'UTF-8');

		if ($key === '') {
			return 0;
		}

		if (!isset($this->memo['man'])) {
			$this->memo['man'] = array();

			foreach ($this->db->query("SELECT manufacturer_id, name FROM `" . DB_PREFIX . "manufacturer`")->rows as $m) {
				$this->memo['man'][mb_strtolower(trim(html_entity_decode($m['name'], ENT_QUOTES, 'UTF-8')), 'UTF-8')] = (int)$m['manufacturer_id'];
			}
		}

		if (isset($this->memo['man'][$key])) {
			return $this->memo['man'][$key];
		}

		$this->db->query("INSERT INTO `" . DB_PREFIX . "manufacturer` SET name = '" . $this->db->escape($brand) . "', image = '', sort_order = 0");
		$id = (int)$this->db->getLastId();
		$this->db->query("INSERT INTO `" . DB_PREFIX . "manufacturer_to_store` SET manufacturer_id = '" . $id . "', store_id = 0");

		foreach ($this->languages() as $i => $l) {
			$kw = $this->uniqueKeyword($this->slug($brand) . ($i ? '-' . $l['code'] : ''));
			$this->db->query("INSERT INTO `" . DB_PREFIX . "seo_url` SET store_id = 0, language_id = '" . (int)$l['language_id'] . "', query = 'manufacturer_id=" . $id . "', keyword = '" . $this->db->escape($kw) . "'");
		}

		return $this->memo['man'][$key] = $id;
	}

	private function stockStatusId($days) {
		$days = (int)$days;
		$name = 'Під замовлення, ' . $days . ' ' . ($days == 1 ? 'день' : ($days >= 2 && $days <= 4 ? 'дні' : 'днів'));

		if (isset($this->memo['ss'][$name])) {
			return $this->memo['ss'][$name];
		}

		$q = $this->db->query("SELECT stock_status_id FROM `" . DB_PREFIX . "stock_status` WHERE name = '" . $this->db->escape($name) . "' LIMIT 1");

		if ($q->num_rows) {
			$id = (int)$q->row['stock_status_id'];
		} else {
			$id = (int)$this->db->query("SELECT COALESCE(MAX(stock_status_id), 0) + 1 AS id FROM `" . DB_PREFIX . "stock_status`")->row['id'];

			foreach ($this->languages() as $l) {
				$this->db->query("INSERT INTO `" . DB_PREFIX . "stock_status` SET stock_status_id = '" . $id . "', language_id = '" . (int)$l['language_id'] . "', name = '" . $this->db->escape($name) . "'");
			}
		}

		return $this->memo['ss'][$name] = $id;
	}

	// category name => id, creating the tree on first use
	private function categoryIds() {
		if (isset($this->memo['cat'])) {
			return $this->memo['cat'];
		}

		$load = function () {
			$ids = array();
			$q = $this->db->query("SELECT c.category_id, c.parent_id, cd.name FROM `" . DB_PREFIX . "category` c LEFT JOIN `" . DB_PREFIX . "category_description` cd ON (cd.category_id = c.category_id AND cd.language_id = '" . (int)$this->config->get('config_language_id') . "')");

			foreach ($q->rows as $r) {
				$k = $this->catKey((string)$r['name']);

				// prefer the copy inside our tree (with a parent) over a loose top-level one
				if (!isset($ids[$k]) || (int)$r['parent_id'] > 0) {
					$ids[$k] = array((int)$r['category_id'], (int)$r['parent_id']);
				}
			}

			return $ids;
		};

		$ids = $load();
		$tree = $this->categoryPreset();

		if ($tree && !isset($ids[$this->catKey($tree[0]['children'][0])])) {
			$this->createCategories();
			$ids = $load();
		}

		if (!isset($ids[$this->catKey('Інші запчастини')])) {
			$this->addCat('Інші запчастини', 0, 99, true);
			$ids = $load();
		}

		return $this->memo['cat'] = $ids;
	}

	private function rules() {
		if (!isset($this->memo['rules'])) {
			$this->memo['rules'] = array();
			$list = json_decode((string)@file_get_contents(self::dataDir() . 'category_rules.json'), true);

			foreach ((array)$list as $r) {
				$this->memo['rules'][] = array('/' . str_replace('/', '\/', $r[0]) . '/u', $r[1]);
			}
		}

		return $this->memo['rules'];
	}

	private function categoriesFor($name) {
		$ids = $this->categoryIds();
		$low = mb_strtolower($name, 'UTF-8');
		$cat = 'Інші запчастини';

		foreach ($this->rules() as $r) {
			if (@preg_match($r[0], $low)) {
				$cat = $r[1];
				break;
			}
		}

		$k = $this->catKey($cat);

		if (!isset($ids[$k])) {
			$k = $this->catKey('Інші запчастини');
		}

		$out = array($ids[$k][0]);

		if ($ids[$k][1]) {
			$out[] = $ids[$k][1];
		}

		return $out;
	}

	private function productName($name, $brand, $article) {
		$name = trim(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $name)));
		$first = mb_strtolower(strtok($brand, ' '), 'UTF-8');

		// "sato пружина" -> "пружина"; "компресор кондиціонера sato tech" -> "компресор кондиціонера"
		if ($first !== '' && $first !== false) {
			$name = preg_replace('/^' . preg_quote($first, '/') . '(\s+tech)?\s+/iu', '', $name);
			$name = preg_replace('/\s+' . preg_quote(mb_strtolower($brand, 'UTF-8'), '/') . '$/iu', '', $name);
		}

		$name = mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($name, 1, null, 'UTF-8');
		$full = $name;

		if (mb_stripos($full, $brand, 0, 'UTF-8') === false) {
			$full .= ' ' . $brand;
		}

		if (mb_stripos($full, $article, 0, 'UTF-8') === false) {
			$full .= ' ' . $article;
		}

		return mb_substr($full, 0, 250, 'UTF-8');
	}

	private function key($s) {
		return mb_substr(mb_strtoupper(preg_replace('/[\s\-\.\/\\\\]+/u', '', (string)$s), 'UTF-8'), 0, 64, 'UTF-8');
	}

	public function step() {
		$job = $this->getJob();

		if (!$job || $job['finished']) {
			return array('error' => 'Немає активного імпорту');
		}

		@set_time_limit(120);

		$opt = $job['opt'];
		$map = $job['map'];
		$supplier = $opt['supplier'];
		$markup = 1 + (float)$opt['markup'] / 100;
		$wait_status = $this->stockStatusId($opt['days']);
		$now = date('Y-m-d H:i:s');

		$fh = fopen($job['tsv'], 'r');
		fseek($fh, $job['offset']);

		for ($n = 0; $n < self::BATCH && ($line = fgets($fh)) !== false; $n++) {
			$job['done']++;
			$c = explode("\t", rtrim($line, "\n"));
			$article = trim(isset($c[$map['article']]) ? $c[$map['article']] : '');
			$brand = trim(preg_replace('/\s+/u', ' ', isset($c[$map['brand']]) ? $c[$map['brand']] : ''));
			$name = isset($c[$map['name']]) ? $c[$map['name']] : '';
			$price = (float)str_replace(array(',', ' '), array('.', ''), isset($c[$map['price']]) ? $c[$map['price']] : '0');

			if ($article === '' || trim($name) === '' || $price <= 0) {
				$job['stats']['skipped']++;
				continue;
			}

			$stock = array();
			$local = $remote = 0;

			foreach ($map['stock'] as $i => $wh) {
				$q = $this->qty(isset($c[$i]) ? $c[$i] : '');

				if ($q > 0) {
					$stock[$wh] = $q;

					if ((int)$i === (int)$map['local']) {
						$local += $q;
					} else {
						$remote += $q;
					}
				}
			}

			if (!$local && !$remote) {
				$job['stats']['skipped']++;
				continue;
			}

			$sell = round($price * $markup, (int)$opt['round']);
			$qty = $local;
			$status_id = $local ? (int)$this->config->get('config_stock_status_id') : $wait_status;
			$ak = $this->key($article);
			$bk = $this->key($brand);

			$item = $this->db->query("SELECT product_id FROM `" . DB_PREFIX . "vm_catalog_item` WHERE supplier = '" . $this->db->escape($supplier) . "' AND art_key = '" . $this->db->escape($ak) . "' AND brand_key = '" . $this->db->escape($bk) . "'");
			$product_id = $item->num_rows ? (int)$item->row['product_id'] : 0;

			if ($product_id && !$this->db->query("SELECT 1 FROM `" . DB_PREFIX . "product` WHERE product_id = '" . $product_id . "'")->num_rows) {
				$product_id = 0; // product was deleted in admin
			}

			$man_id = $this->manufacturerId($brand);

			if (!$product_id) {
				// a product added by hand earlier with the same article (and brand)
				$q = $this->db->query("SELECT product_id FROM `" . DB_PREFIX . "product` WHERE (model = '" . $this->db->escape($article) . "' OR sku = '" . $this->db->escape($article) . "') AND (manufacturer_id = '" . (int)$man_id . "' OR manufacturer_id = 0) ORDER BY manufacturer_id DESC LIMIT 1");
				$product_id = $q->num_rows ? (int)$q->row['product_id'] : 0;
			}

			if ($product_id) {
				$this->db->query("UPDATE `" . DB_PREFIX . "product` SET price = '" . (float)$sell . "', quantity = '" . (int)$qty . "', stock_status_id = '" . (int)$status_id . "', subtract = 1, status = 1, manufacturer_id = IF(manufacturer_id = 0, '" . (int)$man_id . "', manufacturer_id), date_modified = NOW() WHERE product_id = '" . $product_id . "'");

				if (!$this->db->query("SELECT 1 FROM `" . DB_PREFIX . "product_to_category` WHERE product_id = '" . $product_id . "' LIMIT 1")->num_rows) {
					foreach ($this->categoriesFor($name) as $cid) {
						$this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . "product_to_category` SET product_id = '" . $product_id . "', category_id = '" . (int)$cid . "'");
					}
				}

				$job['stats']['updated']++;
			} else {
				$title = $this->productName($name, $brand, $article);

				$this->db->query("INSERT INTO `" . DB_PREFIX . "product` SET model = '" . $this->db->escape(mb_substr($article, 0, 64)) . "', sku = '" . $this->db->escape(mb_substr($article, 0, 64)) . "', upc = '', ean = '', jan = '', isbn = '', mpn = '" . $this->db->escape(mb_substr($article, 0, 64)) . "', location = '', quantity = '" . (int)$qty . "', minimum = 1, subtract = 1, stock_status_id = '" . (int)$status_id . "', date_available = CURDATE(), manufacturer_id = '" . (int)$man_id . "', shipping = 1, price = '" . (float)$sell . "', points = 0, weight = 0, weight_class_id = '" . (int)$this->config->get('config_weight_class_id') . "', length = 0, width = 0, height = 0, length_class_id = '" . (int)$this->config->get('config_length_class_id') . "', status = 1, tax_class_id = 0, sort_order = 0, image = '', date_added = NOW(), date_modified = NOW()");
				$product_id = (int)$this->db->getLastId();

				$slug = $this->slug($title);

				foreach ($this->languages() as $i => $l) {
					$this->db->query("INSERT INTO `" . DB_PREFIX . "product_description` SET product_id = '" . $product_id . "', language_id = '" . (int)$l['language_id'] . "', name = '" . $this->db->escape($title) . "', description = '', tag = '', meta_title = '" . $this->db->escape(mb_substr($title . ' — купити', 0, 255)) . "', meta_description = '" . $this->db->escape(mb_substr($title . ': ціна, наявність, доставка по Україні.', 0, 255)) . "', meta_keyword = ''");
					$kw = $this->uniqueKeyword($slug . ($i ? '-' . $l['code'] : ''));
					$this->db->query("INSERT INTO `" . DB_PREFIX . "seo_url` SET store_id = 0, language_id = '" . (int)$l['language_id'] . "', query = 'product_id=" . $product_id . "', keyword = '" . $this->db->escape($kw) . "'");
				}

				$this->db->query("INSERT INTO `" . DB_PREFIX . "product_to_store` SET product_id = '" . $product_id . "', store_id = 0");

				foreach ($this->categoriesFor($name) as $cid) {
					$this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . "product_to_category` SET product_id = '" . $product_id . "', category_id = '" . (int)$cid . "'");
				}

				$job['stats']['created']++;
			}

			$job['stats'][$local ? 'local' : 'remote']++;

			$this->db->query("REPLACE INTO `" . DB_PREFIX . "vm_catalog_item` SET supplier = '" . $this->db->escape($supplier) . "', art_key = '" . $this->db->escape($ak) . "', brand_key = '" . $this->db->escape($bk) . "', article = '" . $this->db->escape(mb_substr($article, 0, 64)) . "', brand = '" . $this->db->escape(mb_substr($brand, 0, 64)) . "', product_id = '" . $product_id . "', price_in = '" . (float)$price . "', local_qty = '" . (int)$local . "', remote_qty = '" . (int)$remote . "', stock = '" . $this->db->escape(json_encode($stock, JSON_UNESCAPED_UNICODE)) . "', date_seen = '" . $this->db->escape($now) . "'");
		}

		$job['offset'] = ftell($fh);
		$eof = feof($fh) || $job['done'] >= $job['total'];
		fclose($fh);

		if ($eof) {
			$this->finish($job);
		}

		$this->saveJob($job);

		return $job;
	}

	// products of this supplier that are no longer in the price list: hide
	private function finish(&$job) {
		$supplier = $this->db->escape($job['opt']['supplier']);
		$old = $this->db->query("SELECT product_id FROM `" . DB_PREFIX . "vm_catalog_item` WHERE supplier = '" . $supplier . "' AND date_seen < '" . $this->db->escape($job['started']) . "'");

		foreach ($old->rows as $r) {
			$this->db->query("UPDATE `" . DB_PREFIX . "product` SET quantity = 0, status = 0, date_modified = NOW() WHERE product_id = '" . (int)$r['product_id'] . "'");
		}

		$this->db->query("UPDATE `" . DB_PREFIX . "vm_catalog_item` SET local_qty = 0, remote_qty = 0 WHERE supplier = '" . $supplier . "' AND date_seen < '" . $this->db->escape($job['started']) . "'");

		$job['stats']['hidden'] = $old->num_rows;
		$job['finished'] = true;

		$this->cache->delete('product');
		@unlink($job['tsv']);
	}
}
