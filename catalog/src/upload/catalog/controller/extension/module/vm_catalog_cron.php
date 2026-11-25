<?php
/**
 * V&M Catalog — background work for hosting cron:
 * index.php?route=extension/module/vm_catalog_cron&key=SECRET
 * Enriches products with BM Parts data for ~50 seconds per call.
 */
class ControllerExtensionModuleVmCatalogCron extends Controller {
	public function index() {
		$this->response->addHeader('Content-Type: text/plain; charset=utf-8');
		$this->response->addHeader('X-Robots-Tag: noindex');

		$key = (string)$this->config->get('module_vm_catalog_cron_key');
		$given = isset($this->request->get['key']) ? (string)$this->request->get['key'] : '';

		if (strlen($key) < 24 || !hash_equals($key, $given)) {
			$this->response->addHeader($this->request->server['SERVER_PROTOCOL'] . ' 403 Forbidden');
			$this->response->setOutput('forbidden');

			return;
		}

		$file = rtrim((string)$this->config->get('module_vm_catalog_admin_dir'), '/') . '/model/extension/module/vm_catalog_data/enricher.php';

		if (!is_file($file)) {
			$this->response->setOutput('enricher not found: open the module page in admin once');

			return;
		}

		require_once $file;

		// one cron run at a time: overlapping runs pile up PHP processes and the hosting answers 508
		$lockdir = DIR_STORAGE . 'upload/vm_catalog/';

		if (!is_dir($lockdir)) {
			@mkdir($lockdir, 0755, true);
		}

		$cron_lock = @fopen($lockdir . 'cron.lock', 'c');

		if (!$cron_lock || !flock($cron_lock, LOCK_EX | LOCK_NB)) {
			$this->response->setOutput("busy: previous run still working\n");

			return;
		}

		@set_time_limit(320);

		if (!empty($this->request->get['photo_test'])) {
			$e = new VmCatalogEnricher($this->registry);
			$this->response->setOutput("photo test\n" . $e->photoTest() . "\n");

			return;
		}

		$seconds = isset($this->request->get['seconds']) ? max(10, min(280, (int)$this->request->get['seconds'])) : 50;

		// photos from the UTR YML first (no API limits), then BM Parts with the rest of the time
		$photo_line = '';
		$yfile = dirname($file) . '/yml.php';

		if (is_file($yfile)) {
			require_once $yfile;
			$y = new VmCatalogYml($this->registry);
			$ys = $y->photoStats();

			if ($ys['pending'] > 0) {
				$t0 = microtime(true);
				$yr = $y->runPhotos((int)round($seconds * 0.5));
				$seconds = max(10, $seconds - (int)round(microtime(true) - $t0));
				$ys = $y->photoStats();
			}

			$photo_line = "\nutr photos: done=" . $ys['done'] . ' pending=' . $ys['pending'] . ' failed=' . $ys['failed'] . (isset($yr) ? ' this_run=' . $yr['done'] : '') . ($ys['error'] !== '' ? ' last_error=' . $ys['error'] : '');
		}

		// 1.8.1: re-read stored supplier fitment once (years from engines, engine links); no API calls
		$rebuild_line = '';
		$e0 = new VmCatalogEnricher($this->registry);
		$e0->install();
		$rb = $e0->rebuildCars(0); // status only

		if (empty($rb['finished'])) {
			$t0 = microtime(true);
			$rb = $e0->rebuildCars(min(20, (int)round($seconds * 0.4)));
			$seconds = max(10, $seconds - (int)round(microtime(true) - $t0));
			$rebuild_line = "\ncars rebuild: " . $rb['done'] . '/' . (isset($rb['total']) ? $rb['total'] : '?') . (!empty($rb['finished']) ? ' finished' : '');
		}

		// AUTO.RIA car reference: a short step when due (first import or monthly refresh)
		$ria_line = '';
		$rfile = dirname($file) . '/ria.php';

		if (is_file($rfile)) {
			require_once $rfile;
			$r = new VmCatalogRia($this->registry);

			if ($r->due()) {
				$t0 = microtime(true);
				$rj = $r->step(min(15, (int)round($seconds * 0.3)));
				$seconds = max(10, $seconds - (int)round(microtime(true) - $t0));
				$rs = $r->stats();
				$ria_line = "
auto.ria: stage=" . $rj['stage'] . ' left=' . $rs['left'] . ' gens=' . $rs['gens'] . ($rj['error'] !== '' ? ' error=' . $rj['error'] : '');
			}
		}

		$e = new VmCatalogEnricher($this->registry);
		$run = $e->run($seconds, 300);
		$s = $e->stats();

		$this->response->setOutput(date('Y-m-d H:i:s') . ' checked=' . $run['checked'] . ' found=' . $run['found'] . ' notfound=' . $run['notfound'] . ' errors=' . $run['errors'] . ' requests=' . $run['requests'] . (!empty($run['brand_skipped']) ? ' brand_skipped=' . $run['brand_skipped'] : '') . ($run['stopped'] ? ' stop=' . $run['stopped'] : '') . "\ntotal: " . $s['checked'] . '/' . $s['total'] . ' found=' . $s['found'] . ' cars=' . $s['links'] . ' photos=' . $s['images'] . ' photo_fail=' . (isset($s['img_failed']) ? $s['img_failed'] : '-') . "\nversion: " . @trim(file_get_contents(dirname($file) . '/VERSION')) . ' photos_mode=' . $this->config->get('module_vm_catalog_bm_photos') . "\nlast photo error: " . (isset($s['img_error']) && $s['img_error'] !== '' ? $s['img_error'] : '-') . $photo_line . $ria_line . $rebuild_line . "\n");
	}
}
