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

	// Page opened over http while the shop's base URL is https: cart/wishlist AJAX
	// would go cross-origin and be blocked by the browser. Send visitors to https.
	private function forceHttps() {
		if ($this->config->get('module_vm_theme_force_https') === '0') {
			return;
		}

		$ssl = (string)$this->config->get('config_ssl');

		if (strpos($ssl, 'https://') !== 0 || $this->isSecure() || headers_sent()) {
			return;
		}

		$method = isset($this->request->server['REQUEST_METHOD']) ? $this->request->server['REQUEST_METHOD'] : 'GET';

		if ($method !== 'GET' && $method !== 'HEAD') {
			return;
		}

		$host = parse_url($ssl, PHP_URL_HOST);
		$port = parse_url($ssl, PHP_URL_PORT);
		$host .= $port ? ':' . $port : '';
		// request->server is HTML-escaped by OpenCart
		$uri = isset($this->request->server['REQUEST_URI']) ? html_entity_decode($this->request->server['REQUEST_URI'], ENT_QUOTES, 'UTF-8') : '/';

		if (!parse_url($ssl, PHP_URL_HOST) || preg_match('/[\r\n]/', $uri)) {
			return;
		}

		header('Location: https://' . $host . $uri, true, 301);
		exit;
	}

	private function isSecure() {
		$s = $this->request->server;

		return (!empty($s['HTTPS']) && strtolower($s['HTTPS']) !== 'off')
			|| (isset($s['SERVER_PORT']) && (int)$s['SERVER_PORT'] === 443)
			|| (!empty($s['HTTP_X_FORWARDED_PROTO']) && strtolower($s['HTTP_X_FORWARDED_PROTO']) === 'https')
			|| (!empty($s['HTTP_X_FORWARDED_SSL']) && strtolower($s['HTTP_X_FORWARDED_SSL']) === 'on')
			|| (!empty($s['HTTP_CF_VISITOR']) && strpos($s['HTTP_CF_VISITOR'], 'https') !== false);
	}

	private function cartCount() {
		$count = (int)$this->cart->countProducts();

		if (!empty($this->session->data['vouchers'])) {
			$count += count($this->session->data['vouchers']);
		}

		return $count;
	}

	// AJAX: number of items in the cart for the mobile bottom bar
	public function count() {
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode(array('count' => $this->cartCount())));
	}

	// AJAX: cart summary and wishlist ids for the add-to-cart popup and the hearts
	public function state() {
		$total = 0;

		foreach ($this->cart->getProducts() as $product) {
			$total += $this->tax->calculate($product['price'], $product['tax_class_id'], $this->config->get('config_tax')) * $product['quantity'];
		}

		if (!empty($this->session->data['vouchers'])) {
			foreach ($this->session->data['vouchers'] as $voucher) {
				$total += $voucher['amount'];
			}
		}

		$wishlist = array();

		if ($this->customer->isLogged()) {
			$this->load->model('account/wishlist');

			foreach ($this->model_account_wishlist->getWishlist() as $row) {
				$wishlist[] = (int)$row['product_id'];
			}
		} elseif (!empty($this->session->data['wishlist']) && is_array($this->session->data['wishlist'])) {
			foreach ($this->session->data['wishlist'] as $id) {
				$wishlist[] = (int)$id;
			}
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode(array(
			'count'    => $this->cartCount(),
			'total'    => $this->currency->format($total, $this->session->data['currency']),
			'wishlist' => array_values(array_unique($wishlist)),
			'logged'   => $this->customer->isLogged() ? 1 : 0
		)));
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

		if ($route === 'product/search') {
			$this->searchByOe($data);
		}

		if (isset($data['products']) && is_array($data['products']) && $data['products']) {
			$data['products'] = $this->enrichProducts($data['products']);
		}

		switch ($route) {
			case 'common/header':
				$this->forceHttps();
				$data['vm_home'] = $this->url->link('common/home');
				$data['vm_cart_link'] = $this->url->link('checkout/cart');
				$data['vm_cart_count'] = $this->cartCount();
				$t = $this->texts();
				$data['vm_js'] = json_encode(array(
					'added'    => $t['text_pop_added'],
					'incart'   => $t['text_pop_incart'],
					'cont'     => $t['text_pop_continue'],
					'checkout' => $t['text_pop_checkout'],
					'qty'      => $t['text_pop_qty'],
					'fav_add'  => $t['text_fav_added'],
					'fav_has'  => $t['text_fav_has'],
					'fav_open' => $t['text_fav_open'],
					'fav_login'=> $t['text_fav_login'],
					'cart'     => $this->url->link('checkout/cart'),
					'checkout_url' => $this->url->link('checkout/checkout', '', true),
					'wishlist' => $this->url->link('account/wishlist', '', true),
					'login'    => $this->url->link('account/login', '', true)
				), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
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
			case 'information/information':
			case 'information/contact':
				$this->infoPages($data);
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
		$data['vm_route_class'] = 'vm-r-' . trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($data['vm_route'])), '-');

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

	private function infoPages(&$data) {
		$this->load->model('catalog/information');

		$current = isset($this->request->get['information_id']) ? (int)$this->request->get['information_id'] : 0;

		$data['vm_infos'] = array();

		foreach ($this->model_catalog_information->getInformations() as $info) {
			if ($info['bottom']) {
				$data['vm_infos'][] = array(
					'title'  => $info['title'],
					'href'   => $this->url->link('information/information', 'information_id=' . $info['information_id']),
					'active' => (int)$info['information_id'] === $current
				);
			}
		}

		$data['vm_contact'] = $this->url->link('information/contact');
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

	// warehouses from the V&M Catalog module: own warehouse + supplier's other warehouses
	private function warehouseStock($ids) {
		static $has = null;

		if ($has === null) {
			$has = (bool)$this->db->query("SHOW TABLES LIKE '" . $this->db->escape(DB_PREFIX . 'vm_catalog_item') . "'")->num_rows;
		}

		$out = array();
		$ids = array_filter(array_map('intval', $ids));

		if (!$has || !$ids) {
			return $out;
		}

		$q = $this->db->query("SELECT product_id, SUM(local_qty) AS l, SUM(remote_qty) AS r FROM `" . DB_PREFIX . "vm_catalog_item` WHERE product_id IN (" . implode(',', $ids) . ") GROUP BY product_id");

		foreach ($q->rows as $row) {
			$out[(int)$row['product_id']] = array((int)$row['l'], (int)$row['r']);
		}

		return $out;
	}

	private function warehouseLines($l, $r) {
		$t = $this->texts();
		$local = trim((string)$this->config->get('module_vm_catalog_site_local'));
		$remote = trim((string)$this->config->get('module_vm_catalog_site_remote'));
		$days = (int)$this->config->get('module_vm_catalog_days');
		$days = $days > 0 ? $days : 1;
		$lines = array();

		if ($l > 0) {
			$lines[] = array('class' => 'in', 'text' => ($local !== '' ? $local : $t['text_wh_local']), 'note' => sprintf($t['text_wh_qty'], $l >= 10 ? '10+' : $l));
		}

		if ($r > 0) {
			$lines[] = array('class' => 'wait', 'text' => ($remote !== '' ? $remote : $t['text_wh_remote']), 'note' => sprintf($t['text_wh_qty'], $r >= 10 ? '10+' : $r) . ', ' . sprintf($days == 1 ? $t['text_wh_day'] : $t['text_wh_days'], $days));
		}

		return $lines;
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

		$query = $this->db->query("SELECT p.product_id, p.model, p.quantity, p.minimum, p.price, p.date_added, ss.name AS stock_status,
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
		$wh = $this->warehouseStock(array_keys($rows));

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
			$products[$k]['vm_stock_lines'] = array();

			// module lists (latest/featured/bestseller) don't pass "minimum": cart.add('id', '') adds 0 pcs
			if (empty($products[$k]['minimum']) || (int)$products[$k]['minimum'] < 1) {
				$products[$k]['minimum'] = isset($rows[$id]['minimum']) && (int)$rows[$id]['minimum'] > 0 ? (int)$rows[$id]['minimum'] : 1;
			}

			if (isset($wh[$id]) && ($wh[$id][0] > 0 || $wh[$id][1] > 0)) {
				$lines = $this->warehouseLines($wh[$id][0], $wh[$id][1]);
				$products[$k]['vm_stock_lines'] = $lines;
				$products[$k]['vm_stock'] = $lines[0]['text'] . ($lines[0]['class'] === 'wait' ? ' · ' . $lines[0]['note'] : '');
				$products[$k]['vm_stock_class'] = $lines[0]['class'];
			}
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


	private function hasTable($name) {
		static $cache = array();

		if (!isset($cache[$name])) {
			$cache[$name] = (bool)$this->db->query("SHOW TABLES LIKE '" . $this->db->escape(DB_PREFIX . $name) . "'")->num_rows;
		}

		return $cache[$name];
	}

	private static function partKey($s) {
		return mb_substr(mb_strtoupper(preg_replace('/[^\p{L}\p{N}]+/u', '', (string)$s), 'UTF-8'), 0, 64, 'UTF-8');
	}

	// product cards in the same shape as OpenCart's category/search controllers build them
	private function cards($ids) {
		$this->load->model('catalog/product');
		$this->load->model('tool/image');
		$theme = $this->config->get('config_theme');
		$w = (int)$this->config->get('theme_' . $theme . '_image_product_width') ?: 228;
		$h = (int)$this->config->get('theme_' . $theme . '_image_product_height') ?: 228;
		$out = array();

		foreach ($ids as $id) {
			$p = $this->model_catalog_product->getProduct((int)$id);

			if (!$p) {
				continue;
			}

			$tax = $this->config->get('config_tax');
			$price = ($this->customer->isLogged() || !$this->config->get('config_customer_price')) ? $this->currency->format($this->tax->calculate($p['price'], $p['tax_class_id'], $tax), $this->session->data['currency']) : false;
			$special = ((float)$p['special']) ? $this->currency->format($this->tax->calculate($p['special'], $p['tax_class_id'], $tax), $this->session->data['currency']) : false;

			$out[] = array(
				'product_id'  => $p['product_id'],
				'thumb'       => $this->model_tool_image->resize($p['image'] ? $p['image'] : 'placeholder.png', $w, $h),
				'name'        => $p['name'],
				'description' => '',
				'price'       => $price,
				'special'     => $special,
				'tax'         => false,
				'minimum'     => $p['minimum'] > 0 ? $p['minimum'] : 1,
				'rating'      => $p['rating'],
				'href'        => $this->url->link('product/product', 'product_id=' . $p['product_id'])
			);
		}

		return $out;
	}

	// "0 986 479 B15", "GHP9-33-25XA": find products by OE / cross numbers from BM Parts
	private function searchByOe(&$data) {
		$term = isset($this->request->get['search']) ? $this->request->get['search'] : (isset($this->request->get['tag']) ? $this->request->get['tag'] : '');
		$key = self::partKey(html_entity_decode($term, ENT_QUOTES, 'UTF-8'));

		if (mb_strlen($key, 'UTF-8') < 4 || !$this->hasTable('vm_catalog_oe')) {
			return;
		}

		$have = array();

		foreach ((array)(isset($data['products']) ? $data['products'] : array()) as $p) {
			$have[(int)$p['product_id']] = true;
		}

		$ids = array();
		$q = $this->db->query("SELECT DISTINCT o.product_id FROM `" . DB_PREFIX . "vm_catalog_oe` o JOIN `" . DB_PREFIX . "product` p ON (p.product_id = o.product_id AND p.status = 1) WHERE o.oe_key = '" . $this->db->escape($key) . "' LIMIT 40");

		foreach ($q->rows as $r) {
			if (!isset($have[(int)$r['product_id']])) {
				$ids[] = (int)$r['product_id'];
			}
		}

		if ($ids) {
			$data['products'] = array_merge(isset($data['products']) && is_array($data['products']) ? $data['products'] : array(), $this->cards($ids));
			$data['vm_oe_found'] = count($ids);
		}
	}

	// vehicles from BM: [{brand, models:[{model, engines:[...]}]}] -> makes/models/engines for the 3-column tab
	private function compatTree($json) {
		$cars = json_decode((string)$json, true);
		$tree = array();

		if (!is_array($cars)) {
			return $tree;
		}

		$str = function ($v) {
			if (is_array($v)) {
				foreach (array('name', 'title', 'model', 'engine', 'value') as $k) {
					if (isset($v[$k]) && is_scalar($v[$k]) && trim((string)$v[$k]) !== '') {
						$main = trim((string)$v[$k]);
						$more = array();

						foreach (array('years', 'year', 'period', 'power', 'kw', 'hp', 'volume', 'fuel', 'code', 'engine_code') as $x) {
							if ($x !== $k && isset($v[$x]) && is_scalar($v[$x]) && trim((string)$v[$x]) !== '') {
								$more[] = trim((string)$v[$x]);
							}
						}

						return $main . ($more ? ' · ' . implode(' · ', $more) : '');
					}
				}

				return implode(' ', array_filter(array_map(function ($x) { return is_scalar($x) ? (string)$x : ''; }, $v)));
			}

			return trim((string)$v);
		};

		foreach ($cars as $car) {
			if (!is_array($car)) {
				continue;
			}

			$make = $str(isset($car['brand']) ? $car['brand'] : (isset($car['name']) ? $car['name'] : ''));

			if ($make === '') {
				continue;
			}

			$models = array();

			foreach ((isset($car['models']) && is_array($car['models']) ? $car['models'] : array()) as $m) {
				$name = $str(is_array($m) && isset($m['model']) ? $m['model'] : $m);
				$engines = array();

				foreach ((is_array($m) && isset($m['engines']) && is_array($m['engines']) ? $m['engines'] : array()) as $e) {
					$e = $str($e);

					if ($e !== '') {
						$engines[] = $e;
					}
				}

				if ($name !== '') {
					$models[] = array('name' => $name, 'engines' => array_values(array_unique($engines)));
				}
			}

			$tree[] = array('name' => $make, 'models' => $models);
		}

		usort($tree, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });

		return $tree;
	}

	private function productExtras(&$data) {
		$id = (int)$data['product_id'];
		$t = $this->texts();
		$data['vm_compat'] = array();
		$data['vm_oe'] = array();
		$data['vm_analogs'] = array();
		$data['vm_analogs_more'] = 0;
		$data['vm_chars'] = array();
		$extra = array();
		$node = '';

		if ($this->hasTable('vm_catalog_enrich')) {
			$e = $this->db->query("SELECT * FROM `" . DB_PREFIX . "vm_catalog_enrich` WHERE product_id = '" . $id . "' AND status = 1")->row;

			if ($e) {
				$data['vm_compat'] = $this->compatTree($e['cars_json']);
				$extra = isset($e['extra_json']) ? (array)json_decode($e['extra_json'], true) : array();
				$node = (string)$e['node'];
			}
		}

		if ($this->hasTable('vm_catalog_oe')) {
			foreach ($this->db->query("SELECT oe FROM `" . DB_PREFIX . "vm_catalog_oe` WHERE product_id = '" . $id . "' ORDER BY oe LIMIT 600")->rows as $r) {
				$data['vm_oe'][] = array('oe' => $r['oe'], 'href' => $this->url->link('product/search', 'search=' . urlencode($r['oe'])));
			}
		}

		if ($this->hasTable('vm_catalog_analog')) {
			$all = (int)$this->db->query("SELECT COUNT(*) AS n FROM `" . DB_PREFIX . "vm_catalog_analog` WHERE product_id = '" . $id . "'")->row['n'];

			if ($all && $this->hasTable('vm_catalog_item')) {
				$rows = $this->db->query("SELECT DISTINCT i.product_id FROM `" . DB_PREFIX . "vm_catalog_analog` a
					JOIN `" . DB_PREFIX . "vm_catalog_item` i ON (i.art_key = a.art_key)
					JOIN `" . DB_PREFIX . "product` p ON (p.product_id = i.product_id AND p.status = 1)
					WHERE a.product_id = '" . $id . "' AND i.product_id <> '" . $id . "' LIMIT 24")->rows;
				$ids = array();

				foreach ($rows as $r) {
					$ids[] = (int)$r['product_id'];
				}

				if ($ids) {
					$data['vm_analogs'] = $this->enrichProducts($this->cards($ids));
				}

				$data['vm_analogs_more'] = max(0, $all - count($data['vm_analogs']));
			}
		}

		// characteristics: shop data + supplier data
		$p = $data;
		$chars = array();

		if (!empty($p['manufacturer'])) {
			$chars[] = array($t['text_ch_brand'], $p['manufacturer']);
		}

		if (!empty($p['model'])) {
			$chars[] = array($t['text_ch_article'], $p['model']);
		}

		if ($node !== '') {
			$chars[] = array($t['text_ch_group'], mb_convert_case(mb_strtolower(str_replace('/', ' / ', $node), 'UTF-8'), MB_CASE_TITLE, 'UTF-8'));
		}

		if (!empty($extra['weight'])) {
			$chars[] = array($t['text_ch_weight'], rtrim(rtrim(number_format((float)$extra['weight'], 3, '.', ''), '0'), '.') . ' ' . $t['text_ch_kg']);
		}

		if (!empty($extra['barcodes'])) {
			$chars[] = array($t['text_ch_barcode'], implode(', ', (array)$extra['barcodes']));
		}

		if (!empty($extra['multiplicity']) && (int)$extra['multiplicity'] > 1) {
			$chars[] = array($t['text_ch_mult'], sprintf($t['text_ch_mult_v'], (int)$extra['multiplicity']));
		}

		if ($data['vm_compat']) {
			$models = 0;

			foreach ($data['vm_compat'] as $mk) {
				$models += count($mk['models']);
			}

			$chars[] = array($t['text_ch_cars'], sprintf($t['text_ch_cars_v'], count($data['vm_compat']), $models));
		}

		if ($data['vm_oe']) {
			$chars[] = array($t['text_ch_oe'], count($data['vm_oe']));
		}

		$data['vm_chars'] = $chars;
		$data['vm_images_all'] = array();

		if (!empty($data['popup'])) {
			$data['vm_images_all'][] = array('popup' => $data['popup'], 'thumb' => $data['thumb']);
		}

		foreach ((array)(isset($data['images']) ? $data['images'] : array()) as $img) {
			$data['vm_images_all'][] = $img;
		}
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

		$this->productExtras($data);
	}
}
