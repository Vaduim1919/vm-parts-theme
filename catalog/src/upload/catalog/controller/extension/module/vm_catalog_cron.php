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

		if (!empty($this->request->get['photo_test'])) {
			$e = new VmCatalogEnricher($this->registry);
			$this->response->setOutput("photo test\n" . $e->photoTest() . "\n");

			return;
		}

		$seconds = isset($this->request->get['seconds']) ? max(10, min(280, (int)$this->request->get['seconds'])) : 50;
		$e = new VmCatalogEnricher($this->registry);
		$run = $e->run($seconds, 300);
		$s = $e->stats();

		$this->response->setOutput(date('Y-m-d H:i:s') . ' checked=' . $run['checked'] . ' found=' . $run['found'] . ' notfound=' . $run['notfound'] . ' errors=' . $run['errors'] . ' requests=' . $run['requests'] . (!empty($run['brand_skipped']) ? ' brand_skipped=' . $run['brand_skipped'] : '') . ($run['stopped'] ? ' stop=' . $run['stopped'] : '') . "\ntotal: " . $s['checked'] . '/' . $s['total'] . ' found=' . $s['found'] . ' cars=' . $s['links'] . ' photos=' . $s['images'] . ' photo_fail=' . (isset($s['img_failed']) ? $s['img_failed'] : '-') . "\nversion: " . @trim(file_get_contents(dirname($file) . '/VERSION')) . ' photos_mode=' . $this->config->get('module_vm_catalog_bm_photos') . "\nlast photo error: " . (isset($s['img_error']) && $s['img_error'] !== '' ? $s['img_error'] : '-') . "\n");
	}
}
