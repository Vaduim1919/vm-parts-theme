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
		$data['yml_chunk_url'] = $this->ajaxUrl('ymlChunk');
		$data['bms_groups_url'] = $this->ajaxUrl('bmsGroups');
		$data['bms_save_url'] = $this->ajaxUrl('bmsSave');
		$data['bms_run_url'] = $this->ajaxUrl('bmsRun');
		$data['bms_groups'] = json_decode((string)$this->config->get('module_vm_catalog_bm_groups'), true) ?: array();
		$data['bms_local'] = (string)$this->config->get('module_vm_catalog_bm_local');
		$data['bms_days'] = (int)($this->config->get('module_vm_catalog_bm_days') ?: 2);
		$data['bms_only'] = $this->config->get('module_vm_catalog_bm_only') === null || (string)$this->config->get('module_vm_catalog_bm_only') === '1';
		$data['yml_start_url'] = $this->ajaxUrl('ymlStart');
		$data['yml_step_url'] = $this->ajaxUrl('ymlStep');
		$data['yml_photos_url'] = $this->ajaxUrl('ymlPhotos');
		$data['ria_step_url'] = $this->ajaxUrl('riaStep');
		$data['ria_key_url'] = $this->ajaxUrl('riaKey');
		$data['cars_rebuild_url'] = $this->ajaxUrl('carsRebuild');
		$data['ria'] = $this->model_extension_module_vm_catalog->ria()->stats();
		$data['yml_job'] = $this->model_extension_module_vm_catalog->yml()->getJob();
		$data['yml_photo'] = $this->model_extension_module_vm_catalog->yml()->photoStats();
		$data['yml_photos_max'] = max(1, min(6, (int)($this->config->get('module_vm_catalog_yml_photos') ?: 1)));
		$data['post_max'] = ini_get('post_max_size');
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
			'module_vm_catalog_ria_per_hour' => isset($src['module_vm_catalog_ria_per_hour']) ? max(5, min(5000, (int)$src['module_vm_catalog_ria_per_hour'])) : (int)$this->config->get('module_vm_catalog_ria_per_hour'),
			'module_vm_catalog_ria_key'    => !empty($src['module_vm_catalog_ria_key']) ? preg_replace('/[^A-Za-z0-9]/', '', $get('module_vm_catalog_ria_key')) : (string)$this->config->get('module_vm_catalog_ria_key'),
			'module_vm_catalog_bm_photos'  => isset($src['module_vm_catalog_bm_photos']) && in_array($src['module_vm_catalog_bm_photos'], array('none', 'local', 'all'), true) ? $src['module_vm_catalog_bm_photos'] : ((string)$this->config->get('module_vm_catalog_bm_photos') ?: 'all'),
			'module_vm_catalog_photos_v2'  => 1,
			'module_vm_catalog_cron_key'   => $this->cronKey(),
			'module_vm_catalog_admin_dir'  => DIR_APPLICATION,
			'module_vm_catalog_bm_img_pattern' => (string)$this->config->get('module_vm_catalog_bm_img_pattern'),
			'module_vm_catalog_yml_photos' => (string)($this->config->get('module_vm_catalog_yml_photos') ?: 1),
			'module_vm_catalog_bm_groups'  => (string)$this->config->get('module_vm_catalog_bm_groups'),
			'module_vm_catalog_bm_local'   => (string)$this->config->get('module_vm_catalog_bm_local'),
			'module_vm_catalog_bm_days'    => (string)($this->config->get('module_vm_catalog_bm_days') ?: 2),
			'module_vm_catalog_bm_only'    => (string)($this->config->get('module_vm_catalog_bm_only') === null ? '1' : $this->config->get('module_vm_catalog_bm_only'))
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

	// ---------------------------------------------------------------- UTR YML (photos, characteristics)

	public function ymlChunk() {
		$this->load->language($this->route);
		$this->load->model($this->route);

		if ($this->request->server['REQUEST_METHOD'] !== 'POST' || !$this->canModify()) {
			return $this->json(array('error' => $this->language->get('error_permission')));
		}

		if (empty($this->request->files['chunk']['tmp_name']) || !is_uploaded_file($this->request->files['chunk']['tmp_name'])) {
			return $this->json(array('error' => 'Частина файлу не дійшла (ліміт post_max_size = ' . ini_get('post_max_size') . ')'));
		}

		$index = isset($this->request->post['index']) ? (int)$this->request->post['index'] : 0;
		$this->json($this->model_extension_module_vm_catalog->yml()->chunk($index, file_get_contents($this->request->files['chunk']['tmp_name'])));
	}

	public function ymlStart() {
		$this->load->language($this->route);
		$this->load->model($this->route);

		if (!$this->canModify()) {
			return $this->json(array('error' => $this->language->get('error_permission')));
		}

		if (isset($this->request->post['photos'])) {
			$v = (string)max(1, min(6, (int)$this->request->post['photos']));
			$this->db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE store_id = 0 AND `code` = 'module_vm_catalog' AND `key` = 'module_vm_catalog_yml_photos'");
			$this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET store_id = 0, `code` = 'module_vm_catalog', `key` = 'module_vm_catalog_yml_photos', `value` = '" . $this->db->escape($v) . "', serialized = 0");
			$this->config->set('module_vm_catalog_yml_photos', (string)max(1, min(6, (int)$this->request->post['photos'])));
		}

		$this->json($this->model_extension_module_vm_catalog->yml()->start());
	}

	public function ymlStep() {
		$this->load->language($this->route);
		$this->load->model($this->route);

		if (!$this->canModify() || !$this->user->hasPermission('modify', 'catalog/product')) {
			return $this->json(array('error' => $this->language->get('error_permission')));
		}

		$this->json($this->model_extension_module_vm_catalog->yml()->step(20));
	}

	public function ymlPhotos() {
		$this->load->language($this->route);
		$this->load->model($this->route);

		if (!$this->canModify()) {
			return $this->json(array('error' => $this->language->get('error_permission')));
		}

		$y = $this->model_extension_module_vm_catalog->yml();
		$run = $y->runPhotos(20, true); // the button skips the disk-full pause: space may have been freed
		$this->json(array('run' => $run, 'stats' => $y->photoStats()));
	}

	// ---------------------------------------------------------------- AUTO.RIA car reference

	public function riaKey() {
		$this->load->language($this->route);
		$this->load->model($this->route);

		if ($this->request->server['REQUEST_METHOD'] !== 'POST' || !$this->canModify()) {
			return $this->json(array('error' => $this->language->get('error_permission')));
		}

		if (isset($this->request->post['per_hour'])) {
			$this->setValue('module_vm_catalog_ria_per_hour', (string)max(5, min(5000, (int)$this->request->post['per_hour'])));
		}

		$key = isset($this->request->post['key']) ? preg_replace('/[^A-Za-z0-9]/', '', (string)$this->request->post['key']) : '';

		if ($key === '' && $this->config->get('module_vm_catalog_ria_key') && isset($this->request->post['per_hour'])) {
			return $this->json(array('ok' => 1, 'saved' => 'per_hour', 'stats' => $this->model_extension_module_vm_catalog->ria()->stats()));
		}

		if (strlen($key) < 20) {
			return $this->json(array('error' => 'Ключ AUTO.RIA має бути рядком з ~40 латинських літер і цифр'));
		}

		$this->setValue('module_vm_catalog_ria_key', $key);
		$r = $this->model_extension_module_vm_catalog->ria();
		$test = $r->get('auto/categories/1/marks');

		if ($test === null) {
			return $this->json(array('error' => $r->error));
		}

		$this->json(array('ok' => 1, 'makes' => count($test), 'stats' => $r->stats()));
	}

	public function carsRebuild() {
		$this->load->language($this->route);
		$this->load->model($this->route);

		if (!$this->canModify()) {
			return $this->json(array('error' => $this->language->get('error_permission')));
		}

		require_once DIR_APPLICATION . 'model/extension/module/vm_catalog_data/enricher.php';
		$e = new VmCatalogEnricher($this->registry);
		$e->install();

		if (!empty($this->request->get['restart'])) {
			@unlink(DIR_STORAGE . 'upload/vm_catalog/cars_rebuild.json');
		}

		$this->json($e->rebuildCars(20));
	}

	public function riaStep() {
		$this->load->language($this->route);
		$this->load->model($this->route);

		if (!$this->canModify()) {
			return $this->json(array('error' => $this->language->get('error_permission')));
		}

		$r = $this->model_extension_module_vm_catalog->ria();

		$cur = $r->getJob();

		if (!empty($this->request->get['restart']) && (!$cur || $cur['stage'] === 'done')) {
			$r->start();
		}

		$job = $r->step(20, !empty($this->request->get['restart']));
		$this->json(array('job' => array('stage' => $job['stage'], 'error' => $job['error'], 'paused' => !empty($job['paused']), 'waiting' => !empty($job['waiting'])), 'stats' => $r->stats()));
	}

	// ---------------------------------------------------------------- BM Parts as a supplier

	private function setValue($key, $value) {
		$this->db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE store_id = 0 AND `code` = 'module_vm_catalog' AND `key` = '" . $this->db->escape($key) . "'");
		$this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET store_id = 0, `code` = 'module_vm_catalog', `key` = '" . $this->db->escape($key) . "', `value` = '" . $this->db->escape($value) . "', serialized = 0");
		$this->config->set($key, $value);
	}

	public function bmsGroups() {
		$this->load->model($this->route);
		$m = $this->model_extension_module_vm_catalog;
		$tree = $m->bmGroups(!empty($this->request->get['refresh']));
		$wh = isset($tree['error']) ? array() : $m->bmWarehouses();
		$this->json(array('tree' => $tree, 'warehouses' => isset($wh['error']) ? array() : $wh, 'wh_error' => isset($wh['error']) ? $wh['error'] : ''));
	}

	public function bmsSave() {
		$this->load->language($this->route);

		if ($this->request->server['REQUEST_METHOD'] !== 'POST' || !$this->canModify()) {
			return $this->json(array('error' => $this->language->get('error_permission')));
		}

		$post = $this->request->post;
		$groups = json_decode(html_entity_decode(isset($post['groups']) ? $post['groups'] : '[]', ENT_QUOTES, 'UTF-8'), true);
		$groups = is_array($groups) ? array_values(array_filter(array_map('strval', $groups), 'strlen')) : array();
		$this->setValue('module_vm_catalog_bm_groups', json_encode($groups, JSON_UNESCAPED_UNICODE));
		$this->setValue('module_vm_catalog_bm_local', mb_substr(html_entity_decode(isset($post['local']) ? trim($post['local']) : '', ENT_QUOTES, 'UTF-8'), 0, 64));
		$this->setValue('module_vm_catalog_bm_days', (string)max(1, min(60, isset($post['days']) ? (int)$post['days'] : 2)));
		$this->setValue('module_vm_catalog_bm_only', !empty($post['only']) ? '1' : '0');
		$this->json(array('saved' => count($groups)));
	}

	public function bmsRun() {
		$this->load->language($this->route);
		$this->load->model($this->route);

		if (!$this->canModify() || !$this->user->hasPermission('modify', 'catalog/product')) {
			return $this->json(array('error' => $this->language->get('error_permission')));
		}

		$m = $this->model_extension_module_vm_catalog;
		$step = isset($this->request->get['step']) ? $this->request->get['step'] : 'fetch';

		if ($step === 'start') {
			$groups = json_decode((string)$this->config->get('module_vm_catalog_bm_groups'), true);

			return $this->json($m->bmFetchStart(is_array($groups) ? $groups : array(), (string)$this->config->get('module_vm_catalog_bm_only') !== '0'));
		}

		if ($step === 'fetch') {
			return $this->json($m->bmFetchStep(20));
		}

		$s = $this->settings();
		$job = $m->bmImportStart(array(
			'supplier' => 'bm',
			'local'    => (string)$this->config->get('module_vm_catalog_bm_local'),
			'days'     => (int)($this->config->get('module_vm_catalog_bm_days') ?: 2),
			'markup'   => $s['module_vm_catalog_markup'],
			'round'    => $s['module_vm_catalog_round'],
			'file'     => 'BM Parts API'
		));

		$this->json(isset($job['error']) ? $job : array('status' => 'started', 'total' => $job['total']));
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
