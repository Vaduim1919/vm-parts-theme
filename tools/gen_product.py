SRC='/home/claude/site/catalog/view/theme/default/template/product/product.twig'
DST='/home/claude/vm_parts/upload/catalog/view/theme/vm_parts/template/product/product.twig'
s=open(SRC).read()
card=open('/home/claude/vm_parts/tools/card.twig').read()

def cut(a, b, start=0, inclusive_b=False):
    i = s.index(a, start)
    j = s.index(b, i)
    if inclusive_b: j += len(b)
    return s[i:j], j

gallery, _ = cut('{% if thumb or images %}', '<ul class="nav nav-tabs">')
i_tabs = s.index('<ul class="nav nav-tabs">')
tabs_end = s.index('{% endif %}</div>\n        </div>', i_tabs) + len('{% endif %}</div>')
tabs = s[i_tabs:tabs_end]
prod_block, _ = cut('<div id="product">', '{% if review_status %}\n          <div class="rating">')
prod_block = prod_block.rstrip()
rating, _ = cut('{% if review_status %}\n          <div class="rating">', '{% endif %} </div>\n      </div>')
rating = rating + '{% endif %}'
tags, _ = cut('{% if tags %}', '{{ content_bottom }}')
scripts = s[s.index('<script type="text/javascript"><!--\n$(\'select[name=\\\'recurring_id\\\']'):]

# add compatibility tab
tabs = tabs.replace('<li class="active"><a href="#tab-description" data-toggle="tab">{{ tab_description }}</a></li>',
  '<li class="active"><a href="#tab-description" data-toggle="tab">{{ tab_description }}</a></li>\n            <li><a href="#tab-vm-compat" data-toggle="tab">{{ vm_t.text_compat }}{% if vm_fits %} ({{ vm_fits|length }}){% endif %}</a></li>')
tabs = tabs.replace('<div class="tab-pane active" id="tab-description">{{ description }}</div>',
 '''<div class="tab-pane active" id="tab-description">{{ description }}</div>
            <div class="tab-pane" id="tab-vm-compat">
              {% if vm_fits %}
              <div class="table-responsive"><table class="table vm-compat-table">
                <thead><tr><th>{{ vm_t.text_make }}</th><th>{{ vm_t.text_model_car }}</th><th>{{ vm_t.text_years }}</th></tr></thead>
                <tbody>{% for f in vm_fits %}<tr><td><b>{{ f.make }}</b></td><td>{{ f.model }}</td><td>{{ f.years }}</td></tr>{% endfor %}</tbody>
              </table></div>
              {% else %}<p class="text-muted">{{ vm_t.text_no_compat }}</p>{% endif %}
            </div>''')
assert 'tab-vm-compat' in tabs

prod_block = prod_block.replace('class="btn btn-primary btn-lg btn-block">{{ button_cart }}</button>', 'class="vm-buy vm-buy-lg">{{ button_cart }}</button>')
assert 'vm-buy-lg' in prod_block

out = '''{{ header }}
<div id="product-product" class="container vm-product-page">
  <ul class="breadcrumb">
    {% for breadcrumb in breadcrumbs %}
    <li><a href="{{ breadcrumb.href }}">{{ breadcrumb.text }}</a></li>
    {% endfor %}
  </ul>
  <div class="row">{{ column_left }}
    {% if column_left and column_right %}{% set class = 'col-sm-6' %}{% elseif column_left or column_right %}{% set class = 'col-sm-9' %}{% else %}{% set class = 'col-sm-12' %}{% endif %}
    <div id="content" class="{{ class }}">{{ content_top }}
      <div class="vm-pp-top">
        <div class="vm-pp-gallery vm-box">
          <div class="vm-stickers vm-pp-stickers">{% if vm_product.vm_hit %}<span class="vm-st vm-st-hit">{{ vm_t.text_hit }}</span>{% endif %}{% if vm_product.vm_new %}<span class="vm-st vm-st-new">{{ vm_t.text_new }}</span>{% endif %}{% if vm_product.vm_discount %}<span class="vm-st vm-st-sale">{{ vm_product.vm_discount }}</span>{% endif %}</div>
          ''' + gallery.strip() + '''
        </div>
        <div class="vm-pp-info">
          <div class="vm-box vm-pp-buy">
            <h1>{{ heading_title }}</h1>
            <ul class="vm-pp-meta list-unstyled">
              {% if manufacturer %}<li>{{ text_manufacturer }} <a href="{{ manufacturers }}"><b>{{ manufacturer }}</b></a></li>{% endif %}
              <li>{{ text_model }} <b>{{ model }}</b></li>
              {% if reward %}<li>{{ text_reward }} {{ reward }}</li>{% endif %}
            </ul>
            {% if vm_product.vm_fit %}<div class="vm-pp-fit"><i class="fa fa-check-circle"></i> {{ vm_t.text_fits }}: {{ vm_product.vm_fit }}</div>{% endif %}
            <div class="vm-pp-pricebox">
              {% if price %}
              <div class="vm-pp-price">{% if not special %}<b>{{ price }}</b>{% else %}<s>{{ price }}</s> <b class="vm-price-new">{{ special }}</b>{% endif %}</div>
              {% endif %}
              <span class="vm-stock vm-stock-{{ vm_product.vm_stock_class ? vm_product.vm_stock_class : 'in' }}">{{ stock }}</span>
            </div>
            {% if price %}
            <ul class="list-unstyled vm-pp-extra">
              {% if tax %}<li>{{ text_tax }} {{ tax }}</li>{% endif %}
              {% if points %}<li>{{ text_points }} {{ points }}</li>{% endif %}
              {% for discount in discounts %}<li>{{ discount.quantity }}{{ text_discount }}{{ discount.price }}</li>{% endfor %}
            </ul>
            {% endif %}
            ''' + prod_block + '''
            <div class="vm-pp-actions">
              <button type="button" class="vm-link-btn" onclick="wishlist.add('{{ product_id }}');"><i class="fa fa-heart-o"></i> {{ button_wishlist }}</button>
              <button type="button" class="vm-link-btn" onclick="compare.add('{{ product_id }}');"><i class="fa fa-exchange"></i> {{ button_compare }}</button>
            </div>
            ''' + rating + '''
          </div>
          <div class="vm-box vm-pp-service">
            <div><i class="fa fa-truck"></i> {{ vm_t.text_delivery_box }}</div>
            <div><i class="fa fa-credit-card"></i> {{ vm_t.text_payment_box }}</div>
            <div><i class="fa fa-undo"></i> {{ vm_t.text_return_box }}</div>
          </div>
        </div>
      </div>
      <div class="vm-box vm-pp-tabs">
          ''' + tabs + '''
      </div>
      {% if products %}
      <h2 class="vm-h2">{{ text_related }}</h2>
      <div class="vm-grid">
      {% for product in products %}
''' + card + '''
      {% endfor %}
      </div>
      {% endif %}
      ''' + tags + '''
      {{ content_bottom }}</div>
    {{ column_right }}</div>
</div>
''' + scripts
open(DST,'w').write(out)
print('ok', len(out))
