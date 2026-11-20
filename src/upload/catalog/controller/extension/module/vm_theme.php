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

	private function cartSum() {
		$total = 0;

		foreach ($this->cart->getProducts() as $product) {
			$total += $this->tax->calculate($product['price'], $product['tax_class_id'], $this->config->get('config_tax')) * $product['quantity'];
		}

		if (!empty($this->session->data['vouchers'])) {
			foreach ($this->session->data['vouchers'] as $voucher) {
				$total += $voucher['amount'];
			}
		}

		return $this->currency->format($total, $this->session->data['currency']);
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
				$this->carBar($data);
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
			case 'common/cart':
				$data['vm_cart_count'] = $this->cartCount();
				$data['vm_cart_sum'] = $this->cartSum();
				break;
			case 'common/search':
				$data['vm_search_cats'] = $this->menu();
				$data['vm_search_cat'] = isset($this->request->get['category_id']) ? (int)$this->request->get['category_id'] : 0;
				break;
			case 'account/account':
				$this->accountPage($data);
				break;
			case 'common/home':
				$data['vm_menu'] = $this->menu();
				$data['vm_garage'] = $this->garage();
				$data['vm_carbar_html'] = ($this->vmTablesExist() && $this->carMakesData()) ? $this->carBarHtml(false) : '';
				$this->homeSections($data);
				break;
			case 'product/category':
				if (empty($data['vm_car_page']) && !isset($data['vm_filters']) && isset($this->request->get['path'])) {
					$fpath = '';
					$fcat = $this->categoryId($fpath);

					if ($fcat && !empty($data['products'])) {
						$data['vm_filters'] = $this->filterPanel($fcat, $fpath, $this->filterSel());
						$data['column_left'] = '';
					}
				}

				if (empty($data['vm_car_page'])) {
					$data['vm_garage'] = $this->garage();
					$path = isset($this->request->get['path']) ? explode('_', (string)$this->request->get['path']) : array();
					$cid = (int)end($path);

					if ($data['vm_garage'] && $cid) {
						$data['vm_garage_cat'] = $data['vm_garage']['href'] . '&cat=' . $cid;
					}
				}
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
				'id'       => (int)$category['category_id'],
				'name'     => $category['name'],
				'href'     => $this->url->link('product/category', 'path=' . $category['category_id']),
				'image'    => $category['image'] ? $this->model_tool_image->resize($category['image'], 160, 120) : '',
				'icon'     => self::catIcon($category['name']),
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
				$fits[(int)$row['product_id']] = self::prettyName($row['make']) . ' ' . self::prettyName($row['model']) . $years;
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
					'make'  => self::prettyName($row['make']),
					'model' => self::prettyName($row['model']),
					'years' => $row['year_from'] ? $row['year_from'] . '–' . ($row['year_to'] ? $row['year_to'] : $this->texts()['text_now']) : $this->texts()['text_all_years']
				);
			}
		}

		$this->productExtras($data);

		// the visitor's car is known, the product has a fitment list, and the car is not in it
		$data['vm_nofit'] = '';
		$garage = $this->garage();

		if ($garage && empty($data['vm_product']['vm_fit']) && ($data['vm_fits'] || !empty($data['vm_compat']))) {
			$data['vm_nofit'] = $garage['label'];
		}

		$data['vm_garage'] = $garage;
	}

	// =============================================================== category filters (1.5.0)
	// The native category page shows the filter panel; applying filters opens route vm_theme/catalog,
	// which lists the same category with the conditions applied.

	private function filterSel() {
		$g = $this->request->get;
		$sel = array('mf' => array(), 'st' => array(), 'pmin' => '', 'pmax' => '', 'car' => 0, 'a' => array());

		if (!empty($g['mf'])) {
			$sel['mf'] = array_values(array_filter(array_map('intval', explode(',', (string)$g['mf']))));
		}

		if (!empty($g['st'])) {
			$sel['st'] = array_values(array_intersect(explode(',', (string)$g['st']), array('in', 'wait')));
		}

		foreach (array('pmin', 'pmax') as $k) {
			if (isset($g[$k]) && $g[$k] !== '' && is_numeric($g[$k])) {
				$sel[$k] = max(0, (float)$g[$k]);
			}
		}

		$sel['car'] = !empty($g['car']) ? 1 : 0;

		if (!empty($g['a']) && is_array($g['a'])) {
			foreach ($g['a'] as $aid => $vals) {
				$vals = array_values(array_filter(array_map(function ($v) {
					return mb_substr(trim(html_entity_decode((string)$v, ENT_QUOTES, 'UTF-8')), 0, 128);
				}, explode('|', (string)$vals)), 'strlen'));

				if ((int)$aid > 0 && $vals) {
					$sel['a'][(int)$aid] = array_slice($vals, 0, 20);
				}
			}
		}

		return $sel;
	}

	private function filterActive($sel) {
		return $sel['mf'] || $sel['st'] || $sel['pmin'] !== '' || $sel['pmax'] !== '' || $sel['car'] || $sel['a'];
	}

	// SQL conditions on alias p; $skip = group to leave out (for facet counts)
	private function filterWhere($cat_id, $sel, $skip = '') {
		$p = DB_PREFIX;
		$w = array(
			"p.status = '1'",
			"p.date_available <= NOW()",
			"p.product_id IN (SELECT p2s.product_id FROM `{$p}product_to_store` p2s WHERE p2s.store_id = '" . (int)$this->config->get('config_store_id') . "')",
			"p.product_id IN (SELECT p2c.product_id FROM `{$p}product_to_category` p2c INNER JOIN `{$p}category_path` cp ON (cp.category_id = p2c.category_id) WHERE cp.path_id = '" . (int)$cat_id . "')"
		);

		if ($skip !== 'mf' && $sel['mf']) {
			$w[] = "p.manufacturer_id IN (" . implode(',', array_map('intval', $sel['mf'])) . ")";
		}

		if ($skip !== 'st' && count($sel['st']) == 1) {
			$w[] = $sel['st'][0] === 'in' ? "p.quantity > 0" : "p.quantity <= 0";
		}

		if ($skip !== 'price') {
			if ($sel['pmin'] !== '') {
				$w[] = "p.price >= '" . (float)$sel['pmin'] . "'";
			}

			if ($sel['pmax'] !== '') {
				$w[] = "p.price <= '" . (float)$sel['pmax'] . "'";
			}
		}

		if ($skip !== 'car' && $sel['car'] && ($g = $this->garage())) {
			$cond = "pv.model_id = '" . (int)$g['model_id'] . "'";

			if ($g['year']) {
				$cond .= " AND (pv.year_from = 0 OR pv.year_from <= '" . (int)$g['year'] . "') AND (pv.year_to = 0 OR pv.year_to >= '" . (int)$g['year'] . "')";
			}

			$w[] = "p.product_id IN (SELECT pv.product_id FROM `{$p}vm_product_vehicle` pv WHERE " . $cond . ")";
		}

		if ($skip !== 'a') {
			foreach ($sel['a'] as $aid => $vals) {
				$in = implode(',', array_map(function ($v) {
					return "'" . $this->db->escape($v) . "'";
				}, $vals));
				$w[] = "p.product_id IN (SELECT pa.product_id FROM `{$p}product_attribute` pa WHERE pa.attribute_id = '" . (int)$aid . "' AND pa.language_id = '" . (int)$this->config->get('config_language_id') . "' AND pa.text IN (" . $in . "))";
			}
		}

		return implode(' AND ', $w);
	}

	private function filterUrl($cat_path, $sel, $drop = array()) {
		$q = array('path' => $cat_path);

		if ($sel['mf']) {
			$q['mf'] = implode(',', $sel['mf']);
		}

		if ($sel['st']) {
			$q['st'] = implode(',', $sel['st']);
		}

		foreach (array('pmin', 'pmax') as $k) {
			if ($sel[$k] !== '') {
				$q[$k] = $sel[$k];
			}
		}

		if ($sel['car']) {
			$q['car'] = 1;
		}

		foreach ($sel['a'] as $aid => $vals) {
			$q['a[' . $aid . ']'] = implode('|', $vals);
		}

		foreach ($drop as $k) {
			unset($q[$k]);
		}

		$has = count($q) > 1;
		$args = array();

		foreach ($q as $k => $v) {
			$args[] = str_replace(array('%5B', '%5D'), array('[', ']'), rawurlencode($k)) . '=' . rawurlencode($v);
		}

		return $this->url->link($has ? 'extension/module/vm_theme/catalog' : 'product/category', implode('&', $args));
	}

	// facet data for the panel
	private function filterPanel($cat_id, $cat_path, $sel) {
		$p = DB_PREFIX;
		$t = $this->texts();
		$lang = (int)$this->config->get('config_language_id');
		$active = $this->filterActive($sel);
		$ckey = 'vm_filter.' . (int)$cat_id . '.' . $lang . '.' . (int)$this->config->get('config_store_id');
		$cached = $active ? null : $this->cache->get($ckey);

		if (!is_array($cached)) {
			$f = array();

			$r = $this->db->query("SELECT MIN(p.price) AS mn, MAX(p.price) AS mx, COUNT(*) AS n FROM `{$p}product` p WHERE " . $this->filterWhere($cat_id, $sel, 'price'))->row;
			$f['price'] = array('min' => floor((float)$r['mn']), 'max' => ceil((float)$r['mx']), 'n' => (int)$r['n']);

			$r = $this->db->query("SELECT SUM(p.quantity > 0) AS i, SUM(p.quantity <= 0) AS w FROM `{$p}product` p WHERE " . $this->filterWhere($cat_id, $sel, 'st'))->row;
			$f['stock'] = array('in' => (int)$r['i'], 'wait' => (int)$r['w']);

			$f['mf'] = $this->db->query("SELECT m.manufacturer_id AS id, m.name, COUNT(*) AS n FROM `{$p}product` p INNER JOIN `{$p}manufacturer` m ON (m.manufacturer_id = p.manufacturer_id)
				WHERE " . $this->filterWhere($cat_id, $sel, 'mf') . " GROUP BY m.manufacturer_id ORDER BY n DESC LIMIT 300")->rows;

			// attributes with at least two values in this selection
			$rows = $this->db->query("SELECT pa.attribute_id AS aid, ad.name AS aname, pa.text AS val, COUNT(*) AS n FROM `{$p}product_attribute` pa
				INNER JOIN `{$p}product` p ON (p.product_id = pa.product_id)
				INNER JOIN `{$p}attribute_description` ad ON (ad.attribute_id = pa.attribute_id AND ad.language_id = '" . $lang . "')
				WHERE pa.language_id = '" . $lang . "' AND pa.text <> '' AND " . $this->filterWhere($cat_id, $sel, 'a') . "
				GROUP BY pa.attribute_id, pa.text ORDER BY n DESC LIMIT 2000")->rows;
			$attrs = array();

			foreach ($rows as $row) {
				$aid = (int)$row['aid'];

				if (!isset($attrs[$aid])) {
					$attrs[$aid] = array('id' => $aid, 'name' => $row['aname'], 'cover' => 0, 'values' => array());
				}

				$attrs[$aid]['cover'] += (int)$row['n'];
				$attrs[$aid]['values'][] = array('v' => html_entity_decode($row['val'], ENT_QUOTES, 'UTF-8'), 'n' => (int)$row['n']);
			}

			$attrs = array_filter($attrs, function ($a) {
				return count($a['values']) >= 2 && count($a['values']) <= 150;
			});
			uasort($attrs, function ($a, $b) {
				return $b['cover'] - $a['cover'];
			});
			$f['attrs'] = array_slice(array_values($attrs), 0, 12);

			if ($this->garage()) {
				$f['car'] = (int)$this->db->query("SELECT COUNT(*) AS n FROM `{$p}product` p WHERE " . $this->filterWhere($cat_id, array_merge($sel, array('car' => 1)), ''))->row['n'];
			}

			$cached = $f;

			if (!$active) {
				$this->cache->set($ckey, $f);
			}
		}

		$f = $cached;
		$panel = array('action' => html_entity_decode($this->url->link('extension/module/vm_theme/catalog'), ENT_QUOTES, 'UTF-8'), 'path' => $cat_path, 'groups' => array(), 'chips' => array(), 'active' => $active, 'count' => 0);
		$panel['reset'] = $this->url->link('product/category', 'path=' . $cat_path);
		$panel['price'] = $f['price'] + array('from' => $sel['pmin'] !== '' ? $sel['pmin'] : '', 'to' => $sel['pmax'] !== '' ? $sel['pmax'] : '');

		if ($sel['pmin'] !== '' || $sel['pmax'] !== '') {
			$panel['chips'][] = array('text' => $t['text_f_price'] . ': ' . ($sel['pmin'] !== '' ? $sel['pmin'] : $f['price']['min']) . '–' . ($sel['pmax'] !== '' ? $sel['pmax'] : $f['price']['max']), 'href' => $this->filterUrl($cat_path, array_merge($sel, array('pmin' => '', 'pmax' => ''))));
		}

		$garage = $this->garage();

		if ($garage && !empty($f['car'])) {
			$panel['car'] = array('label' => $garage['label'], 'n' => $f['car'], 'on' => $sel['car']);

			if ($sel['car']) {
				$panel['chips'][] = array('text' => $garage['label'], 'href' => $this->filterUrl($cat_path, array_merge($sel, array('car' => 0))));
			}
		}

		$opts = array();

		foreach (array('in' => $t['text_f_in'], 'wait' => $t['text_f_wait']) as $k => $label) {
			if ($f['stock'][$k] > 0 || in_array($k, $sel['st'], true)) {
				$on = in_array($k, $sel['st'], true);
				$opts[] = array('name' => 'st', 'value' => $k, 'label' => $label, 'n' => $f['stock'][$k], 'on' => $on);

				if ($on) {
					$panel['chips'][] = array('text' => $label, 'href' => $this->filterUrl($cat_path, array_merge($sel, array('st' => array_values(array_diff($sel['st'], array($k)))))));
				}
			}
		}

		if (count($opts) > 1 || $sel['st']) {
			$panel['groups'][] = array('key' => 'st', 'title' => $t['text_f_stock'], 'opts' => $opts, 'search' => false);
		}

		$opts = array();

		foreach ($f['mf'] as $m) {
			$on = in_array((int)$m['id'], $sel['mf'], true);
			$opts[] = array('name' => 'mf', 'value' => (int)$m['id'], 'label' => html_entity_decode($m['name'], ENT_QUOTES, 'UTF-8'), 'n' => (int)$m['n'], 'on' => $on);

			if ($on) {
				$panel['chips'][] = array('text' => html_entity_decode($m['name'], ENT_QUOTES, 'UTF-8'), 'href' => $this->filterUrl($cat_path, array_merge($sel, array('mf' => array_values(array_diff($sel['mf'], array((int)$m['id'])))))));
			}
		}

		if (count($opts) > 1 || $sel['mf']) {
			usort($opts, function ($a, $b) {
				return ($b['on'] - $a['on']) ? $b['on'] - $a['on'] : $b['n'] - $a['n'];
			});
			$panel['groups'][] = array('key' => 'mf', 'title' => $t['text_f_brand'], 'opts' => $opts, 'search' => count($opts) > 10);
		}

		foreach ($f['attrs'] as $a) {
			$opts = array();
			$chosen = isset($sel['a'][$a['id']]) ? $sel['a'][$a['id']] : array();

			foreach ($a['values'] as $v) {
				$on = in_array($v['v'], $chosen, true);
				$opts[] = array('name' => 'a[' . $a['id'] . ']', 'value' => $v['v'], 'label' => $v['v'], 'n' => $v['n'], 'on' => $on);

				if ($on) {
					$rest = $sel['a'];
					$rest[$a['id']] = array_values(array_diff($chosen, array($v['v'])));

					if (!$rest[$a['id']]) {
						unset($rest[$a['id']]);
					}

					$panel['chips'][] = array('text' => $a['name'] . ': ' . $v['v'], 'href' => $this->filterUrl($cat_path, array_merge($sel, array('a' => $rest))));
				}
			}

			usort($opts, function ($x, $y) {
				return ($y['on'] - $x['on']) ? $y['on'] - $x['on'] : $y['n'] - $x['n'];
			});
			$panel['groups'][] = array('key' => 'a' . $a['id'], 'attr' => $a['id'], 'title' => $a['name'], 'opts' => $opts, 'search' => count($opts) > 10);
		}

		$panel['count'] = count($panel['chips']);

		return $panel;
	}

	private function categoryId(&$path) {
		$parts = isset($this->request->get['path']) ? array_filter(array_map('intval', explode('_', (string)$this->request->get['path']))) : array();
		$path = implode('_', $parts);

		return $parts ? (int)end($parts) : 0;
	}

	// filtered listing of a category
	public function catalog() {
		$path = '';
		$cat_id = $this->categoryId($path);
		$this->load->model('catalog/category');
		$category = $cat_id ? $this->model_catalog_category->getCategory($cat_id) : array();

		if (!$category) {
			$this->response->redirect($this->url->link('common/home'));

			return;
		}

		$sel = $this->filterSel();

		if (!$this->filterActive($sel)) {
			$this->response->redirect($this->url->link('product/category', 'path=' . $path));

			return;
		}

		$this->load->language('product/category');
		$this->load->model('catalog/product');
		$this->load->model('tool/image');

		$t = $this->texts();
		$theme = $this->config->get('config_theme');
		$p = DB_PREFIX;
		$where = $this->filterWhere($cat_id, $sel);

		$sort = isset($this->request->get['sort']) ? (string)$this->request->get['sort'] : 'p.sort_order';
		$order = (isset($this->request->get['order']) && strtoupper($this->request->get['order']) === 'DESC') ? 'DESC' : 'ASC';
		$page = isset($this->request->get['page']) ? max(1, (int)$this->request->get['page']) : 1;
		$limit = isset($this->request->get['limit']) ? min(100, max(1, (int)$this->request->get['limit'])) : 0;

		if (!$limit) {
			$limit = (int)$this->config->get('theme_' . $theme . '_product_limit');
			$limit = $limit > 0 ? $limit : 24;
		}

		$sorts = array('p.sort_order' => 'p.sort_order', 'pd.name' => 'LCASE(pd.name)', 'p.price' => 'p.price', 'p.date_added' => 'p.date_added');
		$sort_sql = isset($sorts[$sort]) ? $sorts[$sort] : 'p.sort_order';

		$total = (int)$this->db->query("SELECT COUNT(*) AS total FROM `{$p}product` p WHERE " . $where)->row['total'];
		$ids = array();

		foreach ($this->db->query("SELECT p.product_id FROM `{$p}product` p LEFT JOIN `{$p}product_description` pd ON (pd.product_id = p.product_id AND pd.language_id = '" . (int)$this->config->get('config_language_id') . "') WHERE " . $where . " ORDER BY (p.quantity > 0) DESC, " . $sort_sql . " " . $order . ", LCASE(pd.name) ASC LIMIT " . (($page - 1) * $limit) . "," . $limit)->rows as $r) {
			$ids[] = (int)$r['product_id'];
		}

		$data['products'] = $this->cards($ids);

		$data['breadcrumbs'] = array(array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/home')));
		$acc = array();

		foreach (explode('_', $path) as $pid) {
			$acc[] = $pid;
			$c = $this->model_catalog_category->getCategory((int)$pid);

			if ($c) {
				$data['breadcrumbs'][] = array('text' => $c['name'], 'href' => $this->url->link('product/category', 'path=' . implode('_', $acc)));
			}
		}

		$this->document->setTitle($category['name'] . ' — ' . $this->config->get('config_name'));
		$this->document->addLink($this->url->link('product/category', 'path=' . $path), 'canonical');

		$data['heading_title'] = $category['name'];
		$data['thumb'] = '';
		$data['description'] = '';
		$data['categories'] = array();
		$data['text_compare'] = sprintf($this->language->get('text_compare'), isset($this->session->data['compare']) ? count($this->session->data['compare']) : 0);
		$data['compare'] = $this->url->link('product/compare');

		$base = $this->filterUrl($path, $sel);
		$base_q = html_entity_decode(parse_url($base, PHP_URL_QUERY), ENT_QUOTES, 'UTF-8');
		$base_q = preg_replace('/(^|&)route=[^&]*/', '', $base_q);
		$link = function ($extra) use ($base_q) {
			return $this->url->link('extension/module/vm_theme/catalog', ltrim($base_q, '&') . $extra);
		};

		$data['sorts'] = array();

		foreach (array(array('p.sort_order', 'ASC', 'text_default'), array('pd.name', 'ASC', 'text_name_asc'), array('pd.name', 'DESC', 'text_name_desc'), array('p.price', 'ASC', 'text_price_asc'), array('p.price', 'DESC', 'text_price_desc')) as $s) {
			$data['sorts'][] = array('text' => $this->language->get($s[2]), 'value' => $s[0] . '-' . $s[1], 'href' => $link('&sort=' . $s[0] . '&order=' . $s[1]));
		}

		$data['limits'] = array();

		foreach (array_unique(array($limit, 25, 50, 75, 100)) as $v) {
			$data['limits'][] = array('text' => $v, 'value' => $v, 'href' => $link('&sort=' . $sort . '&order=' . $order . '&limit=' . $v));
		}

		$pagination = new Pagination();
		$pagination->total = $total;
		$pagination->page = $page;
		$pagination->limit = $limit;
		$pagination->url = $link('&sort=' . $sort . '&order=' . $order . '&limit=' . $limit . '&page={page}');

		$data['pagination'] = $pagination->render();
		$data['results'] = sprintf($this->language->get('text_pagination'), $total ? (($page - 1) * $limit) + 1 : 0, ((($page - 1) * $limit) > ($total - $limit)) ? $total : ((($page - 1) * $limit) + $limit), $total, ceil($total / $limit));
		$data['sort'] = $sort;
		$data['order'] = $order;
		$data['limit'] = $limit;
		$data['text_empty'] = $t['text_f_empty'];
		$data['continue'] = $this->url->link('product/category', 'path=' . $path);
		$data['vm_filters'] = $this->filterPanel($cat_id, $path, $sel);
		$data['vm_filter_total'] = $total;

		$data['column_left'] = '';
		$data['column_right'] = '';
		$data['content_top'] = '';
		$data['content_bottom'] = '';
		$data['footer'] = $this->load->controller('common/footer');
		$data['header'] = $this->load->controller('common/header');

		$this->response->setOutput($this->load->view('product/category', $data));
	}

	// =============================================================== account dashboard (1.4.1)
	private function accountPage(&$data) {
		if (!$this->customer->isLogged()) {
			return;
		}

		$this->load->model('account/order');
		$this->load->model('account/wishlist');

		$data['vm_acc_name'] = trim($this->customer->getFirstName());
		$data['vm_acc_email'] = $this->customer->getEmail();
		$data['vm_acc_logout'] = $this->url->link('account/logout', '', true);
		$data['vm_acc_orders_total'] = (int)$this->model_account_order->getTotalOrders();
		$data['vm_acc_wish_total'] = count((array)$this->model_account_wishlist->getWishlist());
		$data['vm_acc_cart_total'] = $this->cartCount();
		$data['vm_acc_cart'] = $this->url->link('checkout/cart');
		$menu = $this->menu();
		$data['vm_home'] = $menu ? $menu[0]['href'] : $this->url->link('common/home');
		$data['vm_acc_orders'] = array();

		foreach ((array)$this->model_account_order->getOrders(0, 3) as $o) {
			$data['vm_acc_orders'][] = array(
				'id'     => $o['order_id'],
				'date'   => date('d.m.Y', strtotime($o['date_added'])),
				'status' => $o['status'],
				'total'  => $this->currency->format($o['total'], $o['currency_code'], $o['currency_value']),
				'items'  => (int)$o['products'],
				'href'   => $this->url->link('account/order/info', 'order_id=' . $o['order_id'], true)
			);
		}
	}

	// =============================================================== home page sections (1.4.0)

	public static function catIcon($name) {
		$map = array(
			'підвіск|рульов|амортиз|подвес' => 'fa-life-ring', 'гальм|тормоз' => 'fa-circle-o-notch', 'фільтр|фильтр' => 'fa-filter',
			'охолодж|охлажд' => 'fa-snowflake-o', 'грм|ремен|ремн' => 'fa-cogs', 'двигун|двигат' => 'fa-cog', 'палив|топлив' => 'fa-tint',
			'вихлоп|выхлоп' => 'fa-cloud', 'датчик' => 'fa-tachometer', 'трансміс|трансмис|зчепл|сцепл' => 'fa-sliders', 'електр|электр|освітл|освещ|ламп' => 'fa-bolt',
			'кузов|склоочис|стеклоочис|дзеркал' => 'fa-car', 'олив|масл|хімі|хими|рідин|жидк' => 'fa-flask', 'аксесуар|аксессуар|інструмент|инструмент' => 'fa-wrench',
			'акці|акци|знижк|скидк' => 'fa-percent', 'шин|диск' => 'fa-dot-circle-o'
		);
		$n = mb_strtolower((string)$name, 'UTF-8');

		foreach ($map as $re => $icon) {
			if (preg_match('/' . $re . '/u', $n)) {
				return $icon;
			}
		}

		return 'fa-cubes';
	}

	private function hero() {
		$t = $this->texts();
		$slides = $this->config->get('module_vm_theme_hero');
		$out = array();

		if (is_array($slides)) {
			$this->load->model('tool/image');

			foreach ($slides as $s) {
				if (!is_array($s) || trim(isset($s['title']) ? $s['title'] : '') === '') {
					continue;
				}

				$img = !empty($s['image']) && is_file(DIR_IMAGE . $s['image']) ? $this->model_tool_image->resize($s['image'], 640, 440) : '';
				$out[] = array(
					'badge'  => isset($s['badge']) ? $s['badge'] : '',
					'title'  => nl2br($s['title']),
					'text'   => isset($s['text']) ? $s['text'] : '',
					'button' => !empty($s['button']) ? $s['button'] : $t['text_hero_button'],
					'link'   => !empty($s['link']) ? $s['link'] : '',
					'price'  => isset($s['price']) ? $s['price'] : '',
					'image'  => $img
				);
			}
		}

		if (!$out && $this->config->get('module_vm_theme_hero_off') !== '1') {
			$out[] = array('badge' => $t['text_hero_badge'], 'title' => $t['text_hero_title'], 'text' => $t['text_hero_text'], 'button' => $t['text_hero_button'], 'link' => '', 'price' => '', 'image' => '');
		}

		$menu = $this->menu();

		foreach ($out as $k => $s) {
			if ($s['link'] === '') {
				$out[$k]['link'] = $menu ? $menu[0]['href'] : $this->url->link('product/search', 'search=');
			}
		}

		return $out;
	}

	// product count per category (whole subtree), active products only; cached for an hour
	private function categoryCounts() {
		$key = 'vm_home.catcounts.' . (int)$this->config->get('config_store_id') . '.' . (int)$this->config->get('config_language_id');
		$data = $this->cache->get($key);

		if (is_array($data)) {
			return $data;
		}

		$p = DB_PREFIX;
		$rows = $this->db->query("SELECT cp.path_id AS category_id, MIN(cp.level) AS lvl, COUNT(DISTINCT p2c.product_id) AS total
			FROM `{$p}product_to_category` p2c
			INNER JOIN `{$p}product` pr ON (pr.product_id = p2c.product_id AND pr.status = '1')
			INNER JOIN `{$p}category_path` cp ON (cp.category_id = p2c.category_id)
			GROUP BY cp.path_id")->rows;

		$counts = array();

		foreach ($rows as $r) {
			$counts[(int)$r['category_id']] = (int)$r['total'];
		}

		$data = array();

		if ($counts) {
			$q = $this->db->query("SELECT c.category_id, c.parent_id, c.image, cd.name FROM `{$p}category` c
				INNER JOIN `{$p}category_to_store` c2s ON (c2s.category_id = c.category_id AND c2s.store_id = '" . (int)$this->config->get('config_store_id') . "')
				LEFT JOIN `{$p}category_description` cd ON (cd.category_id = c.category_id AND cd.language_id = '" . (int)$this->config->get('config_language_id') . "')
				WHERE c.status = '1' AND c.category_id IN (" . implode(',', array_keys($counts)) . ")");

			foreach ($q->rows as $r) {
				$data[] = array('id' => (int)$r['category_id'], 'parent' => (int)$r['parent_id'], 'name' => $r['name'], 'image' => $r['image'], 'count' => $counts[(int)$r['category_id']]);
			}
		}

		$this->cache->set($key, $data);

		return $data;
	}

	private static function isOther($name) {
		return (bool)preg_match('/^(інші|прочие|другие|other)/iu', trim((string)$name));
	}

	private function catHref($id, $parent) {
		return $this->url->link('product/category', 'path=' . ($parent ? $parent . '_' : '') . $id);
	}

	private function homeSections(&$data) {
		$t = $this->texts();
		$p = DB_PREFIX;

		$data['vm_hero'] = $this->hero();

		// hits: best sellers first, then fresh in-stock products with a photo
		$ids = array_slice(array_keys($this->hitIds()), 0, 10);

		if (count($ids) < 10) {
			$q = $this->db->query("SELECT p.product_id FROM `{$p}product` p INNER JOIN `{$p}product_to_store` p2s ON (p2s.product_id = p.product_id AND p2s.store_id = '" . (int)$this->config->get('config_store_id') . "')
				WHERE p.status = '1' AND p.quantity > 0 AND p.date_available <= NOW()" . ($ids ? " AND p.product_id NOT IN (" . implode(',', $ids) . ")" : '') . "
				ORDER BY (p.image <> '' AND p.image NOT LIKE '%placeholder%') DESC, p.date_added DESC LIMIT " . (10 - count($ids)));

			foreach ($q->rows as $r) {
				$ids[] = (int)$r['product_id'];
			}
		}

		$products = $ids ? $this->enrichProducts($this->cards($ids)) : array();
		$tabs = array();

		if ($ids) {
			$q = $this->db->query("SELECT p2c.product_id, cp.path_id, cd.name FROM `{$p}product_to_category` p2c
				INNER JOIN `{$p}category_path` cp ON (cp.category_id = p2c.category_id AND cp.level = 0)
				LEFT JOIN `{$p}category_description` cd ON (cd.category_id = cp.path_id AND cd.language_id = '" . (int)$this->config->get('config_language_id') . "')
				WHERE p2c.product_id IN (" . implode(',', $ids) . ")");
			$by = array();

			foreach ($q->rows as $r) {
				if (self::isOther($r['name'])) {
					continue;
				}

				$by[(int)$r['product_id']][(int)$r['path_id']] = (int)$r['path_id'];
				$tabs[(int)$r['path_id']]['name'] = $r['name'];
				$tabs[(int)$r['path_id']]['n'] = (isset($tabs[(int)$r['path_id']]['n']) ? $tabs[(int)$r['path_id']]['n'] : 0) + 1;
			}

			foreach ($products as $k => $pr) {
				$products[$k]['vm_cats'] = isset($by[(int)$pr['product_id']]) ? ' c' . implode(' c', $by[(int)$pr['product_id']]) : '';
			}

			uasort($tabs, function ($a, $b) {
				return $b['n'] - $a['n'];
			});
			$tabs = array_slice($tabs, 0, 5, true);
		}

		$data['vm_hits'] = $products;
		$data['vm_hit_tabs'] = array();

		foreach ($tabs as $id => $tab) {
			if ($tab['n'] > 0 && count($tabs) > 1) {
				$data['vm_hit_tabs'][] = array('id' => $id, 'name' => $tab['name']);
			}
		}

		// promo tiles and category wall from the busiest categories
		$cats = $this->categoryCounts();
		$tops = array_values(array_filter($cats, function ($c) {
			return !$c['parent'] && !self::isOther($c['name']);
		}));
		$subs = array_values(array_filter($cats, function ($c) {
			return $c['parent'] && !self::isOther($c['name']);
		}));
		$by_count = function ($a, $b) {
			return $b['count'] - $a['count'];
		};
		usort($tops, $by_count);
		usort($subs, $by_count);

		$data['vm_promos'] = array();

		foreach (array_slice($tops, 0, 3) as $i => $c) {
			$data['vm_promos'][] = array('name' => $c['name'], 'text' => sprintf($t['text_promo_count'], number_format($c['count'], 0, '', ' ')), 'href' => $this->catHref($c['id'], 0), 'tone' => $i, 'icon' => self::catIcon($c['name']));
		}

		$this->load->model('tool/image');
		$data['vm_wall'] = array();

		foreach (array_slice($subs ? $subs : $tops, 0, 12) as $c) {
			$data['vm_wall'][] = array(
				'name'  => $c['name'],
				'count' => $c['count'],
				'href'  => $this->catHref($c['id'], $c['parent']),
				'image' => ($c['image'] && is_file(DIR_IMAGE . $c['image'])) ? $this->model_tool_image->resize($c['image'], 200, 140) : '',
				'icon'  => self::catIcon($c['name'])
			);
		}

		// makes with parts
		$makes = $this->carMakesData();
		usort($makes, function ($a, $b) {
			return ($b['pop'] - $a['pop']) ? $b['pop'] - $a['pop'] : $b['count'] - $a['count'];
		});
		$data['vm_makes'] = array();

		foreach (array_slice($makes, 0, 18) as $m) {
			$data['vm_makes'][] = array('name' => $m['name'], 'letter' => mb_substr($m['name'], 0, 1, 'UTF-8'), 'count' => $m['count'], 'href' => $this->url->link('extension/module/vm_theme/car', 'make_id=' . $m['id']));
		}

		usort($data['vm_makes'], function ($a, $b) {
			return strnatcasecmp($a['name'], $b['name']);
		});
	}

	// =============================================================== car picker (1.3.0)
	// Makes / models / years come from vm_make, vm_model, vm_product_vehicle (filled by V&M Catalog from BM Parts).

	private static $popular = array('volkswagen', 'vw', 'skoda', 'renault', 'toyota', 'ford', 'opel', 'hyundai', 'kia', 'bmw', 'mercedes-benz', 'audi', 'nissan', 'chevrolet', 'daewoo', 'mitsubishi', 'mazda', 'peugeot', 'citroen', 'honda', 'fiat', 'lexus', 'dacia', 'subaru');

	public static function prettyName($name) {
		$name = trim((string)$name);

		if ($name === '') {
			return $name;
		}

		return preg_replace_callback('/[A-ZА-ЯІЇЄ]{4,}/u', function ($m) {
			if (preg_match('/^(?=[MDCLXVI])M*(C[MD]|D?C{0,3})(X[CL]|L?X{0,3})(I[XV]|V?I{0,3})$/', $m[0])) {
				return $m[0];
			}

			return mb_convert_case($m[0], MB_CASE_TITLE, 'UTF-8');
		}, $name);
	}

	// "GOLF IV Variant (1J5)" -> "GOLF", "LAND CRUISER 200 (_J20_)" -> "LAND CRUISER", "A4 B8 (8K2)" -> "A4"
	public static function modelFamily($name) {
		$base = trim(preg_replace('/\(.*$/u', '', (string)$name));
		$tokens = preg_split('/\s+/u', $base);
		$out = array();
		$body = '/^(variant|avant|turnier|kombi|combi|estate|wagon|touring|sportback|sportwagen|sw|break|bus|van|kasten|box|platform|pickup|pick-up|hatchback|sedan|saloon|liftback|coupe|coupé|cabrio|cabriolet|convertible|roadster|limousine|tourer|grandtour|universal|shooting|allroad|cross|plus|classic)$/iu';

		foreach ($tokens as $i => $tk) {
			if ($tk === '') {
				continue;
			}

			if ($i > 0 && (preg_match('/^(?=[MDCLXVI])M*(C[MD]|D?C{0,3})(X[CL]|L?X{0,3})(I[XV]|V?I{0,3})$/i', $tk) || preg_match('/^[A-Z]{0,2}\d/u', $tk) || preg_match($body, $tk))) {
				break;
			}

			$out[] = $tk;

			if (count($out) >= 3) {
				break;
			}
		}

		return $out ? implode(' ', $out) : $base;
	}

	private function carCacheKey($what) {
		return 'vm_car.' . $what . '.' . (int)$this->config->get('config_store_id');
	}

	private function carActiveJoin() {
		return " INNER JOIN `" . DB_PREFIX . "product` p ON (p.product_id = pv.product_id AND p.status = '1')
			INNER JOIN `" . DB_PREFIX . "product_to_store` p2s ON (p2s.product_id = p.product_id AND p2s.store_id = '" . (int)$this->config->get('config_store_id') . "')";
	}

	private function carMakesData() {
		if (!$this->vmTablesExist()) {
			return array();
		}

		$data = $this->cache->get($this->carCacheKey('makes'));

		if (is_array($data)) {
			return $data;
		}

		$rows = $this->db->query("SELECT mk.make_id, mk.name, COUNT(DISTINCT pv.product_id) AS total FROM `" . DB_PREFIX . "vm_product_vehicle` pv
			" . $this->carActiveJoin() . "
			INNER JOIN `" . DB_PREFIX . "vm_model` md ON (md.model_id = pv.model_id)
			INNER JOIN `" . DB_PREFIX . "vm_make` mk ON (mk.make_id = md.make_id AND mk.status = '1')
			GROUP BY mk.make_id")->rows;

		$data = array();

		foreach ($rows as $r) {
			$key = mb_strtolower($r['name'], 'UTF-8');
			$pop = array_search($key, self::$popular);
			$data[] = array('id' => (int)$r['make_id'], 'name' => self::prettyName($r['name']), 'count' => (int)$r['total'], 'pop' => $pop === false ? 0 : 100 - $pop);
		}

		usort($data, function ($a, $b) {
			return strnatcasecmp($a['name'], $b['name']);
		});

		$this->cache->set($this->carCacheKey('makes'), $data);

		return $data;
	}

	private function carModelsData($make_id) {
		$make_id = (int)$make_id;

		if (!$make_id || !$this->vmTablesExist()) {
			return array();
		}

		$data = $this->cache->get($this->carCacheKey('models.' . $make_id));

		if (is_array($data)) {
			return $data;
		}

		$rows = $this->db->query("SELECT md.model_id, md.name, MIN(NULLIF(pv.year_from, 0)) AS yf, MAX(pv.year_to) AS yt, SUM(pv.year_from > 0 AND pv.year_to = 0) AS open_end, COUNT(DISTINCT pv.product_id) AS total
			FROM `" . DB_PREFIX . "vm_product_vehicle` pv " . $this->carActiveJoin() . "
			INNER JOIN `" . DB_PREFIX . "vm_model` md ON (md.model_id = pv.model_id)
			WHERE md.make_id = '" . $make_id . "' GROUP BY md.model_id")->rows;

		$now = $this->texts()['text_now'];
		$data = array();

		foreach ($rows as $r) {
			$years = '';

			if ($r['yf']) {
				$years = $r['yf'] . '–' . (($r['open_end'] || !$r['yt']) ? $now : $r['yt']);
			}

			$name = self::prettyName($r['name']);
			$data[] = array('id' => (int)$r['model_id'], 'name' => $name, 'family' => self::prettyName(self::modelFamily($r['name'])), 'years' => $years, 'yf' => (int)$r['yf'], 'count' => (int)$r['total']);
		}

		usort($data, function ($a, $b) {
			$c = strnatcasecmp($a['family'], $b['family']);

			return $c ? $c : ($a['yf'] - $b['yf'] ? $a['yf'] - $b['yf'] : strnatcasecmp($a['name'], $b['name']));
		});

		$this->cache->set($this->carCacheKey('models.' . $make_id), $data);

		return $data;
	}

	private function carYearsData($model_id) {
		$model_id = (int)$model_id;

		if (!$model_id || !$this->vmTablesExist()) {
			return array();
		}

		$query = $this->db->query("SELECT DISTINCT pv.year_from, pv.year_to FROM `" . DB_PREFIX . "vm_product_vehicle` pv " . $this->carActiveJoin() . " WHERE pv.model_id = '" . $model_id . "' AND pv.year_from > 0");

		$current = (int)date('Y');
		$years = array();

		foreach ($query->rows as $row) {
			$from = max(1950, (int)$row['year_from']);
			$to = (int)$row['year_to'] ? min((int)$row['year_to'], $current + 1) : $current;

			for ($y = $from; $y <= $to && $y - $from < 80; $y++) {
				$years[$y] = $y;
			}
		}

		krsort($years);

		return array_values($years);
	}

	private function jsonOut($data) {
		$this->response->addHeader('Content-Type: application/json');
		$this->response->addHeader('Cache-Control: public, max-age=600');
		$this->response->setOutput(json_encode($data, JSON_UNESCAPED_UNICODE));
	}

	public function carMakes() {
		$this->jsonOut($this->carMakesData());
	}

	public function carModels() {
		$this->jsonOut($this->carModelsData(isset($this->request->get['make_id']) ? $this->request->get['make_id'] : 0));
	}

	public function carYears() {
		$this->jsonOut($this->carYearsData(isset($this->request->get['model_id']) ? $this->request->get['model_id'] : 0));
	}

	// cookie "make-model-year"; make only is allowed for the results page, the garage needs a model
	private function carCookie() {
		$c = array('make_id' => 0, 'model_id' => 0, 'year' => 0);

		if (!empty($this->request->cookie['vm_car'])) {
			$parts = array_map('intval', explode('-', (string)$this->request->cookie['vm_car']));
			$c['make_id'] = isset($parts[0]) ? $parts[0] : 0;
			$c['model_id'] = isset($parts[1]) ? $parts[1] : 0;
			$c['year'] = isset($parts[2]) ? $parts[2] : 0;
		}

		return $c;
	}

	// selection + human names, or false
	private function carInfo($make_id, $model_id = 0, $year = 0) {
		if (!$make_id || !$this->vmTablesExist()) {
			return false;
		}

		$make = $this->db->query("SELECT make_id, name FROM `" . DB_PREFIX . "vm_make` WHERE make_id = '" . (int)$make_id . "'")->row;

		if (!$make) {
			return false;
		}

		$info = array('make_id' => (int)$make_id, 'model_id' => 0, 'year' => 0, 'make' => self::prettyName($make['name']), 'model' => '', 'label' => self::prettyName($make['name']));

		if ($model_id) {
			$model = $this->db->query("SELECT model_id, name FROM `" . DB_PREFIX . "vm_model` WHERE model_id = '" . (int)$model_id . "' AND make_id = '" . (int)$make_id . "'")->row;

			if ($model) {
				$info['model_id'] = (int)$model_id;
				$info['model'] = self::prettyName($model['name']);
				$info['label'] .= ' ' . $info['model'];

				if ($year >= 1950 && $year <= (int)date('Y') + 1) {
					$info['year'] = (int)$year;
					$info['label'] .= ', ' . (int)$year;
				}
			}
		}

		$info['href'] = $this->url->link('extension/module/vm_theme/car', 'make_id=' . $info['make_id'] . ($info['model_id'] ? '&model_id=' . $info['model_id'] : '') . ($info['year'] ? '&year=' . $info['year'] : ''));

		return $info;
	}

	private function garage() {
		static $g = null;

		if ($g === null) {
			$c = $this->carCookie();
			$g = ($c['make_id'] && $c['model_id']) ? $this->carInfo($c['make_id'], $c['model_id'], $c['year']) : false;

			if ($g && !$g['model_id']) {
				$g = false;
			}
		}

		return $g;
	}

	// data for the picker bar + the JS config
	private function carBar(&$data, $sel = null) {
		if (!$this->vmTablesExist()) {
			return;
		}

		$t = $this->texts();
		$garage = $this->garage();

		if ($sel === null) {
			$sel = $garage ? $garage : array('make_id' => 0, 'model_id' => 0, 'year' => 0, 'make' => '', 'model' => '');
		}

		$data['vm_garage'] = $garage;
		$data['vm_carbar'] = array(
			'cfg' => json_encode(array(
				'sel'      => array('make_id' => (int)$sel['make_id'], 'model_id' => (int)$sel['model_id'], 'year' => (int)$sel['year'], 'make' => isset($sel['make']) ? $sel['make'] : '', 'model' => isset($sel['model']) ? $sel['model'] : ''),
				'garage'   => $garage ? $garage['label'] : '',
				'urls'     => array(
					'makes'   => html_entity_decode($this->url->link('extension/module/vm_theme/carMakes'), ENT_QUOTES, 'UTF-8'),
					'models'  => html_entity_decode($this->url->link('extension/module/vm_theme/carModels'), ENT_QUOTES, 'UTF-8'),
					'years'   => html_entity_decode($this->url->link('extension/module/vm_theme/carYears'), ENT_QUOTES, 'UTF-8'),
					'results' => html_entity_decode($this->url->link('extension/module/vm_theme/car'), ENT_QUOTES, 'UTF-8'),
					'clear'   => html_entity_decode($this->url->link('extension/module/vm_theme/carClear'), ENT_QUOTES, 'UTF-8')
				),
				't' => array(
					'make' => $t['text_car_make'], 'model' => $t['text_car_model'], 'year' => $t['text_car_year'],
					'all_years' => $t['text_car_all_years'], 'all_models' => $t['text_car_all_models'], 'popular' => $t['text_car_popular'], 'all_makes' => $t['text_car_all_makes'],
					'search_make' => $t['text_car_search_make'], 'search_model' => $t['text_car_search_model'], 'nothing' => $t['text_car_nothing'],
					'loading' => $t['text_car_loading'], 'show' => $t['text_car_show'], 'show_n' => $t['text_car_show_n'], 'parts' => $t['text_car_parts'], 'pick_make' => $t['text_car_pick_make'], 'back' => $t['text_car_back'], 'title' => $t['text_car_title'], 'forget' => $t['text_car_forget']
				)
			), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
		);
	}

	private function carBarHtml($compact, $label = '') {
		return $this->load->view('extension/module/vm_carbar', array(
			'carbar_label'   => $label,
			'vm_t'           => $this->texts(),
			'vm_garage'      => $this->garage(),
			'carbar_garage'  => !$compact,
			'carbar_compact' => $compact
		));
	}

	public function carClear() {
		setcookie('vm_car', '', time() - 3600, '/');

		$back = isset($this->request->server['HTTP_REFERER']) ? html_entity_decode($this->request->server['HTTP_REFERER'], ENT_QUOTES, 'UTF-8') : '';
		$host = parse_url((string)$this->config->get('config_url'), PHP_URL_HOST);

		if (!$back || parse_url($back, PHP_URL_HOST) !== $host || strpos($back, 'vm_theme/car') !== false) {
			$back = $this->url->link('common/home');
		}

		$this->response->redirect($back);
	}

	// results page: all parts for the chosen car, with category chips
	public function car() {
		$make_id = isset($this->request->get['make_id']) ? (int)$this->request->get['make_id'] : 0;
		$model_id = isset($this->request->get['model_id']) ? (int)$this->request->get['model_id'] : 0;
		$year = isset($this->request->get['year']) ? (int)$this->request->get['year'] : 0;
		$cat = isset($this->request->get['cat']) ? (int)$this->request->get['cat'] : 0;

		$info = $this->carInfo($make_id, $model_id, $year);

		if (!$info) {
			$this->response->redirect($this->url->link('common/home'));

			return;
		}

		$make_id = $info['make_id'];
		$model_id = $info['model_id'];
		$year = $info['year'];

		setcookie('vm_car', $make_id . '-' . $model_id . '-' . $year, time() + 86400 * 180, '/');
		$this->request->cookie['vm_car'] = $make_id . '-' . $model_id . '-' . $year;

		$this->load->language('product/category');
		$this->load->model('catalog/product');
		$this->load->model('tool/image');

		$t = $this->texts();
		$lang = (int)$this->config->get('config_language_id');
		$theme = $this->config->get('config_theme');
		$p = DB_PREFIX;

		$where = $model_id ? "pv.model_id = '" . $model_id . "'" : "pv.model_id IN (SELECT model_id FROM `{$p}vm_model` WHERE make_id = '" . $make_id . "')";

		if ($year) {
			$where .= " AND (pv.year_from = 0 OR pv.year_from <= '" . $year . "') AND (pv.year_to = 0 OR pv.year_to >= '" . $year . "')";
		}

		$from = " FROM `{$p}vm_product_vehicle` pv " . $this->carActiveJoin() . " LEFT JOIN `{$p}product_description` pd ON (pd.product_id = p.product_id AND pd.language_id = '" . $lang . "')";

		// category chips: top-level sections first, then subcategories of the chosen section
		$chips = array();
		$base = 'make_id=' . $make_id . ($model_id ? '&model_id=' . $model_id : '') . ($year ? '&year=' . $year : '');
		$cat_rows = $this->db->query("SELECT cp.path_id AS category_id, cp.level, COUNT(DISTINCT p.product_id) AS total" . $from . "
			INNER JOIN `{$p}product_to_category` p2c ON (p2c.product_id = p.product_id)
			INNER JOIN `{$p}category_path` cp ON (cp.category_id = p2c.category_id)
			WHERE " . $where . " GROUP BY cp.path_id, cp.level")->rows;

		$parent_of = array();
		$counts = array();

		foreach ($cat_rows as $r) {
			$counts[(int)$r['category_id']] = (int)$r['total'];
		}

		if ($counts) {
			$names = array();
			$q = $this->db->query("SELECT c.category_id, c.parent_id, cd.name FROM `{$p}category` c LEFT JOIN `{$p}category_description` cd ON (cd.category_id = c.category_id AND cd.language_id = '" . $lang . "') WHERE c.status = '1' AND c.category_id IN (" . implode(',', array_keys($counts)) . ")");

			foreach ($q->rows as $r) {
				$names[(int)$r['category_id']] = $r['name'];
				$parent_of[(int)$r['category_id']] = (int)$r['parent_id'];
			}

			$section = 0;

			if ($cat && isset($parent_of[$cat])) {
				$section = $parent_of[$cat] ? $parent_of[$cat] : $cat;
			}

			foreach ($names as $id => $name) {
				$is_top = empty($parent_of[$id]);

				if ($is_top || ($section && $parent_of[$id] == $section)) {
					$chips[] = array(
						'id'     => $id,
						'name'   => $name,
						'count'  => $counts[$id],
						'top'    => $is_top,
						'active' => ($id == $cat || ($is_top && $id == $section)),
						'href'   => $this->url->link('extension/module/vm_theme/car', $base . '&cat=' . $id)
					);
				}
			}

			usort($chips, function ($a, $b) {
				return ($b['top'] - $a['top']) ? $b['top'] - $a['top'] : $b['count'] - $a['count'];
			});
		}

		if ($cat && isset($counts[$cat])) {
			$where .= " AND p.product_id IN (SELECT p2c2.product_id FROM `{$p}product_to_category` p2c2 INNER JOIN `{$p}category_path` cp2 ON (cp2.category_id = p2c2.category_id) WHERE cp2.path_id = '" . $cat . "')";
		} else {
			$cat = 0;
		}

		$sort = isset($this->request->get['sort']) ? (string)$this->request->get['sort'] : 'p.sort_order';
		$order = (isset($this->request->get['order']) && strtoupper($this->request->get['order']) === 'DESC') ? 'DESC' : 'ASC';
		$page = isset($this->request->get['page']) ? max(1, (int)$this->request->get['page']) : 1;
		$limit = isset($this->request->get['limit']) ? min(100, max(1, (int)$this->request->get['limit'])) : 0;

		if (!$limit) {
			$limit = (int)$this->config->get('theme_' . $theme . '_product_limit');
			$limit = $limit > 0 ? $limit : 24;
		}

		$sorts = array('p.sort_order' => 'p.sort_order', 'pd.name' => 'LCASE(pd.name)', 'p.price' => 'p.price', 'p.date_added' => 'p.date_added');
		$sort_sql = isset($sorts[$sort]) ? $sorts[$sort] : 'p.sort_order';

		$total = (int)$this->db->query("SELECT COUNT(DISTINCT p.product_id) AS total" . $from . " WHERE " . $where)->row['total'];
		$ids = array();

		foreach ($this->db->query("SELECT DISTINCT p.product_id, p.quantity, p.sort_order, p.price, p.date_added, pd.name" . $from . " WHERE " . $where . " ORDER BY (p.quantity > 0) DESC, " . $sort_sql . " " . $order . ", LCASE(pd.name) ASC LIMIT " . (($page - 1) * $limit) . "," . $limit)->rows as $r) {
			$ids[] = (int)$r['product_id'];
		}

		$img_w = $this->config->get('theme_' . $theme . '_image_product_width');
		$img_h = $this->config->get('theme_' . $theme . '_image_product_height');
		$data['products'] = array();

		foreach ($ids as $product_id) {
			$r = $this->model_catalog_product->getProduct($product_id);

			if (!$r) {
				continue;
			}

			$price = ($this->customer->isLogged() || !$this->config->get('config_customer_price')) ? $this->currency->format($this->tax->calculate($r['price'], $r['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']) : false;
			$special = (!is_null($r['special']) && (float)$r['special'] >= 0) ? $this->currency->format($this->tax->calculate($r['special'], $r['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']) : false;

			$data['products'][] = array(
				'product_id'  => $r['product_id'],
				'thumb'       => $this->model_tool_image->resize($r['image'] ? $r['image'] : 'placeholder.png', $img_w, $img_h),
				'name'        => $r['name'],
				'description' => '',
				'price'       => $price,
				'special'     => $special,
				'tax'         => false,
				'minimum'     => $r['minimum'] > 0 ? $r['minimum'] : 1,
				'rating'      => $this->config->get('config_review_status') ? (int)$r['rating'] : false,
				'href'        => $this->url->link('product/product', 'product_id=' . $r['product_id'])
			);
		}

		$heading = sprintf($t['text_car_heading'], $info['label']);
		$cat_name = '';

		foreach ($chips as $c) {
			if ($c['id'] == $cat) {
				$cat_name = $c['name'];
			}
		}

		$this->document->setTitle(($cat_name ? $cat_name . ' — ' : '') . $heading . ' | ' . $this->config->get('config_name'));
		$this->document->setDescription(sprintf($t['text_car_meta'], $info['label']));

		$data['breadcrumbs'] = array(
			array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/home')),
			array('text' => $info['make'], 'href' => $this->url->link('extension/module/vm_theme/car', 'make_id=' . $make_id))
		);

		if ($model_id) {
			$data['breadcrumbs'][] = array('text' => $info['model'] . ($year ? ', ' . $year : ''), 'href' => $info['href']);
		}

		if ($cat_name) {
			$data['breadcrumbs'][] = array('text' => $cat_name, 'href' => $this->url->link('extension/module/vm_theme/car', $base . '&cat=' . $cat));
		}

		$data['heading_title'] = $cat_name ? $cat_name . ' — ' . $info['label'] : $heading;
		$data['thumb'] = '';
		$data['description'] = '';
		$data['categories'] = array();
		$data['vm_car_page'] = array(
			'label' => $info['label'],
			'total' => $total,
			'chips' => $chips,
			'all'   => $this->url->link('extension/module/vm_theme/car', $base),
			'cat'   => $cat,
			'model' => $model_id,
			'bar'   => $this->carBarHtml(true, $info['label'])
		);

		$data['text_compare'] = sprintf($this->language->get('text_compare'), isset($this->session->data['compare']) ? count($this->session->data['compare']) : 0);
		$data['compare'] = $this->url->link('product/compare');

		$url = $base . ($cat ? '&cat=' . $cat : '');
		$data['sorts'] = array();

		foreach (array(array('p.sort_order', 'ASC', 'text_default'), array('pd.name', 'ASC', 'text_name_asc'), array('pd.name', 'DESC', 'text_name_desc'), array('p.price', 'ASC', 'text_price_asc'), array('p.price', 'DESC', 'text_price_desc')) as $s) {
			$data['sorts'][] = array('text' => $this->language->get($s[2]), 'value' => $s[0] . '-' . $s[1], 'href' => $this->url->link('extension/module/vm_theme/car', $url . '&sort=' . $s[0] . '&order=' . $s[1]));
		}

		$data['limits'] = array();

		foreach (array_unique(array($limit, 25, 50, 75, 100)) as $v) {
			$data['limits'][] = array('text' => $v, 'value' => $v, 'href' => $this->url->link('extension/module/vm_theme/car', $url . '&sort=' . $sort . '&order=' . $order . '&limit=' . $v));
		}

		$pagination = new Pagination();
		$pagination->total = $total;
		$pagination->page = $page;
		$pagination->limit = $limit;
		$pagination->url = $this->url->link('extension/module/vm_theme/car', $url . '&sort=' . $sort . '&order=' . $order . '&limit=' . $limit . '&page={page}');

		$data['pagination'] = $pagination->render();
		$data['results'] = sprintf($this->language->get('text_pagination'), $total ? (($page - 1) * $limit) + 1 : 0, ((($page - 1) * $limit) > ($total - $limit)) ? $total : ((($page - 1) * $limit) + $limit), $total, ceil($total / $limit));
		$data['sort'] = $sort;
		$data['order'] = $order;
		$data['limit'] = $limit;
		$data['text_empty'] = $t['text_car_empty'];
		$data['continue'] = $this->url->link('common/home');

		if ($page == 1) {
			$this->document->addLink($this->url->link('extension/module/vm_theme/car', $url), 'canonical');
		}

		$data['column_left'] = '';
		$data['column_right'] = '';
		$data['content_top'] = '';
		$data['content_bottom'] = '';
		$data['footer'] = $this->load->controller('common/footer');
		$data['header'] = $this->load->controller('common/header');

		$this->response->setOutput($this->load->view('product/category', $data));
	}
}
