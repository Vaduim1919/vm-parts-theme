<?php
class ControllerExtensionModuleVmTheme extends Controller {
	// where release manifests are published (can be changed in the module settings)
	const DEFAULT_UPDATE_URL = 'https://raw.githubusercontent.com/Vaduim1919/vm-parts-theme/main/latest.json';

	private $error = array();
	private $route = 'extension/module/vm_theme';

	private function link($action = '') {
		return $this->url->link($this->route . ($action ? '/' . $action : ''), 'user_token=' . $this->session->data['user_token'], true);
	}

	private function currentDirectory() {
		return ($this->config->get('config_theme') == 'default') ? $this->config->get('theme_default_directory') : $this->config->get('config_theme');
	}

	public function index() {
		$this->load->language($this->route);
		$this->load->model('setting/setting');
		$this->load->model($this->route);

		$this->migrate();

		$this->document->setTitle($this->language->get('heading_title'));

		if ($this->request->server['REQUEST_METHOD'] == 'POST' && $this->validate()) {
			$post = $this->request->post;
			// keep values the form does not send
			foreach (array('module_vm_theme_prev_theme', 'module_vm_theme_prev_directory', 'module_vm_theme_moved', 'module_vm_theme_installed_version') as $key) {
				$post[$key] = (string)$this->config->get($key);
			}

			$this->model_setting_setting->editSetting('module_vm_theme', $post);
			$this->session->data['success'] = $this->language->get('text_success');
			$this->response->redirect($this->link());
		}

		$data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';

		if (isset($this->session->data['vm_theme_error'])) {
			$data['error_warning'] = $this->session->data['vm_theme_error'];
			unset($this->session->data['vm_theme_error']);
		}

		$data['success'] = '';

		if (isset($this->session->data['success'])) {
			$data['success'] = $this->session->data['success'];
			unset($this->session->data['success']);
		}

		$data['breadcrumbs'] = array(
			array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)),
			array('text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true)),
			array('text' => $this->language->get('heading_title'), 'href' => $this->link())
		);

		$data['action'] = $this->link();
		$data['activate'] = $this->link('activate');
		$data['restore'] = $this->link('restore');
		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true);

		$fields = array(
			'module_vm_theme_status'    => 1,
			'module_vm_theme_phone2'    => '',
			'module_vm_theme_viber'     => '',
			'module_vm_theme_telegram'  => '',
			'module_vm_theme_instagram' => '',
			'module_vm_theme_facebook'  => '',
			'module_vm_theme_hours'     => '',
			'module_vm_theme_benefits'  => '',
			'module_vm_theme_new_days'  => 30,
			'module_vm_theme_fallback'  => '',
			'module_vm_theme_head_code' => '',
			'module_vm_theme_body_code' => '',
			'module_vm_theme_update_url' => ''
		);

		foreach ($fields as $key => $default) {
			if (isset($this->request->post[$key])) {
				$data[$key] = $this->request->post[$key];
			} elseif ($this->config->has($key)) {
				$data[$key] = $this->config->get($key);
			} else {
				$data[$key] = $default;
			}
		}

		$data['version'] = $this->model_extension_module_vm_theme->getVersion();
		$data['backups'] = $this->model_extension_module_vm_theme->getBackups();
		// relative URLs: work whatever HTTP(S)_SERVER says in admin/config.php (avoids blocked mixed-content requests)
		$data['check_url'] = 'index.php?route=' . $this->route . '/checkUpdate&user_token=' . $this->session->data['user_token'];
		$data['update_url'] = 'index.php?route=' . $this->route . '/update&user_token=' . $this->session->data['user_token'];
		$data['upload_action'] = $this->link('upload');
		$data['rollback'] = $this->link('rollback');
		$data['zip_ok'] = class_exists('ZipArchive');

		$data['current_directory'] = $this->currentDirectory();
		$data['is_active'] = ($data['current_directory'] == 'vm_parts');
		$data['prev_directory'] = (string)$this->config->get('module_vm_theme_prev_directory');

		$data['directories'] = array();

		foreach (glob(DIR_CATALOG . 'view/theme/*', GLOB_ONLYDIR) as $dir) {
			if (basename($dir) != 'vm_parts') {
				$data['directories'][] = basename($dir);
			}
		}

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view($this->route, $data));
	}

	// Switch the store to the vm_parts directory, remembering what was there before
	public function activate() {
		$this->load->language($this->route);

		if (!$this->user->hasPermission('modify', $this->route)) {
			$this->session->data['vm_theme_error'] = $this->language->get('error_permission');
			$this->response->redirect($this->link());

			return;
		}

		$this->load->model('setting/setting');

		$current = $this->currentDirectory();

		if ($current != 'vm_parts') {
			$this->model_setting_setting->editSettingValue('module_vm_theme', 'module_vm_theme_prev_theme', (string)$this->config->get('config_theme'));
			$this->model_setting_setting->editSettingValue('module_vm_theme', 'module_vm_theme_prev_directory', (string)$this->config->get('theme_default_directory'));

			if (!$this->config->get('module_vm_theme_fallback')) {
				$this->model_setting_setting->editSettingValue('module_vm_theme', 'module_vm_theme_fallback', $current);
			}
		}

		$this->arrangeHomeLayout();

		$this->model_setting_setting->editSettingValue('config', 'config_theme', 'default');
		$this->model_setting_setting->editSettingValue('theme_default', 'theme_default_directory', 'vm_parts');
		$this->model_setting_setting->editSettingValue('theme_default', 'theme_default_status', '1');
		$this->model_setting_setting->editSettingValue('module_vm_theme', 'module_vm_theme_status', '1');

		$this->clearCache();

		$this->session->data['success'] = $this->language->get('text_activated');
		$this->response->redirect($this->link());
	}

	public function restore() {
		$this->load->language($this->route);

		if (!$this->user->hasPermission('modify', $this->route)) {
			$this->session->data['vm_theme_error'] = $this->language->get('error_permission');
			$this->response->redirect($this->link());

			return;
		}

		$this->doRestore();

		$this->session->data['success'] = $this->language->get('text_restored');
		$this->response->redirect($this->link());
	}

	private function doRestore() {
		$this->load->model('setting/setting');

		$this->restoreHomeLayout();

		$prev_theme = (string)$this->config->get('module_vm_theme_prev_theme');
		$prev_dir = (string)$this->config->get('module_vm_theme_prev_directory');

		$this->model_setting_setting->editSettingValue('config', 'config_theme', $prev_theme ? $prev_theme : 'default');
		$this->model_setting_setting->editSettingValue('theme_default', 'theme_default_directory', ($prev_dir && $prev_dir != 'vm_parts') ? $prev_dir : 'default');

		$this->clearCache();
	}

	// On the home page only the slider and the car filter sit next to the catalog column;
	// product blocks go full width below. The moved rows are remembered for restore().
	private function arrangeHomeLayout() {
		$query = $this->db->query("SELECT layout_id FROM `" . DB_PREFIX . "layout_route` WHERE route = 'common/home' LIMIT 1");

		if (!$query->num_rows) {
			return;
		}

		$layout_id = (int)$query->row['layout_id'];

		$moved = json_decode((string)$this->config->get('module_vm_theme_moved'), true);

		if (!is_array($moved)) {
			$moved = array();
		}

		$rows = $this->db->query("SELECT layout_module_id, code, sort_order FROM `" . DB_PREFIX . "layout_module` WHERE layout_id = '" . $layout_id . "' AND position = 'content_top'")->rows;

		foreach ($rows as $row) {
			$code = strtok($row['code'], '.');

			if (in_array($code, array('slideshow', 'vm_carfilter', 'html', 'banner'))) {
				continue;
			}

			$this->db->query("UPDATE `" . DB_PREFIX . "layout_module` SET position = 'content_bottom', sort_order = '" . ((int)$row['sort_order'] - 100) . "' WHERE layout_module_id = '" . (int)$row['layout_module_id'] . "'");
			$moved[] = (int)$row['layout_module_id'];
		}

		$this->load->model('setting/setting');
		$this->saveValue('module_vm_theme_moved', json_encode(array_values(array_unique($moved))));
	}

	private function restoreHomeLayout() {
		$moved = json_decode((string)$this->config->get('module_vm_theme_moved'), true);

		if (is_array($moved) && $moved) {
			foreach ($moved as $id) {
				$this->db->query("UPDATE `" . DB_PREFIX . "layout_module` SET position = 'content_top', sort_order = sort_order + 100 WHERE layout_module_id = '" . (int)$id . "' AND position = 'content_bottom'");
			}
		}

		$this->saveValue('module_vm_theme_moved', '');
	}

	private function saveValue($key, $value) {
		$this->db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE store_id = '0' AND `code` = 'module_vm_theme' AND `key` = '" . $this->db->escape($key) . "'");
		$this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET store_id = '0', `code` = 'module_vm_theme', `key` = '" . $this->db->escape($key) . "', `value` = '" . $this->db->escape($value) . "', serialized = '0'");
		$this->config->set($key, $value);
	}

	// Runs once after the files were updated: re-register events, add settings new versions need.
	private function migrate() {
		$this->load->model($this->route);

		$file_version = $this->model_extension_module_vm_theme->getVersion();

		if ((string)$this->config->get('module_vm_theme_installed_version') === $file_version) {
			return;
		}

		if (!$this->config->has('module_vm_theme_status')) {
			return; // not installed yet
		}

		$this->load->model('setting/event');
		$this->model_setting_event->deleteEventByCode('vm_theme');
		$this->model_setting_event->addEvent('vm_theme', 'catalog/view/*/before', 'extension/module/vm_theme/view', 1, 501);

		if (!$this->config->has('module_vm_theme_update_url')) {
			$this->saveValue('module_vm_theme_update_url', self::DEFAULT_UPDATE_URL);
		}

		$this->saveValue('module_vm_theme_installed_version', $file_version);
		$this->clearCache();
	}

	private function json($data) {
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($data));
	}

	private function errorText($code, $detail = '') {
		$text = $this->language->get('error_upd_' . $code);

		if ($text == 'error_upd_' . $code) {
			$text = $this->language->get('error_upd_generic') . ' (' . $code . ')';
		}

		return $text . ($detail ? ' — ' . $detail : '');
	}

	private function manifestUrl() {
		$url = trim(html_entity_decode((string)$this->config->get('module_vm_theme_update_url'), ENT_QUOTES, 'UTF-8'));

		return $url ? $url : self::DEFAULT_UPDATE_URL;
	}

	public function checkUpdate() {
		$this->load->language($this->route);
		$this->load->model($this->route);

		if (!$this->user->hasPermission('modify', $this->route)) {
			return $this->json(array('error' => $this->language->get('error_permission')));
		}

		$res = $this->model_extension_module_vm_theme->checkManifest($this->manifestUrl());

		if (isset($res['error'])) {
			return $this->json(array('error' => $this->errorText($res['error'], isset($res['detail']) ? $res['detail'] : '')));
		}

		$res['message'] = $res['newer'] ? sprintf($this->language->get('text_upd_available'), $res['version'], $res['current']) : sprintf($this->language->get('text_upd_latest'), $res['current']);

		return $this->json($res);
	}

	public function update() {
		$this->load->language($this->route);
		$this->load->model($this->route);

		if (!$this->user->hasPermission('modify', $this->route) || $this->request->server['REQUEST_METHOD'] != 'POST') {
			return $this->json(array('error' => $this->language->get('error_permission')));
		}

		$m = $this->model_extension_module_vm_theme->checkManifest($this->manifestUrl());

		if (isset($m['error'])) {
			return $this->json(array('error' => $this->errorText($m['error'], isset($m['detail']) ? $m['detail'] : '')));
		}

		$dl = $this->model_extension_module_vm_theme->fetch($m['zip'], ModelExtensionModuleVmTheme::MAX_ZIP);

		if (isset($dl['error'])) {
			return $this->json(array('error' => $this->errorText($dl['error'], isset($dl['detail']) ? $dl['detail'] : '')));
		}

		$tmp = tempnam(sys_get_temp_dir(), 'vmt');
		file_put_contents($tmp, $dl['body']);

		$res = $this->model_extension_module_vm_theme->installZip($tmp, $m['sha256']);
		@unlink($tmp);

		if (isset($res['error'])) {
			return $this->json(array('error' => $this->errorText($res['error'], isset($res['detail']) ? $res['detail'] : '')));
		}

		return $this->json(array('success' => sprintf($this->language->get('text_upd_done'), $res['version'], $res['files']) . ($res['skipped'] ? ' ' . sprintf($this->language->get('text_upd_skipped'), count($res['skipped'])) : '')));
	}

	public function upload() {
		$this->load->language($this->route);
		$this->load->model($this->route);

		if (!$this->user->hasPermission('modify', $this->route)) {
			$this->session->data['vm_theme_error'] = $this->language->get('error_permission');
		} elseif (empty($this->request->files['file']['tmp_name']) || !is_uploaded_file($this->request->files['file']['tmp_name'])) {
			$this->session->data['vm_theme_error'] = $this->errorText('nofile');
		} else {
			$res = $this->model_extension_module_vm_theme->installZip($this->request->files['file']['tmp_name']);

			if (isset($res['error'])) {
				$this->session->data['vm_theme_error'] = $this->errorText($res['error'], isset($res['detail']) ? $res['detail'] : '');
			} else {
				$this->session->data['success'] = sprintf($this->language->get('text_upd_done'), $res['version'], $res['files']) . ($res['skipped'] ? ' ' . sprintf($this->language->get('text_upd_skipped'), count($res['skipped'])) : '');
			}
		}

		$this->response->redirect($this->link());
	}

	public function rollback() {
		$this->load->language($this->route);
		$this->load->model($this->route);

		if (!$this->user->hasPermission('modify', $this->route)) {
			$this->session->data['vm_theme_error'] = $this->language->get('error_permission');
		} else {
			$res = $this->model_extension_module_vm_theme->rollback(isset($this->request->get['backup']) ? $this->request->get['backup'] : '');

			if (isset($res['error'])) {
				$this->session->data['vm_theme_error'] = $this->errorText($res['error']);
			} else {
				$this->session->data['success'] = sprintf($this->language->get('text_upd_rolled'), $res['version']);
			}
		}

		$this->response->redirect($this->link());
	}

	private function clearCache() {
		// twig cache of the storefront
		$files = glob(DIR_CACHE . 'template/*/*');

		if ($files) {
			foreach ($files as $file) {
				if (is_file($file)) {
					@unlink($file);
				}
			}
		}
	}

	public function install() {
		$this->load->model('setting/event');
		$this->load->model('setting/setting');
		$this->load->model($this->route);

		$this->model_setting_event->deleteEventByCode('vm_theme');
		// 501: right after the core theme event (500), before the language event (998)
		$this->model_setting_event->addEvent('vm_theme', 'catalog/view/*/before', 'extension/module/vm_theme/view', 1, 501);

		$head = '';
		$seed = DIR_APPLICATION . 'controller/extension/module/vm_theme_head.txt';

		if (is_file($seed)) {
			$head = file_get_contents($seed);
		}

		$this->model_setting_setting->editSetting('module_vm_theme', array(
			'module_vm_theme_status'    => 1,
			'module_vm_theme_phone2'    => '',
			'module_vm_theme_viber'     => '',
			'module_vm_theme_telegram'  => '',
			'module_vm_theme_instagram' => 'https://www.instagram.com/v_m_auto_parts/',
			'module_vm_theme_facebook'  => 'https://www.facebook.com/p/VM-Auto-Parts-61576288277241/',
			'module_vm_theme_hours'     => '',
			'module_vm_theme_benefits'  => "Доставка по Україні | Нова Пошта, Укрпошта\nПідбір за VIN | Перевіримо сумісність\nОплата при отриманні | або карткою онлайн\nПовернення 14 днів | якщо деталь не підійшла",
			'module_vm_theme_new_days'  => 30,
			'module_vm_theme_fallback'  => $this->currentDirectory() != 'vm_parts' ? $this->currentDirectory() : '',
			'module_vm_theme_head_code' => $head,
			'module_vm_theme_body_code' => '',
			'module_vm_theme_prev_theme' => '',
			'module_vm_theme_prev_directory' => '',
			'module_vm_theme_update_url' => self::DEFAULT_UPDATE_URL,
			'module_vm_theme_installed_version' => $this->model_extension_module_vm_theme->getVersion()
		));
	}

	public function uninstall() {
		$this->load->model('setting/event');
		$this->model_setting_event->deleteEventByCode('vm_theme');

		// never leave the store pointing to a theme whose helper is gone
		if ($this->currentDirectory() == 'vm_parts') {
			$this->doRestore();
		}
	}

	protected function validate() {
		if (!$this->user->hasPermission('modify', $this->route)) {
			$this->error['warning'] = $this->language->get('error_permission');
		}

		return !$this->error;
	}
}
