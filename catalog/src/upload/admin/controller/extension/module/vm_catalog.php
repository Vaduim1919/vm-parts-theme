<?php
/**
 * V&M Catalog — категорії та імпорт прайсів постачальників (OpenCart 3).
 */
class ControllerExtensionModuleVmCatalog extends Controller {
	const DEFAULT_UPDATE_URL = 'https://raw.githubusercontent.com/Vaduim1919/vm-parts-theme/main/catalog/latest.json';

	private $route = 'extension/module/vm_catalog';

	private function link($action = '', $args = '') {
		return $this->url->link($this->route . ($action ? '/' . $action : ''), 'user_token=' . $this->session->data['user_token'] . $args, true);
	}

	private function ajaxUrl($action) {
		return 'index.php?route=' . $this->route . '/' . $action . '&user_token=' . $this->session->data['user_token'];
	}

	private function defaults() {
		return array(
			'module_vm_catalog_status'     => 1,
			'module_vm_catalog_supplier'   => 'utr',
			'module_vm_catalog_local'      => 'Вінницька обл.',
			'module_vm_catalog_days'       => 1,
			'module_vm_catalog_site_local' => 'Склад Вінниця',
			'module_vm_catalog_site_remote' => 'Інші склади',
			'module_vm_catalog_markup'     => 30,
			'module_vm_catalog_round'      => 0,
			'module_vm_catalog_update_url' => self::DEFAULT_UPDATE_URL,
			'module_vm_catalog_utr_email'  => '',
			'module_vm_catalog_utr_password' => '',
			'module_vm_catalog_bm_token'   => '',
			'module_vm_catalog_bm_photos'  => 'all',
			'module_vm_catalog_cron_key'   => '',
			'module_vm_catalog_admin_dir'  => ''
		);
	}

	private function settings() {
		$out = array();

		foreach ($this->defaults() as $k => $v) {
			$cur = $this->config->get($k);
			$out[$k] = ($cur === null || $cur === '') ? $v : $cur;
		}

		return $out;
	}

	private function cronKey() {
		$k = (string)$this->config->get('module_vm_catalog_cron_key');

		return strlen($k) >= 24 ? $k : bin2hex(function_exists('random_bytes') ? random_bytes(16) : md5(uniqid('', true), true));
	}

	private function canModify() {
		return $this->user->hasPermission('modify', $this->route);
	}

	public function index() {
		$this->load->language($this->route);
		$this->load->model($this->route);
		$this->load->model($this->route . '_updater');

		// tables appear on install; also on the first open after a module update
		$this->model_extension_module_vm_catalog->install();

		if (!$this->config->get('module_vm_catalog_photos_v2')) {
			// 1.3.5: photos for products from every warehouse (owner's request)
			$this->load->model('setting/setting');
			$this->model_setting_setting->editSetting('module_vm_catalog', $this->cleanOptions(array_merge($this->settings(), array('module_vm_catalog_bm_photos' => 'all'))));
			$this->db->query("UPDATE `" . DB_PREFIX . "vm_catalog_enrich` SET images = 0 WHERE images < 0");
			$this->response->redirect($this->link());
		}

		if ((string)$this->config->get('module_vm_catalog_site_local') === '') {
			// 1.3.1: on-order delivery is 1 day; switch products already imported with the old 2-day status
			$this->load->model('setting/setting');
			$opt = $this->cleanOptions(array_merge($this->settings(), array('module_vm_catalog_days' => 1, 'module_vm_catalog_site_local' => 'Склад Вінниця', 'module_vm_catalog_site_remote' => 'Інші склади')));
			$this->model_setting_setting->editSetting('module_vm_catalog', $opt);
			$this->model_extension_module_vm_catalog->switchWaitStatus(2, 1);
			$this->response->redirect($this->link());
		}

		$this->document->setTitle($this->language->get('heading_title'));

		$data = $this->settings();

		foreach (array('success', 'vm_catalog_error') as $k) {
			$data[$k] = isset($this->session->data[$k]) ? $this->session->data[$k] : '';
			unset($this->session->data[$k]);
		}

		$data['update_info'] = isset($this->session->data['vm_catalog_update']) ? $this->session->data['vm_catalog_update'] : null;

		$data['breadcrumbs'] = array(
			array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)),
			array('text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true)),
			array('text' => $this->language->get('heading_title'), 'href' => $this->link())
		);

		$data['version'] = $this->model_extension_module_vm_catalog_updater->getVersion();
		$data['backups'] = $this->model_extension_module_vm_catalog_updater->getBackups();
		$data['stats'] = $this->model_extension_module_vm_catalog->stats();
		$data['job'] = $this->model_extension_module_vm_catalog->getJob();
		$data['stock_checkout'] = (int)$this->config->get('config_stock_checkout');

		$tree = $this->model_extension_module_vm_catalog->categoryPreset();
		$data['cat_preset'] = $tree;
		$data['cat_total'] = 0;

		foreach ($tree as $root) {
			$data['cat_total'] += 1 + count($root['children']);
		}

		$data['utr_password_set'] = (string)$this->config->get('module_vm_catalog_utr_password') !== '';
		$data['module_vm_catalog_utr_password'] = '';
		$data['utr_save'] = $this->link('saveUtr');
		$data['bm_token_set'] = (string)$this->config->get('module_vm_catalog_bm_token') !== '';
		$data['module_vm_catalog_bm_token'] = '';
		$data['bm_probe_url'] = $this->ajaxUrl('bmProbe');
		$data['enrich_url'] = $this->ajaxUrl('enrich');
		$data['enrich_stats'] = $this->model_extension_module_vm_catalog->enricher()->stats();
		$data['enrich_sample'] = $this->model_extension_module_vm_catalog->enricher()->sample();

		// cron needs the key and the (possibly renamed) admin folder in settings
		if (strlen((string)$this->config->get('module_vm_catalog_cron_key')) < 24 || (string)$this->config->get('module_vm_catalog_admin_dir') !== DIR_APPLICATION) {
			$this->load->model('setting/setting');
			$this->model_setting_setting->editSetting('module_vm_catalog', $this->cleanOptions($this->settings()));
			$data['module_vm_catalog_cron_key'] = $this->config->get('module_vm_catalog_cron_key');
			$saved = $this->model_setting_setting->getSetting('module_vm_catalog');
			$data['module_vm_catalog_cron_key'] = isset($saved['module_vm_catalog_cron_key']) ? $saved['module_vm_catalog_cron_key'] : '';
		}

		$data['cron_url'] = (defined('HTTPS_CATALOG') ? HTTPS_CATALOG : HTTP_CATALOG) . 'index.php?route=extension/module/vm_catalog_cron&key=' . $data['module_vm_catalog_cron_key'];
		$data['cron_installed'] = is_file(DIR_CATALOG . 'controller/extension/module/vm_catalog_cron.php');
		$data['utr_probe_url'] = $this->ajaxUrl('utrProbe');
		$data['utr_price_url'] = $this->ajaxUrl('utrPrice');
		$data['upload_url'] = $this->ajaxUrl('upload');
		$data['step_url'] = $this->ajaxUrl('step');
		$data['categories_action'] = $this->link('categories');
		$data['categorize_url'] = $this->ajaxUrl('categorize');
		// markups per supplier and brand
		$rules = $this->model_extension_module_vm_catalog->markupRules();
		$suppliers = array();

		foreach ((array)$this->model_extension_module_vm_catalog->stats() as $st) {
			$suppliers[$st['supplier']] = array('supplier' => $st['supplier'], 'total' => (int)$st['total'], 'markup' => '');
		}

		$brand_rules = array();

		foreach ($rules as $r) {
			if ($r['brand_key'] === '') {
				if (!isset($suppliers[$r['supplier']])) {
					$suppliers[$r['supplier']] = array('supplier' => $r['supplier'], 'total' => 0, 'markup' => '');
				}

				$suppliers[$r['supplier']]['markup'] = rtrim(rtrim($r['markup'], '0'), '.');
			} else {
				$brand_rules[] = array('supplier' => $r['supplier'], 'brand' => $r['brand'], 'markup' => rtrim(rtrim($r['markup'], '0'), '.'));
			}
		}

		$data['markup_suppliers'] = array_values($suppliers);
		$data['markup_brands'] = $brand_rules;
		$data['markup_save_url'] = $this->ajaxUrl('markupSave');
		$data['markup_brands_url'] = $this->ajaxUrl('markupBrands');
		$data['markup_recalc_url'] = $this->ajaxUrl('markupRecalc');
		$data['checkout_action'] = $this->link('stockCheckout');
		$data['check_action'] = $this->link('checkUpdate');
		$data['update_action'] = $this->link('update');
		$data['rollback'] = $this->link('rollback');
		$data['save_action'] = $this->link('save');
		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true);

		$data['upload_max'] = ini_get('upload_max_filesize');
		$data['text_count_off_js'] = $this->language->get('text_count_off');

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view($this->route, $data));
	}

	private function cleanOptions($src) {
		$d = $this->defaults();
		$get = function ($k) use ($src, $d) {
			return isset($src[$k]) ? html_entity_decode(trim($src[$k]), ENT_QUOTES, 'UTF-8') : $d[$k];
		};

		$supplier = preg_replace('/[^a-z0-9_\-]/', '', mb_strtolower($get('module_vm_catalog_supplier')));

		return array(
			'module_vm_catalog_status'     => 1,
			'module_vm_catalog_supplier'   => $supplier !== '' ? substr($supplier, 0, 32) : 'price',
			'module_vm_catalog_local'      => mb_substr($get('module_vm_catalog_local'), 0, 64),
			'module_vm_catalog_days'       => max(1, min(60, (int)$get('module_vm_catalog_days'))),
			'module_vm_catalog_site_local' => (isset($src['module_vm_catalog_site_local']) ? mb_substr($get('module_vm_catalog_site_local'), 0, 64) : (string)$this->config->get('module_vm_catalog_site_local')) ?: 'Склад Вінниця',
			'module_vm_catalog_site_remote' => (isset($src['module_vm_catalog_site_remote']) ? mb_substr($get('module_vm_catalog_site_remote'), 0, 64) : (string)$this->config->get('module_vm_catalog_site_remote')) ?: 'Інші склади',
			'module_vm_catalog_markup'     => max(-90, min(500, (float)str_replace(',', '.', $get('module_vm_catalog_markup')))),
			'module_vm_catalog_round'      => in_array((int)$get('module_vm_catalog_round'), array(0, 2), true) ? (int)$get('module_vm_catalog_round') : 0,
			'module_vm_catalog_update_url' => preg_match('#^https://#i', $get('module_vm_catalog_update_url')) ? $get('module_vm_catalog_update_url') : self::DEFAULT_UPDATE_URL,
			'module_vm_catalog_utr_email'  => isset($src['module_vm_catalog_utr_email']) ? mb_substr($get('module_vm_catalog_utr_email'), 0, 128) : (string)$this->config->get('module_vm_catalog_utr_email'),
			// empty password field = keep the saved one
			'module_vm_catalog_utr_password' => !empty($src['module_vm_catalog_utr_password']) ? $get('module_vm_catalog_utr_password') : (string)$this->config->get('module_vm_catalog_utr_password'),
			'module_vm_catalog_bm_token'   => !empty($src['module_vm_catalog_bm_token']) ? trim($get('module_vm_catalog_bm_token')) : (string)$this->config->get('module_vm_catalog_bm_token'),
			'module_vm_catalog_bm_photos'  => isset($src['module_vm_catalog_bm_photos']) && in_array($src['module_vm_catalog_bm_photos'], array('none', 'local', 'all'), true) ? $src['module_vm_catalog_bm_photos'] : ((string)$this->config->get('module_vm_catalog_bm_photos') ?: 'all'),
			'module_vm_catalog_photos_v2'  => 1,
			'module_vm_catalog_cron_key'   => $this->cronKey(),
			'module_vm_catalog_admin_dir'  => DIR_APPLICATION,
			'module_vm_catalog_bm_img_pattern' => (string)$this->config->get('module_vm_catalog_bm_img_pattern')
		);
	}

	public function save() {
		$this->load->language($this->route);

		if ($this->request->server['REQUEST_METHOD'] == 'POST' && $this->canModify()) {
			$this->load->model('setting/setting');
			$this->model_setting_setting->editSetting('module_vm_catalog', $this->cleanOptions($this->request->post));
			$this->session->data['success'] = $this->language->get('text_success');
		} else {
			$this->session->data['vm_catalog_error'] = $this->language->get('error_permission');
		}

		$this->response->redirect($this->link());
	}

	// ---------------------------------------------------------------- UTR

	public function saveUtr() {
		$this->load->language($this->route);

		if ($this->request->server['REQUEST_METHOD'] == 'POST' && $this->canModify()) {
			$this->load->model('setting/setting');
			$opt = $this->cleanOptions(array_merge($this->settings(), $this->request->post));
			$this->model_setting_setting->editSetting('module_vm_catalog', $opt);
			$this->load->model($this->route);
			@unlink($this->model_extension_module_vm_catalog->workDir() . 'utr_token.json');
			$this->session->data['success'] = $this->language->get('text_success');
		} else {
			$this->session->data['vm_catalog_error'] = $this->language->get('error_permission');
		}

		$this->response->redirect($this->link());
	}

	// one batch of BM enrichment (the admin page calls it in a loop)
	public function enrich() {
		$this->load->language($this->route);
		$this->load->model($this->route);

		if (!$this->canModify() || !$this->user->hasPermission('modify', 'catalog/product')) {
			return $this->json(array('error' => $this->language->get('error_permission')));
		}

		$e = $this->model_extension_module_vm_catalog->enricher();
		$run = $e->run(40, 400);
		$this->json(array('run' => $run, 'stats' => $e->stats()));
	}

	public function bmProbe() {
		$this->load->language($this->route);
		$this->load->model($this->route);

		if (!$this->canModify()) {
			return $this->json(array('error' => $this->language->get('error_permission')));
		}

		$this->json($this->model_extension_module_vm_catalog->bmProbe(isset($this->request->get['q']) ? mb_substr(html_entity_decode($this->request->get['q'], ENT_QUOTES, 'UTF-8'), 0, 64) : ''));
	}

	public function utrProbe() {
		$this->load->language($this->route);
		$this->load->model($this->route);

		if (!$this->canModify()) {
			return $this->json(array('error' => $this->language->get('error_permission')));
		}

		$this->json($this->model_extension_module_vm_catalog->utrProbe());
	}

	// step=request|status|import : the page calls it in a loop
	public function utrPrice() {
		$this->load->language($this->route);
		$this->load->model($this->route);

		if (!$this->canModify() || !$this->user->hasPermission('modify', 'catalog/product')) {
			return $this->json(array('error' => $this->language->get('error_permission')));
		}

		$m = $this->model_extension_module_vm_catalog;
		$step = isset($this->request->get['step']) ? $this->request->get['step'] : 'request';

		if ($step === 'request') {
			return $this->json($m->utrPriceRequest());
		}

		$id = isset($this->request->get['id']) ? (int)$this->request->get['id'] : 0;
		$st = $m->utrPriceStatus($id);

		if (isset($st['error']) || $step === 'status' || $st['status'] !== 'complete') {
			return $this->json($st);
		}

		// import: download and start the usual job with the saved options
		@set_time_limit(600);
		@ini_set('memory_limit', '512M');

		$dl = $m->utrPriceDownload($st['token']);

		if (isset($dl['error'])) {
			return $this->json($dl);
		}

		$tsv = $m->workDir() . 'current.tsv';
		$res = $m->csvToTsv($dl['file'], $tsv);
		@unlink($dl['file']);

		if (isset($res['error'])) {
			return $this->json($res);
		}

		$s = $this->settings();
		$job = $m->startJob($tsv, array(
			'supplier' => $s['module_vm_catalog_supplier'],
			'local'    => $s['module_vm_catalog_local'],
			'days'     => $s['module_vm_catalog_days'],
			'markup'   => $s['module_vm_catalog_markup'],
			'round'    => $s['module_vm_catalog_round'],
			'file'     => 'UTR API'
		));

		if (isset($job['error'])) {
			return $this->json($job);
		}

		$this->json(array('status' => 'started', 'total' => $job['total']));
	}

	// ---------------------------------------------------------------- import

	private function json($data) {
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($data, JSON_UNESCAPED_UNICODE));
	}

	public function upload() {
		$this->load->language($this->route);
		$this->load->model($this->route);

		if (!$this->canModify() || !$this->user->hasPermission('modify', 'catalog/product')) {
			return $this->json(array('error' => $this->language->get('error_permission')));
		}

		if ($this->request->server['REQUEST_METHOD'] != 'POST' || empty($this->request->files['file']['tmp_name']) || !is_uploaded_file($this->request->files['file']['tmp_name'])) {
			$err = isset($this->request->files['file']['error']) ? (int)$this->request->files['file']['error'] : 4;

			return $this->json(array('error' => $err == 1 || $err == 2 ? sprintf($this->language->get('error_too_big'), ini_get('upload_max_filesize')) : $this->language->get('error_no_file')));
		}

		@set_time_limit(300);
		@ini_set('memory_limit', '512M');

		$opt = $this->cleanOptions($this->request->post);
		$this->load->model('setting/setting');
		$this->model_setting_setting->editSetting('module_vm_catalog', $opt);

		$name = $this->request->files['file']['name'];
		$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		$dir = $this->model_extension_module_vm_catalog->workDir();
		$src = $dir . 'source.' . ($ext === 'xlsx' ? 'xlsx' : 'csv');
		$tsv = $dir . 'current.tsv';

		if (!in_array($ext, array('xlsx', 'csv', 'txt'), true)) {
			return $this->json(array('error' => $this->language->get('error_type')));
		}

		move_uploaded_file($this->request->files['file']['tmp_name'], $src);

		$res = $ext === 'xlsx' ? $this->model_extension_module_vm_catalog->xlsxToTsv($src, $tsv) : $this->model_extension_module_vm_catalog->csvToTsv($src, $tsv);
		@unlink($src);

		if (isset($res['error'])) {
			return $this->json($res);
		}

		$job = $this->model_extension_module_vm_catalog->startJob($tsv, array(
			'supplier' => $opt['module_vm_catalog_supplier'],
			'local'    => $opt['module_vm_catalog_local'],
			'days'     => $opt['module_vm_catalog_days'],
			'markup'   => $opt['module_vm_catalog_markup'],
			'round'    => $opt['module_vm_catalog_round'],
			'file'     => $name
		));

		if (isset($job['error'])) {
			@unlink($tsv);

			return $this->json($job);
		}

		$warning = $job['map']['local'] < 0 ? sprintf($this->language->get('warning_no_local'), $opt['module_vm_catalog_local'], implode(', ', $job['map']['stock'])) : '';

		$this->json(array('total' => $job['total'], 'warehouses' => array_values($job['map']['stock']), 'warning' => $warning));
	}

	public function step() {
		$this->load->model($this->route);
		$this->load->language($this->route);

		if (!$this->canModify()) {
			return $this->json(array('error' => $this->language->get('error_permission')));
		}

		$job = $this->model_extension_module_vm_catalog->step();

		if (isset($job['error'])) {
			return $this->json($job);
		}

		$this->json(array('done' => $job['done'], 'total' => $job['total'], 'finished' => $job['finished'], 'stats' => $job['stats']));
	}

	public function categories() {
		$this->load->language($this->route);
		$this->load->model($this->route);

		if ($this->request->server['REQUEST_METHOD'] !== 'POST' || !$this->canModify() || !$this->user->hasPermission('modify', 'catalog/category')) {
			$this->session->data['vm_catalog_error'] = $this->language->get('error_permission');
		} else {
			$res = $this->model_extension_module_vm_catalog->createCategories();

			if (isset($res['error'])) {
				$this->session->data['vm_catalog_error'] = $this->language->get('text_cat_error');
			} else {
				$this->session->data['success'] = sprintf($this->language->get('text_cat_done'), $res['created'], $res['existing'], $res['moved']);
			}
		}

		$this->response->redirect($this->link());
	}

	public function markupSave() {
		$this->load->language($this->route);
		$this->load->model($this->route);

		if ($this->request->server['REQUEST_METHOD'] !== 'POST' || !$this->canModify()) {
			return $this->json(array('error' => $this->language->get('error_permission')));
		}

		$rows = json_decode(html_entity_decode(isset($this->request->post['rules']) ? $this->request->post['rules'] : '[]', ENT_QUOTES, 'UTF-8'), true);
		$this->json(array('saved' => $this->model_extension_module_vm_catalog->saveMarkups(is_array($rows) ? $rows : array())));
	}

	public function markupBrands() {
		$this->load->model($this->route);
		$supplier = isset($this->request->get['supplier']) ? (string)$this->request->get['supplier'] : '';
		$q = isset($this->request->get['q']) ? html_entity_decode((string)$this->request->get['q'], ENT_QUOTES, 'UTF-8') : '';
		$this->json(array('brands' => $this->model_extension_module_vm_catalog->supplierBrands($supplier, $q)));
	}

	public function markupRecalc() {
		$this->load->language($this->route);
		$this->load->model($this->route);

		if (!$this->canModify() || !$this->user->hasPermission('modify', 'catalog/product')) {
			return $this->json(array('error' => $this->language->get('error_permission')));
		}

		@set_time_limit(120);
		$after = isset($this->request->get['after']) ? (int)$this->request->get['after'] : 0;
		$this->json($this->model_extension_module_vm_catalog->recalcPrices($after));
	}

	public function categorize() {
		$this->load->language($this->route);
		$this->load->model($this->route);

		if (!$this->canModify() || !$this->user->hasPermission('modify', 'catalog/category') || !$this->user->hasPermission('modify', 'catalog/product')) {
			return $this->json(array('error' => $this->language->get('error_permission')));
		}

		$after = isset($this->request->get['after']) ? (int)$this->request->get['after'] : 0;
		$this->json($this->model_extension_module_vm_catalog->categorize($after));
	}

	// allow checkout of items that are "on order" (quantity 0 at the local warehouse)
	public function stockCheckout() {
		$this->load->language($this->route);

		if ($this->request->server['REQUEST_METHOD'] === 'POST' && $this->canModify() && $this->user->hasPermission('modify', 'setting/setting')) {
			$this->load->model('setting/setting');
			$this->model_setting_setting->editSettingValue('config', 'config_stock_checkout', 1);
			$this->session->data['success'] = $this->language->get('text_checkout_on');
		} else {
			$this->session->data['vm_catalog_error'] = $this->language->get('error_permission');
		}

		$this->response->redirect($this->link());
	}

	// ---------------------------------------------------------------- updates

	private function errorText($code) {
		$t = $this->language->get('error_upd_' . $code);

		return $t !== 'error_upd_' . $code ? $t : $this->language->get('error_upd');
	}

	public function checkUpdate() {
		$this->load->language($this->route);
		$this->load->model($this->route . '_updater');

		$s = $this->settings();
		$res = $this->model_extension_module_vm_catalog_updater->checkManifest($s['module_vm_catalog_update_url']);

		if (isset($res['error'])) {
			unset($this->session->data['vm_catalog_update']);
			$this->session->data['vm_catalog_error'] = $this->errorText($res['error']) . (isset($res['detail']) ? ' (' . $res['detail'] . ')' : '');
		} elseif (!$res['newer']) {
			unset($this->session->data['vm_catalog_update']);
			$this->session->data['success'] = sprintf($this->language->get('text_upd_latest'), $res['current']);
		} else {
			$this->session->data['vm_catalog_update'] = $res;
		}

		$this->response->redirect($this->link());
	}

	public function update() {
		$this->load->language($this->route);
		$this->load->model($this->route . '_updater');

		$info = isset($this->session->data['vm_catalog_update']) ? $this->session->data['vm_catalog_update'] : null;

		if ($this->request->server['REQUEST_METHOD'] !== 'POST' || !$this->canModify() || !$info) {
			$this->session->data['vm_catalog_error'] = $this->language->get('error_permission');

			return $this->response->redirect($this->link());
		}

		$dl = $this->model_extension_module_vm_catalog_updater->fetch($info['zip'], 15728640);

		if (isset($dl['error'])) {
			$this->session->data['vm_catalog_error'] = $this->errorText($dl['error']);

			return $this->response->redirect($this->link());
		}

		$tmp = tempnam(sys_get_temp_dir(), 'vmc');
		file_put_contents($tmp, $dl['body']);
		$res = $this->model_extension_module_vm_catalog_updater->installZip($tmp, $info['sha256']);
		@unlink($tmp);

		unset($this->session->data['vm_catalog_update']);

		if (isset($res['error'])) {
			$this->session->data['vm_catalog_error'] = $this->errorText($res['error']);
		} else {
			$this->session->data['success'] = sprintf($this->language->get('text_upd_done'), $res['version']);
		}

		$this->response->redirect($this->link());
	}

	public function rollback() {
		$this->load->language($this->route);
		$this->load->model($this->route . '_updater');

		if (!$this->canModify()) {
			$this->session->data['vm_catalog_error'] = $this->language->get('error_permission');
		} else {
			$res = $this->model_extension_module_vm_catalog_updater->rollback(isset($this->request->get['backup']) ? $this->request->get['backup'] : '');

			if (isset($res['error'])) {
				$this->session->data['vm_catalog_error'] = $this->errorText($res['error']);
			} else {
				$this->session->data['success'] = sprintf($this->language->get('text_upd_rolled'), $res['version']);
			}
		}

		$this->response->redirect($this->link());
	}

	// ---------------------------------------------------------------- install

	public function install() {
		$this->load->model($this->route);
		$this->load->model('setting/setting');
		$this->model_extension_module_vm_catalog->install();
		$this->model_setting_setting->editSetting('module_vm_catalog', $this->defaults());
	}

	public function uninstall() {
		// products, categories and the price mapping stay: only settings are removed
		$this->load->model('setting/setting');
		$this->model_setting_setting->deleteSetting('module_vm_catalog');
	}
}
