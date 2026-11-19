<?php
/**
 * VM Parts theme updater: replaces only the theme's own files, with a backup for rollback.
 * A release is the same zip as the installable ocmod.zip (files under upload/).
 */
class ModelExtensionModuleVmTheme extends Model {
	const MAX_ZIP = 15728640; // 15 MB

	public function getVersion() {
		$file = DIR_CATALOG . 'view/theme/vm_parts/VERSION';

		return is_file($file) ? trim(file_get_contents($file)) : '0.0.0';
	}

	private function backupDir() {
		$storage = defined('DIR_STORAGE') ? DIR_STORAGE : DIR_SYSTEM . 'storage/';
		$dir = $storage . 'backup/vm_theme/';

		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}

		return $dir;
	}

	// ------------------------------------------------------------------ manifest

	public function fetch($url, $max = 1048576) {
		if (!preg_match('#^https://#i', $url) && !preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?/#i', $url)) {
			return array('error' => 'url');
		}

		if (!function_exists('curl_init')) {
			return array('error' => 'curl');
		}

		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS      => 5,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_USERAGENT      => 'VM-Parts-Theme-Updater',
			CURLOPT_SSL_VERIFYPEER => true
		));

		$body = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err = curl_error($ch);
		curl_close($ch);

		if ($body === false || $code >= 400) {
			return array('error' => 'download', 'detail' => $err ? $err : 'HTTP ' . $code);
		}

		if (strlen($body) > $max) {
			return array('error' => 'size');
		}

		return array('body' => $body);
	}

	public function checkManifest($url) {
		// cache-buster: GitHub raw caches files for a few minutes
		$res = $this->fetch($url . (strpos($url, '?') === false ? '?' : '&') . 't=' . time());

		if (isset($res['error'])) {
			return $res;
		}

		$m = json_decode($res['body'], true);

		if (!is_array($m) || empty($m['version']) || empty($m['zip'])) {
			return array('error' => 'manifest');
		}

		// relative zip path -> next to the manifest
		if (!preg_match('#^https?://#i', $m['zip'])) {
			$m['zip'] = preg_replace('#[^/]*$#', '', strtok($url, '?')) . ltrim($m['zip'], '/');
		}

		$current = $this->getVersion();

		return array(
			'current' => $current,
			'version' => (string)$m['version'],
			'notes'   => isset($m['notes']) ? (string)$m['notes'] : '',
			'date'    => isset($m['date']) ? (string)$m['date'] : '',
			'zip'     => (string)$m['zip'],
			'sha256'  => isset($m['sha256']) ? strtolower((string)$m['sha256']) : '',
			'newer'   => version_compare((string)$m['version'], $current, '>')
		);
	}

	// ------------------------------------------------------------------ install a release zip

	// Only the theme's own files may be written; everything else in a zip is refused.
	private function target($path) {
		$path = str_replace('\\', '/', $path);

		if (strpos($path, 'upload/') !== 0) {
			return false;
		}

		$rel = substr($path, 7);

		if ($rel === '' || substr($rel, -1) === '/' || strpos($rel, '..') !== false || strpos($rel, "\0") !== false) {
			return false;
		}

		if (!preg_match('/\.(php|twig|css|js|txt|json|png|jpe?g|gif|svg|webp|woff2?)$/i', $rel) && basename($rel) !== 'VERSION') {
			return false;
		}

		$allowed = array(
			'#^catalog/view/theme/vm_parts/[A-Za-z0-9_\-./]+$#',
			'#^catalog/controller/extension/module/vm_theme\.php$#',
			'#^catalog/language/[a-z]{2}-[a-z]{2}/extension/module/vm_theme\.php$#',
			'#^admin/controller/extension/module/vm_theme(_head\.txt|\.php)$#',
			'#^admin/model/extension/module/vm_theme\.php$#',
			'#^admin/language/[a-z]{2}-[a-z]{2}/extension/module/vm_theme\.php$#',
			'#^admin/view/template/extension/module/vm_theme\.twig$#'
		);

		foreach ($allowed as $re) {
			if (preg_match($re, $rel)) {
				// admin folder may be renamed on the server: map by constant, not by name
				if (strpos($rel, 'admin/') === 0) {
					return array('rel' => $rel, 'abs' => DIR_APPLICATION . substr($rel, 6));
				}

				return array('rel' => $rel, 'abs' => DIR_CATALOG . substr($rel, 8));
			}
		}

		return false;
	}

	public function installZip($file, $expected_sha = '') {
		if (!class_exists('ZipArchive')) {
			return array('error' => 'nozip');
		}

		if (!is_file($file) || filesize($file) > self::MAX_ZIP) {
			return array('error' => 'size');
		}

		if ($expected_sha && !hash_equals($expected_sha, hash_file('sha256', $file))) {
			return array('error' => 'sha');
		}

		$zip = new ZipArchive();

		if ($zip->open($file) !== true) {
			return array('error' => 'badzip');
		}

		$files = array();
		$skipped = array();
		$version = '';

		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);

			if (substr($name, -1) === '/') {
				continue;
			}

			$t = $this->target($name);

			if (!$t) {
				$skipped[] = $name;
				continue;
			}

			$files[] = array('index' => $i, 'rel' => $t['rel'], 'abs' => $t['abs']);

			if ($t['rel'] === 'catalog/view/theme/vm_parts/VERSION') {
				$version = trim($zip->getFromIndex($i));
			}
		}

		$has_core = false;

		foreach ($files as $f) {
			if ($f['rel'] === 'catalog/view/theme/vm_parts/template/common/header.twig') {
				$has_core = true;
			}
		}

		if (!$files || !$version || !$has_core) {
			$zip->close();

			return array('error' => 'notheme');
		}

		// backup of everything that will be overwritten (+ list of new files for rollback)
		$backup = $this->backupDir() . 'backup-' . preg_replace('/[^0-9.]/', '', $this->getVersion()) . '-' . date('Ymd-His') . '.zip';
		$bz = new ZipArchive();

		if ($bz->open($backup, ZipArchive::CREATE) !== true) {
			$zip->close();

			return array('error' => 'backup');
		}

		$created = array();

		foreach ($files as $f) {
			if (is_file($f['abs'])) {
				$bz->addFile($f['abs'], 'upload/' . $f['rel']);
			} else {
				$created[] = $f['rel'];
			}
		}

		$bz->addFromString('vm_theme_backup.json', json_encode(array('version' => $this->getVersion(), 'created' => $created)));
		$bz->close();

		// write
		foreach ($files as $f) {
			$dir = dirname($f['abs']);

			if (!is_dir($dir)) {
				@mkdir($dir, 0755, true);
			}

			$data = $zip->getFromIndex($f['index']);

			if ($data === false || file_put_contents($f['abs'] . '.vmtmp', $data) === false || !@rename($f['abs'] . '.vmtmp', $f['abs'])) {
				$zip->close();
				// best effort: put the old files back
				$this->restoreBackup($backup);

				return array('error' => 'write', 'detail' => $f['rel']);
			}
		}

		$zip->close();

		$this->pruneBackups(5);
		$this->clearTwigCache();
		$this->clearModificationCopies($files);

		if (function_exists('opcache_reset')) {
			@opcache_reset();
		}

		return array('version' => $version, 'files' => count($files), 'skipped' => $skipped);
	}

	// ------------------------------------------------------------------ backups / rollback

	public function getBackups() {
		$list = glob($this->backupDir() . 'backup-*.zip');

		if (!$list) {
			return array();
		}

		rsort($list);

		$out = array();

		foreach ($list as $file) {
			$name = basename($file, '.zip');
			$parts = explode('-', $name);
			$out[] = array('file' => basename($file), 'version' => isset($parts[1]) ? $parts[1] : '', 'date' => isset($parts[2]) ? substr($parts[2], 6, 2) . '.' . substr($parts[2], 4, 2) . '.' . substr($parts[2], 0, 4) . ' ' . (isset($parts[3]) ? substr($parts[3], 0, 2) . ':' . substr($parts[3], 2, 2) : '') : '');
		}

		return $out;
	}

	public function rollback($basename = '') {
		$list = $this->getBackups();

		if (!$list) {
			return array('error' => 'nobackup');
		}

		$file = $list[0]['file'];

		if ($basename) {
			$basename = basename($basename);

			foreach ($list as $b) {
				if ($b['file'] === $basename) {
					$file = $basename;
				}
			}
		}

		$path = $this->backupDir() . $file;
		$ok = $this->restoreBackup($path);

		if ($ok) {
			@unlink($path);
			$this->clearTwigCache();

			if (function_exists('opcache_reset')) {
				@opcache_reset();
			}

			return array('version' => $this->getVersion());
		}

		return array('error' => 'write');
	}

	private function restoreBackup($path) {
		$zip = new ZipArchive();

		if ($zip->open($path) !== true) {
			return false;
		}

		$meta = json_decode((string)$zip->getFromName('vm_theme_backup.json'), true);

		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			$t = $this->target($name);

			if ($t) {
				@file_put_contents($t['abs'], $zip->getFromIndex($i));
			}
		}

		$zip->close();

		if (is_array($meta) && !empty($meta['created'])) {
			foreach ($meta['created'] as $rel) {
				$t = $this->target('upload/' . $rel);

				if ($t && is_file($t['abs'])) {
					@unlink($t['abs']);
				}
			}
		}

		return true;
	}

	private function pruneBackups($keep) {
		$list = glob($this->backupDir() . 'backup-*.zip');

		if ($list && count($list) > $keep) {
			rsort($list);

			foreach (array_slice($list, $keep) as $old) {
				@unlink($old);
			}
		}
	}

	// Other OCMOD extensions may keep their own patched copies of theme files in
	// storage/modification; those copies would hide the files we just installed.
	public function clearModificationCopies($files) {
		if (!defined('DIR_MODIFICATION')) {
			return;
		}

		foreach ($files as $f) {
			$copy = DIR_MODIFICATION . $f['rel'];

			if (is_file($copy)) {
				@unlink($copy);
			}
		}
	}

	public function clearTwigCache() {
		$files = glob(DIR_CACHE . 'template/*/*');

		if ($files) {
			foreach ($files as $file) {
				if (is_file($file)) {
					@unlink($file);
				}
			}
		}
	}

	// ------------------------------------------------------------ categories

	public function categoryPreset() {
		$file = DIR_CATALOG . 'view/theme/vm_parts/data/categories.json';

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
}
