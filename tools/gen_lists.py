import re, os
SRC='/home/claude/site/catalog/view/theme/default/template/'
DST='/home/claude/vm_parts/upload/catalog/view/theme/vm_parts/template/'

CARD = '''<div class="vm-card">
  <div class="vm-stickers">{% if product.vm_hit %}<span class="vm-st vm-st-hit">{{ vm_t.text_hit }}</span>{% endif %}{% if product.vm_new %}<span class="vm-st vm-st-new">{{ vm_t.text_new }}</span>{% endif %}{% if product.vm_discount %}<span class="vm-st vm-st-sale">{{ product.vm_discount }}</span>{% endif %}</div>
  <button type="button" class="vm-fav" title="{{ button_wishlist }}" aria-label="{{ button_wishlist }}" onclick="wishlist.add('{{ product.product_id }}');"><i class="fa fa-heart-o"></i></button>
  <a class="vm-card-img" href="{{ product.href }}"><img src="{{ product.thumb }}" alt="{{ product.name }}" title="{{ product.name }}" loading="lazy" /></a>
  {% if product.vm_stock %}<div class="vm-stock vm-stock-{{ product.vm_stock_class }}">{{ product.vm_stock }}</div>{% endif %}
  <a class="vm-card-name" href="{{ product.href }}">{{ product.name }}</a>
  {% if product.vm_fit %}<div class="vm-fit">{{ vm_t.text_fits }}: {{ product.vm_fit }}</div>{% endif %}
  {% if product.vm_model %}<div class="vm-code">{{ vm_t.text_code }}: {{ product.vm_model }}</div>{% endif %}
  <div class="vm-card-bottom">
    {% if product.price %}<div class="vm-price">{% if product.special %}<s>{{ product.price }}</s><b class="vm-price-new">{{ product.special }}</b>{% else %}<b>{{ product.price }}</b>{% endif %}</div>{% endif %}
    <button type="button" class="vm-buy" onclick="cart.add('{{ product.product_id }}', '{{ product.minimum }}');"><i class="fa fa-shopping-cart"></i> {{ vm_t.text_buy }}</button>
  </div>
</div>'''

GRID = '<div class="vm-grid">\n{% for product in products %}\n' + CARD + '\n{% endfor %}\n</div>'

start_re = re.compile(r'<div class="row">\s*\{% for product in products %\}')
tag_re = re.compile(r'\{%-?\s*(for|endfor)\b')

def replace_loop(s, grid):
    m = start_re.search(s)
    if not m:
        return s
    pos = s.index('{% for product in products %}', m.start()) + 2
    depth = 1
    for t in tag_re.finditer(s, pos):
        depth += 1 if t.group(1) == 'for' else -1
        if depth == 0:
            end_for = s.index('%}', t.start()) + 2
            close = re.compile(r'\s*</div>').match(s, end_for)
            assert close, 'no closing div'
            return s[:m.start()] + grid + s[close.end():]
    raise Exception('unbalanced')

def write(rel, s):
    path = DST + rel
    os.makedirs(os.path.dirname(path), exist_ok=True)
    open(path, 'w').write(s)

# pages with list: replace the product loop only (toolbar, refine, pagination stay default)
for rel in ['product/category.twig', 'product/search.twig', 'product/special.twig', 'product/manufacturer_info.twig']:
    s = open(SRC + rel).read()
    n_before = len(start_re.findall(s))
    # the product loop is the one containing "product-layout"
    s2 = replace_loop(s, GRID)
    assert 'vm-grid' in s2, rel
    s2 = s2.replace('<div id="content" class="{{ class }}">', '<div id="content" class="{{ class }} vm-content">')
    write(rel, s2)
    print(rel, 'ok', n_before)

# modules
for rel in ['extension/module/featured.twig', 'extension/module/latest.twig', 'extension/module/bestseller.twig', 'extension/module/special.twig']:
    s = open(SRC + rel).read()
    s2 = replace_loop(s, GRID)
    s2 = s2.replace('<h3>{{ heading_title }}</h3>', '<div class="vm-module"><h2 class="vm-h2">{{ heading_title }}</h2>') 
    s2 = s2.rstrip() + '\n</div>\n'
    assert 'vm-grid' in s2 and 'vm-module' in s2, rel
    write(rel, s2)
    print(rel, 'ok')

open('/home/claude/vm_parts/tools/card.twig','w').write(CARD)
