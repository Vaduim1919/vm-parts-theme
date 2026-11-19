<?php
/**
 * V&M Catalog module updater: replaces only the module's own files, with a backup for rollback.
 * A release is the same zip as the installable ocmod.zip (files under upload/).
 */
class ModelExtensionModuleVmCatalogUpdater extends Model {
	const MAX_ZIP = 15728640; // 15 MB

	public function getVersion() {
		$file = DIR_APPLICATION . 'model/extension/module/vm_catalog_data/VERSION';

		return is_file($file) ? trim(file_get_contents($file)) : '0.0.0';
	}

	private function backupDir() {
		$storage = defined('DIR_STORAGE') ? DIR_STORAGE : DIR_SYSTEM . 'storage/';
		$dir = $storage . 'backup/vm_catalog/';

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
			CURLOPT_USERAGENT      => 'VM-Catalog-Updater',
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
			'#^admin/controller/extension/module/vm_catalog\.php$#',
			'#^admin/model/extension/module/vm_catalog(_updater)?\.php$#',
			'#^admin/model/extension/module/vm_catalog_data/[A-Za-z0-9_\-.]+$#',
			'#^admin/language/[a-z]{2}-[a-z]{2}/extension/module/vm_catalog\.php$#',
			'#^admin/view/template/extension/module/vm_catalog\.twig$#'
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

			if ($t['rel'] === 'admin/model/extension/module/vm_catalog_data/VERSION') {
				$version = trim($zip->getFromIndex($i));
			}
		}

		$has_core = false;

		foreach ($files as $f) {
			if ($f['rel'] === 'admin/controller/extension/module/vm_catalog.php') {
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

		$bz->addFromString('vm_catalog_backup.json', json_encode(array('version' => $this->getVersion(), 'created' => $created)));
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

		$meta = json_decode((string)$zip->getFromName('vm_catalog_backup.json'), true);

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
}
