<?php
/**
 * VM Parts theme helper: events that feed the vm_parts templates.
 * Registered on install as catalog/view/* /before (after the core theme event).
 * Every method here must return nothing, otherwise OpenCart treats the return value as page output.
 */
class ControllerExtensionModuleVmTheme extends Controller {
	private static $bestsellers = null;
	private static $texts = null;
	private static $menu = null;

	public function index() {
		return '';
	}

	private function active() {
		if (!$this->config->get('module_vm_theme_status')) {
			return false;
		}

		$dir = ($this->config->get('config_theme') == 'default') ? $this->config->get('theme_default_directory') : $this->config->get('config_theme');

		return $dir == 'vm_parts';
	}

	// --------------------------------------------------------------- the view event

	public function view(&$route, &$data, &$code) {
		if (!$this->active()) {
			return;
		}

		$this->fallbackTemplate($route);

		if (!is_array($data)) {
			return;
		}

		$data['vm_t'] = $this->texts();
		$data['vm'] = $this->settings();

		if (isset($data['products']) && is_array($data['products']) && $data['products']) {
			$data['products'] = $this->enrichProducts($data['products']);
		}

		switch ($route) {
			case 'common/header':
				$this->header($data);
				break;
			case 'common/home':
				$data['vm_menu'] = $this->menu();
				break;
			case 'common/footer':
				$data['vm_menu'] = $this->menu();
				$data['telephone'] = $this->config->get('config_telephone');
				$data['vm_address'] = nl2br($this->config->get('config_address'));
				$data['vm_open'] = nl2br($this->config->get('config_open'));
				$data['vm_name'] = $this->config->get('config_name');
				break;
			case 'product/product':
				$this->productPage($data);
				break;
		}
	}

	// Templates that exist neither in vm_parts nor in default (modules of the previous theme,
	// e.g. a blog or newsletter block) are taken from the previous theme instead of failing.
	private function fallbackTemplate($route) {
		if (is_file(DIR_TEMPLATE . 'vm_parts/template/' . $route . '.twig') || is_file(DIR_TEMPLATE . 'default/template/' . $route . '.twig')) {
			return;
		}

		$fallback = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$this->config->get('module_vm_theme_fallback'));

		if ($fallback && is_file(DIR_TEMPLATE . $fallback . '/template/' . $route . '.twig')) {
			$this->config->set('template_directory', $fallback . '/template/');
		}
	}

	private function texts() {
		if (self::$texts === null) {
			$_ = array();

			$en = DIR_LANGUAGE . 'en-gb/extension/module/vm_theme.php';

			if (is_file($en)) {
				include($en);
			}

			$file = DIR_LANGUAGE . basename((string)$this->config->get('config_language')) . '/extension/module/vm_theme.php';

			if (is_file($file)) {
				include($file);
			}

			self::$texts = $_;
		}

		return self::$texts;
	}

	private function settings() {
		$benefits = array();

		foreach (preg_split('/\r\n|\r|\n/', (string)html_entity_decode((string)$this->config->get('module_vm_theme_benefits'), ENT_QUOTES, 'UTF-8')) as $line) {
			$parts = array_map('trim', explode('|', $line, 2));

			if ($parts[0] !== '') {
				$benefits[] = array('title' => $parts[0], 'text' => isset($parts[1]) ? $parts[1] : '');
			}
		}

		$viber = preg_replace('/[^0-9]/', '', (string)$this->config->get('module_vm_theme_viber'));
		$telegram = trim((string)$this->config->get('module_vm_theme_telegram'));

		if ($telegram && strpos($telegram, 'http') !== 0) {
			$telegram = 'https://t.me/' . ltrim($telegram, '@');
		}

		$phone = (string)$this->config->get('config_telephone');

		return array(
			'phone'      => $phone,
			'phone_href' => 'tel:' . preg_replace('/[^0-9\+]/', '', $phone),
			'phone2'     => (string)$this->config->get('module_vm_theme_phone2'),
			'phone2_href'=> 'tel:' . preg_replace('/[^0-9\+]/', '', (string)$this->config->get('module_vm_theme_phone2')),
			'viber'      => $viber ? 'viber://chat?number=%2B' . $viber : '',
			'telegram'   => $telegram,
			'instagram'  => (string)$this->config->get('module_vm_theme_instagram'),
			'facebook'   => (string)$this->config->get('module_vm_theme_facebook'),
			'hours'      => (string)$this->config->get('module_vm_theme_hours'),
			'benefits'   => $benefits,
			'head_code'  => html_entity_decode((string)$this->config->get('module_vm_theme_head_code'), ENT_QUOTES, 'UTF-8'),
			'body_code'  => html_entity_decode((string)$this->config->get('module_vm_theme_body_code'), ENT_QUOTES, 'UTF-8'),
			'year'       => date('Y'),
			// cache-busting for the stylesheet after theme updates
			'version'    => is_file(DIR_TEMPLATE . 'vm_parts/VERSION') ? trim(file_get_contents(DIR_TEMPLATE . 'vm_parts/VERSION')) : '1'
		);
	}

	private function header(&$data) {
		$data['vm_menu'] = $this->menu();
		$data['vm_route'] = isset($this->request->get['route']) ? (string)$this->request->get['route'] : 'common/home';
		$data['vm_is_home'] = ($data['vm_route'] == 'common/home' || $data['vm_route'] == '');

		// jQuery file differs between OpenCart 3.0.2/3.0.3 (2.1.1) and 3.0.4 (3.7.x)
		$jquery = 'catalog/view/javascript/jquery/jquery-2.1.1.min.js';

		foreach (array('jquery-3.7.1.min.js', 'jquery-3.7.0.min.js', 'jquery-2.1.1.min.js') as $file) {
			if (is_file(DIR_APPLICATION . 'view/javascript/jquery/' . $file)) {
				$jquery = 'catalog/view/javascript/jquery/' . $file;
				break;
			}
		}

		$data['vm_jquery'] = $jquery;

		$this->load->model('catalog/information');

		$data['vm_infos'] = array();

		foreach ($this->model_catalog_information->getInformations() as $info) {
			if ($info['bottom']) {
				$data['vm_infos'][] = array('title' => $info['title'], 'href' => $this->url->link('information/information', 'information_id=' . $info['information_id']));
			}
		}

		$data['vm_contact'] = $this->url->link('information/contact');
		$data['vm_special'] = $this->url->link('product/special');
	}

	// All top-level categories (no "top" flag needed) with their children
	private function menu() {
		if (self::$menu !== null) {
			return self::$menu;
		}

		$this->load->model('catalog/category');
		$this->load->model('tool/image');

		$menu = array();

		foreach ($this->model_catalog_category->getCategories(0) as $category) {
			$children = array();

			foreach ($this->model_catalog_category->getCategories($category['category_id']) as $child) {
				$children[] = array('name' => $child['name'], 'href' => $this->url->link('product/category', 'path=' . $category['category_id'] . '_' . $child['category_id']));
			}

			$menu[] = array(
				'name'     => $category['name'],
				'href'     => $this->url->link('product/category', 'path=' . $category['category_id']),
				'image'    => $category['image'] ? $this->model_tool_image->resize($category['image'], 160, 120) : '',
				'children' => $children
			);
		}

		return self::$menu = $menu;
	}

	// --------------------------------------------------------------- products

	private function hitIds() {
		if (self::$bestsellers === null) {
			self::$bestsellers = array();

			$this->load->model('catalog/product');

			foreach ((array)$this->model_catalog_product->getBestSellerProducts(20) as $p) {
				if (isset($p['product_id'])) {
					self::$bestsellers[(int)$p['product_id']] = true;
				}
			}
		}

		return self::$bestsellers;
	}

	private function vmTablesExist() {
		static $exists = null;

		if ($exists === null) {
			$query = $this->db->query("SHOW TABLES LIKE '" . $this->db->escape(DB_PREFIX . 'vm_product_vehicle') . "'");
			$exists = (bool)$query->num_rows;
		}

		return $exists;
	}

	private function selectedCar() {
		if (empty($this->request->cookie['vm_car'])) {
			return false;
		}

		$parts = array_map('intval', explode('-', (string)$this->request->cookie['vm_car']));

		if (empty($parts[1])) {
			return false;
		}

		return array('model_id' => $parts[1], 'year' => isset($parts[2]) ? $parts[2] : 0);
	}

	private function enrichProducts($products) {
		$ids = array();

		foreach ($products as $p) {
			if (is_array($p) && isset($p['product_id'])) {
				$ids[] = (int)$p['product_id'];
			}
		}

		if (!$ids) {
			return $products;
		}

		$in = implode(',', array_unique($ids));
		$lang = (int)$this->config->get('config_language_id');
		$group = (int)$this->config->get('config_customer_group_id');
		$new_days = (int)$this->config->get('module_vm_theme_new_days');

		if ($new_days < 1) {
			$new_days = 30;
		}

		$rows = array();

		$query = $this->db->query("SELECT p.product_id, p.model, p.quantity, p.price, p.date_added, ss.name AS stock_status,
			(SELECT ps.price FROM `" . DB_PREFIX . "product_special` ps WHERE ps.product_id = p.product_id AND ps.customer_group_id = '" . $group . "' AND ((ps.date_start = '0000-00-00' OR ps.date_start < NOW()) AND (ps.date_end = '0000-00-00' OR ps.date_end > NOW())) ORDER BY ps.priority ASC, ps.price ASC LIMIT 1) AS special
			FROM `" . DB_PREFIX . "product` p LEFT JOIN `" . DB_PREFIX . "stock_status` ss ON (ss.stock_status_id = p.stock_status_id AND ss.language_id = '" . $lang . "')
			WHERE p.product_id IN (" . $in . ")");

		foreach ($query->rows as $row) {
			$rows[(int)$row['product_id']] = $row;
		}

		$fits = array();
		$car = $this->selectedCar();

		if ($car && $this->vmTablesExist()) {
			$year_sql = '';

			if ($car['year']) {
				$y = (int)$car['year'];
				$year_sql = " AND (pv.year_from = 0 OR pv.year_from <= '" . $y . "') AND (pv.year_to = 0 OR pv.year_to >= '" . $y . "')";
			}

			$query = $this->db->query("SELECT pv.product_id, pv.year_from, pv.year_to, md.name AS model, mk.name AS make FROM `" . DB_PREFIX . "vm_product_vehicle` pv
				LEFT JOIN `" . DB_PREFIX . "vm_model` md ON (md.model_id = pv.model_id)
				LEFT JOIN `" . DB_PREFIX . "vm_make` mk ON (mk.make_id = md.make_id)
				WHERE pv.model_id = '" . (int)$car['model_id'] . "' AND pv.product_id IN (" . $in . ")" . $year_sql);

			foreach ($query->rows as $row) {
				$years = $row['year_from'] ? ' ' . $row['year_from'] . '–' . ($row['year_to'] ? $row['year_to'] : $this->texts()['text_now']) : '';
				$fits[(int)$row['product_id']] = $row['make'] . ' ' . $row['model'] . $years;
			}
		}

		$hits = $this->hitIds();
		$t = $this->texts();

		foreach ($products as $k => $p) {
			if (!is_array($p) || !isset($p['product_id'])) {
				continue;
			}

			$id = (int)$p['product_id'];

			if (!isset($rows[$id])) {
				continue;
			}

			$r = $rows[$id];

			$in_stock = (int)$r['quantity'] > 0;

			$products[$k]['vm_model'] = $r['model'];
			$products[$k]['vm_stock'] = $in_stock ? $t['text_in_stock'] : ($r['stock_status'] ? $r['stock_status'] : $t['text_out_stock']);
			$products[$k]['vm_stock_class'] = $in_stock ? 'in' : 'wait';
			$products[$k]['vm_new'] = (strtotime($r['date_added']) > time() - $new_days * 86400);
			$products[$k]['vm_hit'] = isset($hits[$id]);
			$products[$k]['vm_discount'] = '';

			if ($r['special'] !== null && (float)$r['price'] > 0 && (float)$r['special'] < (float)$r['price']) {
				$products[$k]['vm_discount'] = '-' . round((1 - (float)$r['special'] / (float)$r['price']) * 100) . '%';
			}

			$products[$k]['vm_fit'] = isset($fits[$id]) ? $fits[$id] : '';
		}

		return $products;
	}

	private function productPage(&$data) {
		if (empty($data['product_id'])) {
			return;
		}

		$one = $this->enrichProducts(array(array('product_id' => (int)$data['product_id'])));
		$data['vm_product'] = $one[0];

		$data['vm_fits'] = array();

		if ($this->vmTablesExist()) {
			$query = $this->db->query("SELECT mk.name AS make, md.name AS model, pv.year_from, pv.year_to FROM `" . DB_PREFIX . "vm_product_vehicle` pv
				LEFT JOIN `" . DB_PREFIX . "vm_model` md ON (md.model_id = pv.model_id)
				LEFT JOIN `" . DB_PREFIX . "vm_make` mk ON (mk.make_id = md.make_id)
				WHERE pv.product_id = '" . (int)$data['product_id'] . "' ORDER BY mk.name, md.name");

			foreach ($query->rows as $row) {
				$data['vm_fits'][] = array(
					'make'  => $row['make'],
					'model' => $row['model'],
					'years' => $row['year_from'] ? $row['year_from'] . '–' . ($row['year_to'] ? $row['year_to'] : $this->texts()['text_now']) : $this->texts()['text_all_years']
				);
			}
		}
	}
}
