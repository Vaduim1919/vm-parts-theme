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

	private function uniqueKeyword($base, $max = 60) {
		$base = $base !== '' ? rtrim(substr($base, 0, $max), '-') : 'category';
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
		// supplier noise: "_Амортизатор", "(1шт) Амортизатор", "*Фільтр"
		$name = preg_replace('/^(?:[_*.\-\s]+|\(\s*\d+\s*шт\.?\s*\)\s*)+/u', '', $name);
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
				$q = $this->db->query("SELECT product_id FROM `" . DB_PREFIX . "product` WHERE model = '" . $this->db->escape($article) . "' AND (manufacturer_id = '" . (int)$man_id . "' OR manufacturer_id = 0) ORDER BY manufacturer_id DESC LIMIT 1");
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

				// short name + brand + article: readable and practically unique
				$head = $this->slug(trim(str_ireplace(array($brand, $article), '', $title)));

				if (strlen($head) > 50) {
					$head = substr($head, 0, strrpos(substr($head, 0, 50), '-') ?: 50);
				}

				$slug = trim($head . '-' . $this->slug($brand) . '-' . $this->slug($article), '-');

				foreach ($this->languages() as $i => $l) {
					$this->db->query("INSERT INTO `" . DB_PREFIX . "product_description` SET product_id = '" . $product_id . "', language_id = '" . (int)$l['language_id'] . "', name = '" . $this->db->escape($title) . "', description = '', tag = '', meta_title = '" . $this->db->escape(mb_substr($title . ' — купити', 0, 255)) . "', meta_description = '" . $this->db->escape(mb_substr($title . ': ціна, наявність, доставка по Україні.', 0, 255)) . "', meta_keyword = ''");
					$kw = $this->uniqueKeyword($slug . ($i ? '-' . $l['code'] : ''), 120);
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
		$seen = (int)$this->db->query("SELECT COUNT(*) AS n FROM `" . DB_PREFIX . "vm_catalog_item` WHERE supplier = '" . $supplier . "' AND date_seen >= '" . $this->db->escape($job['started']) . "'")->row['n'];
		$missing = (int)$this->db->query("SELECT COUNT(*) AS n FROM `" . DB_PREFIX . "vm_catalog_item` WHERE supplier = '" . $supplier . "' AND date_seen < '" . $this->db->escape($job['started']) . "' AND (local_qty > 0 OR remote_qty > 0)")->row['n'];

		// safety: a cut or partial price list must not switch off the catalogue
		if ($missing > $seen) {
			$job['stats']['hidden'] = 0;
			$job['stats']['guard'] = $missing;
			$job['finished'] = true;
			$this->cache->delete('product');
			@unlink($job['tsv']);

			return;
		}

		$old = $this->db->query("SELECT product_id FROM `" . DB_PREFIX . "vm_catalog_item` WHERE supplier = '" . $supplier . "' AND date_seen < '" . $this->db->escape($job['started']) . "' AND (local_qty > 0 OR remote_qty > 0)");

		foreach ($old->rows as $r) {
			$this->db->query("UPDATE `" . DB_PREFIX . "product` SET quantity = 0, status = 0, date_modified = NOW() WHERE product_id = '" . (int)$r['product_id'] . "'");
		}

		$this->db->query("UPDATE `" . DB_PREFIX . "vm_catalog_item` SET local_qty = 0, remote_qty = 0 WHERE supplier = '" . $supplier . "' AND date_seen < '" . $this->db->escape($job['started']) . "'");

		$job['stats']['hidden'] = $old->num_rows;

		// "show product count next to categories" makes every page count tens of thousands of
		// products per category: with a big price list that takes minutes. Turn it off.
		$job['stats']['count_off'] = 0;

		if ((int)$this->config->get('config_product_count')) {
			$this->db->query("UPDATE `" . DB_PREFIX . "setting` SET value = '0' WHERE store_id = 0 AND `key` = 'config_product_count'");
			$job['stats']['count_off'] = 1;
		}
		$job['finished'] = true;

		$this->cache->delete('product');
		@unlink($job['tsv']);
	}


	// ------------------------------------------------------------ UTR (UniqueTrade) API

	const UTR_API = 'https://order24-api.utr.ua/api/';

	private function utrFingerprint() {
		return 'vm-catalog-' . substr(md5(HTTP_CATALOG), 0, 16);
	}

	private function utrTokenFile() {
		return $this->workDir() . 'utr_token.json';
	}

	public function utrHttp($method, $path, $body = null, $token = '', $save_to = '') {
		if (!function_exists('curl_init')) {
			return array('code' => 0, 'error' => 'На хостингу вимкнено PHP-розширення cURL');
		}

		$url = preg_match('#^https://#', $path) ? $path : self::UTR_API . ltrim($path, '/');
		$ch = curl_init($url);
		$headers = array('Accept: application/json', 'Language: uk');

		if ($token) {
			$headers[] = 'Authorization: Bearer ' . $token;
		}

		$opt = array(
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_TIMEOUT        => $save_to ? 600 : 60,
			CURLOPT_USERAGENT      => 'VM-Catalog/OpenCart',
			CURLOPT_SSL_VERIFYPEER => true
		);

		if ($body !== null) {
			$headers[] = 'Content-Type: application/json';
			$opt[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
		}

		$fh = null;

		if ($save_to) {
			$fh = fopen($save_to, 'w');
			$opt[CURLOPT_FILE] = $fh;
		} else {
			$opt[CURLOPT_RETURNTRANSFER] = true;
		}

		$opt[CURLOPT_HTTPHEADER] = $headers;
		curl_setopt_array($ch, $opt);

		$res = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err = curl_error($ch);
		curl_close($ch);

		if ($fh) {
			fclose($fh);
		}

		if ($res === false) {
			return array('code' => 0, 'error' => 'Немає зв\'язку з UTR: ' . $err);
		}

		return array('code' => $code, 'body' => $save_to ? '' : $res, 'json' => $save_to ? null : json_decode($res, true));
	}

	private function utrLogin($email, $password) {
		$r = $this->utrHttp('POST', 'login_check?browser_fingerprint=' . urlencode($this->utrFingerprint()), array('email' => $email, 'password' => $password));

		if ($r['code'] == 200 && !empty($r['json']['token'])) {
			file_put_contents($this->utrTokenFile(), json_encode($r['json']));

			return $r['json']['token'];
		}

		return array('error' => isset($r['json']['message']) ? $r['json']['message'] : (isset($r['error']) ? $r['error'] : 'Вхід в UTR не вдався (код ' . $r['code'] . ')'));
	}

	private function utrToken($force = false) {
		$email = (string)$this->config->get('module_vm_catalog_utr_email');
		$password = (string)$this->config->get('module_vm_catalog_utr_password');

		if ($email === '' || $password === '') {
			return array('error' => 'Вкажіть логін і пароль UTR і збережіть налаштування');
		}

		$t = is_file($this->utrTokenFile()) ? json_decode(file_get_contents($this->utrTokenFile()), true) : null;

		if (!$force && $t && !empty($t['token']) && !empty($t['expires_at']) && strtotime($t['expires_at']) > time() + 120) {
			return $t['token'];
		}

		if (!$force && $t && !empty($t['refresh_token'])) {
			$r = $this->utrHttp('POST', 'token/refresh', array('refresh_token' => $t['refresh_token'], 'browser_fingerprint' => $this->utrFingerprint()));

			if ($r['code'] == 200 && !empty($r['json']['token'])) {
				file_put_contents($this->utrTokenFile(), json_encode($r['json']));

				return $r['json']['token'];
			}
		}

		return $this->utrLogin($email, $password);
	}

	// authorised call with one re-login on 401
	public function utr($method, $path, $body = null, $save_to = '') {
		$token = $this->utrToken();

		if (is_array($token)) {
			return array('code' => 0, 'error' => $token['error']);
		}

		$r = $this->utrHttp($method, $path, $body, $token, $save_to);

		if ($r['code'] == 401) {
			$token = $this->utrToken(true);

			if (is_array($token)) {
				return array('code' => 0, 'error' => $token['error']);
			}

			$r = $this->utrHttp($method, $path, $body, $token, $save_to);
		}

		return $r;
	}

	// What does UTR give this account? search + the applicability/analogs endpoints
	public function utrProbe() {
		$samples = array();
		$q = $this->db->query("SELECT article, brand FROM `" . DB_PREFIX . "vm_catalog_item` WHERE local_qty > 0 ORDER BY RAND() LIMIT 3");

		foreach ($q->rows as $r) {
			$samples[] = array($r['article'], $r['brand']);
		}

		if (!$samples) {
			$samples = array(array('21819FL', 'SATO TECH'), array('WL7129', 'WIX FILTERS'));
		}

		$out = array('login' => false, 'items' => array());
		$token = $this->utrToken(true);

		if (is_array($token)) {
			$out['error'] = $token['error'];

			return $out;
		}

		$out['login'] = true;

		foreach ($samples as $s) {
			$row = array('article' => $s[0], 'brand' => $s[1]);
			$r = $this->utr('GET', 'search/' . rawurlencode($s[0]) . '?brand=' . rawurlencode($s[1]));
			$row['search'] = $r['code'];

			if ($r['code'] == 200 && !empty($r['json']['details'][0])) {
				$d = $r['json']['details'][0];
				$row['id'] = isset($d['id']) ? $d['id'] : '';
				$row['title'] = isset($d['title']) ? $d['title'] : '';
				$row['category'] = isset($d['category']['name']) ? $d['category']['name'] : '';
				$row['storages'] = isset($d['remains']) ? count($d['remains']) : 0;
				$row['extra'] = implode(', ', array_diff(array_keys($d), array('multiplicity', 'scanCodes', 'isLoyaltyProgram', 'id', 'brand', 'displayBrand', 'article', 'title', 'quantity', 'yourPrice', 'yourPriceUAH', 'yourPriceEUR', 'remains', 'category', 'units', 'modifier')));

				if ($row['id']) {
					$a = $this->utr('GET', 'applicability/' . (int)$row['id']);
					$row['applicability'] = $a['code'] . ($a['code'] == 200 ? ' (' . (is_array($a['json']) ? count($a['json']) : 0) . ')' : '');
				}

				$n = $this->utr('GET', 'analogs/' . rawurlencode($s[1]) . '/' . rawurlencode($s[0]));
				$row['analogs'] = $n['code'] . ($n['code'] == 200 ? ' (' . (is_array($n['json']) ? count($n['json']) : 0) . ')' : '');
			}

			$out['items'][] = $row;
		}

		return $out;
	}

	// ---- price list via API: request -> wait -> download

	public function utrPriceRequest() {
		$r = $this->utr('POST', 'pricelists/export-request', array('format' => 'csv', 'inStock' => true, 'utrArticle' => false, 'showScancode' => false, 'categoriesId' => array(), 'modelsId' => array(), 'visibleBrandsId' => array()));

		if ($r['code'] == 201 && !empty($r['json']['id'])) {
			return array('id' => (int)$r['json']['id']);
		}

		if ($r['code'] == 400) {
			// 3 price lists already queued: reuse the newest one
			$list = $this->utr('GET', 'pricelists');

			if ($list['code'] == 200 && !empty($list['json'][0]['id'])) {
				return array('id' => (int)$list['json'][0]['id']);
			}
		}

		return array('error' => isset($r['error']) ? $r['error'] : (isset($r['json']['message']) ? $r['json']['message'] : 'UTR не прийняв запит на прайс (код ' . $r['code'] . ')'));
	}

	public function utrPriceStatus($id) {
		$r = $this->utr('GET', 'pricelists');

		if ($r['code'] != 200 || !is_array($r['json'])) {
			return array('error' => isset($r['error']) ? $r['error'] : 'UTR: не вдалося отримати список прайсів (код ' . $r['code'] . ')');
		}

		foreach ($r['json'] as $p) {
			if ((int)$p['id'] === (int)$id) {
				return array('status' => $p['status'], 'token' => isset($p['token']) ? $p['token'] : '', 'size' => isset($p['fileSize']) ? $p['fileSize'] : '');
			}
		}

		return array('status' => 'in queue', 'token' => '');
	}

	public function utrPriceDownload($token) {
		$file = $this->workDir() . 'source.csv';
		$r = $this->utr('GET', 'pricelists/export/' . rawurlencode($token), null, $file);

		if ($r['code'] != 200 || !is_file($file) || filesize($file) < 100) {
			@unlink($file);

			return array('error' => isset($r['error']) ? $r['error'] : 'Не вдалося скачати прайс з UTR (код ' . $r['code'] . ')');
		}

		return array('file' => $file);
	}


	// ------------------------------------------------------------ BM Parts API

	const BM_API = 'https://api.bm.parts/';

	public function bm($method, $path, $query = array(), $body = null) {
		$token = trim((string)$this->config->get('module_vm_catalog_bm_token'));

		if ($token === '') {
			return array('code' => 0, 'json' => null, 'error' => 'Вкажіть API-токен BM Parts і збережіть налаштування');
		}

		$url = self::BM_API . ltrim($path, '/');

		if ($query) {
			// repeated keys (cars=..&cars=..) are allowed
			$parts = array();

			foreach ($query as $k => $v) {
				foreach ((array)$v as $one) {
					$parts[] = rawurlencode($k) . '=' . rawurlencode($one);
				}
			}

			$url .= '?' . implode('&', $parts);
		}

		$headers = array('Accept: application/json', 'Authorization: ' . $token, 'Accept-Language: uk');

		$ch = curl_init($url);
		$opt = array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_USERAGENT      => 'VM-Catalog vmautoparts.com.ua',
			CURLOPT_SSL_VERIFYPEER => true
		);

		if ($body !== null) {
			$headers[] = 'Content-Type: application/json';
			$opt[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
		}

		$opt[CURLOPT_HTTPHEADER] = $headers;
		curl_setopt_array($ch, $opt);
		$res = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err = curl_error($ch);
		curl_close($ch);

		if ($res === false) {
			return array('code' => 0, 'json' => null, 'error' => 'Немає зв\'язку з BM Parts: ' . $err);
		}

		return array('code' => $code, 'json' => json_decode($res, true), 'body' => $res);
	}

	// short description of a JSON value: keys, sizes and short scalar values
	private function shape($v, $depth = 0) {
		if (is_array($v)) {
			if (!$v) {
				return '(порожньо)';
			}

			$list = array_keys($v) === range(0, count($v) - 1);

			if ($list) {
				return '[' . count($v) . '] ' . ($depth < 5 ? $this->shape(reset($v), $depth + 1) : '');
			}

			if ($depth >= 4) {
				return '{' . implode(', ', array_slice(array_keys($v), 0, 15)) . '}';
			}

			$out = array();
			$i = 0;

			foreach ($v as $k => $x) {
				if (++$i > 25) {
					$out[] = '… ще ' . (count($v) - 25);
					break;
				}

				$out[] = $k . ': ' . $this->shape($x, $depth + 1);
			}

			return '{' . implode(', ', $out) . '}';
		}

		if (is_string($v)) {
			return '"' . mb_substr($v, 0, 40) . (mb_strlen($v) > 40 ? '…' : '') . '"';
		}

		return var_export($v, true);
	}

	private function firstProduct($json) {
		if (!is_array($json)) {
			return null;
		}

		foreach (array('products', 'data', 'items', 'result') as $k) {
			if (!empty($json[$k]) && is_array($json[$k])) {
				$list = $json[$k];
				$first = reset($list);

				return is_array($first) ? array(key($list), $first) : null;
			}
		}

		return null;
	}

	public function bmProbe($user_q = '') {
		$out = array('steps' => array());
		$add = function ($name, $r) use (&$out) {
			$out['steps'][] = array(
				'name'  => $name,
				'code'  => $r['code'],
				'error' => isset($r['error']) ? $r['error'] : ($r['code'] != 200 && isset($r['json']['message']) ? (is_string($r['json']['message']) ? $r['json']['message'] : json_encode($r['json']['message'], JSON_UNESCAPED_UNICODE)) : ''),
				'shape' => isset($r['json']) && $r['code'] == 200 ? mb_substr($this->shape($r['json']), 0, 2500) : mb_substr((string)(isset($r['body']) ? $r['body'] : ''), 0, 300)
			);
		};

		$r = $this->bm('GET', 'catalog/cars/brands/');
		$add('Марки авто (catalog/cars/brands)', $r);

		if ($r['code'] != 200) {
			return $out;
		}

		$car = 'VOLKSWAGEN';

		if (!empty($r['json']['car_brands'][0])) {
			$c = $r['json']['car_brands'][0];
			$car = is_array($c) ? (isset($c['name']) ? $c['name'] : (string)reset($c)) : (string)$c;
		}

		$tries = array();
		$sample = $this->db->query("SELECT article FROM `" . DB_PREFIX . "vm_catalog_item` WHERE local_qty > 0 ORDER BY RAND() LIMIT 1")->row;

		foreach (array_filter(array(trim($user_q), $sample ? $sample['article'] : '', '21819FL', 'ADM543123', 'OC90')) as $q) {
			$tries[$q] = true;
		}

		$uuid = '';

		foreach (array_keys($tries) as $q) {
			$r = $this->bm('GET', 'search/products', array('q' => $q, 'products_as' => 'obj', 'per_page' => 3, 'search_mode' => 'extended'));
			$add('Пошук «' . $q . '»', $r);

			if (!$uuid && !empty($r['json']['products']) && is_array($r['json']['products'])) {
				$first = reset($r['json']['products']);
				$key = key($r['json']['products']);
				$uuid = is_array($first) && !empty($first['uuid']) ? $first['uuid'] : (is_string($key) ? $key : '');
			}
		}

		$r = $this->bm('GET', 'search/products', array('cars' => $car, 'products_as' => 'obj', 'per_page' => 3));
		$add('Товари для марки «' . $car . '» (cars=' . $car . ', без q)', $r);

		if (!$uuid && !empty($r['json']['products']) && is_array($r['json']['products'])) {
			$first = reset($r['json']['products']);
			$key = key($r['json']['products']);
			$uuid = is_array($first) && !empty($first['uuid']) ? $first['uuid'] : (is_string($key) ? $key : '');
		}

		$r = $this->bm('GET', 'search/products/aggregations/cars', array('q' => 'амортизатор'));
		$add('Агрегація авто для «амортизатор»', $r);

		if ($uuid) {
			$r = $this->bm('GET', 'product/' . rawurlencode($uuid), array('products_as' => 'obj', 'oe' => 'short', 'output_field' => 'all', 'with_extra' => 'true'));
			$add('Картка товару ' . $uuid, $r);

			$r = $this->bm('GET', 'product/' . rawurlencode($uuid) . '/images');
			$add('Фото товару', $r);
		}

		return $out;
	}
}
