/* VM Parts theme: mobile menu, bottom bar, footer accordion */
function vmDrawer(open){
  var d=document.getElementById('vm-drawer'),o=document.getElementById('vm-drawer-overlay');
  if(!d)return;
  d.classList.toggle('is-open',open);if(o)o.classList.toggle('is-open',open);
  d.setAttribute('aria-hidden',open?'false':'true');
  document.documentElement.classList.toggle('vm-noscroll',open);
}
function vmSearch(){
  var i=document.querySelector('#search input[name="search"]');
  if(!i)return;
  window.scrollTo({top:0,behavior:'smooth'});
  setTimeout(function(){i.focus();},250);
}
function vmChat(){
  var f=document.getElementById('vm-fab');
  if(f){f.classList.toggle('is-open');return;}
  var t=document.querySelector('a[href^="tel:"]');if(t)location.href=t.href;
}
document.addEventListener('keydown',function(e){if(e.key==='Escape')vmDrawer(false);});
document.addEventListener('DOMContentLoaded',function(){
  // footer sections fold on phones
  [].forEach.call(document.querySelectorAll('.vm-footer-grid>div:not(.vm-footer-brand)>h5:first-child'),function(h){
    h.classList.add('vm-acc');
    h.addEventListener('click',function(){if(window.innerWidth<768)h.parentNode.classList.toggle('is-open');});
  });
  // desktop catalog dropdown on inner pages
  var d=document.querySelector('.vm-catalog-drop:not(.vm-catalog-drop-home)');
  var oldFooter=[].some.call(document.querySelectorAll('script:not([src])'),function(x){return x.textContent.indexOf('vm-catalog-drop:not')>-1;});
  if(d&&!oldFooter&&d.querySelector('.vm-catalog-btn')){
    d.querySelector('.vm-catalog-btn').addEventListener('click',function(e){e.stopPropagation();d.classList.toggle('is-open');});
    document.addEventListener('click',function(e){if(!d.contains(e.target))d.classList.remove('is-open');});
  }
  // bottom bar cart counter follows the standard add-to-cart AJAX
  if(window.jQuery){
    jQuery(document).ajaxSuccess(function(e,xhr,opt){
      if(!opt||!opt.url||!/checkout\/cart\/(add|remove)/.test(opt.url))return;
      jQuery.getJSON('index.php?route=extension/module/vm_theme/count',function(j){
        var n=parseInt(j.count,10)||0,b=document.getElementById('vm-bn-count');
        if(b){b.textContent=n;if(n>0){b.removeAttribute('hidden');}else{b.setAttribute('hidden','');}}
      });
    });
  }
});
/* 1.2.2 add-to-cart popup + wishlist hearts */
(function(){
var T=window.vmT||{},last=null;
function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
function txt(el){return el?(el.textContent||'').replace(/\s+/g,' ').trim():'';}
document.addEventListener('click',function(e){
  var b=e.target.closest?e.target.closest('[onclick*="cart.add"],[onclick*="wishlist.add"],#button-cart'):null;
  if(b)last=b;
},true);
function info(btn){
  var i={name:'',img:'',price:'',qty:1};
  if(!btn)return i;
  if(btn.id==='button-cart'||btn.closest('.vm-buybar')){
    i.name=txt(document.querySelector('h1'));
    var im=document.querySelector('.thumbnails img');i.img=im?im.src:'';
    var p=document.querySelectorAll('.vm-pp-price b');i.price=p.length?txt(p[p.length-1]):'';
    var q=document.querySelector('#product input[name="quantity"]');i.qty=q?(parseInt(q.value,10)||1):1;
    return i;
  }
  var c=btn.closest('.vm-card,.vm-an-row,.product-thumb')||btn.parentNode;
  var n=c.querySelector('.vm-card-name,.vm-an-info a,.caption h4 a,h4 a');i.name=txt(n);
  var m=c.querySelector('img');i.img=m?(m.currentSrc||m.src):'';
  var pr=c.querySelectorAll('.vm-price b,.vm-an-price b,.price');i.price=pr.length?txt(pr[pr.length-1]):'';
  return i;
}
function close(){var p=document.getElementById('vm-pop');if(p){p.classList.remove('is-open');setTimeout(function(){if(p.parentNode)p.parentNode.removeChild(p);},200);}document.documentElement.classList.remove('vm-noscroll');}
window.vmCartPopup=function(i,qty){
  close();var tt=document.getElementById('vm-toast');if(tt)tt.classList.remove('is-open');
  var d=document.createElement('div');d.id='vm-pop';d.className='vm-pop';d.setAttribute('role','dialog');d.setAttribute('aria-modal','true');
  d.innerHTML='<div class="vm-pop-box"><button type="button" class="vm-pop-x" aria-label="×">&times;</button>'+
   '<div class="vm-pop-head"><span class="vm-pop-ok"><i class="fa fa-check"></i></span>'+esc(T.added||'Added to cart')+'</div>'+
   '<div class="vm-pop-item">'+(i.img?'<img src="'+esc(i.img)+'" alt="">':'')+'<div><div class="vm-pop-name">'+esc(i.name)+'</div>'+
   '<div class="vm-pop-meta">'+(i.price?'<b>'+esc(i.price)+'</b>':'')+'<span>'+esc(T.qty||'Qty')+': '+esc(qty||1)+'</span></div></div></div>'+
   '<div class="vm-pop-sum" id="vm-pop-sum">&nbsp;</div>'+
   '<div class="vm-pop-btns"><button type="button" class="vm-pop-cont">'+esc(T.cont||'Continue')+'</button>'+
   '<a class="vm-pop-go" href="'+esc(T.checkout_url||T.cart||'index.php?route=checkout/cart')+'"><i class="fa fa-check-circle"></i> '+esc(T.checkout||'Checkout')+'</a></div></div>';
  document.body.appendChild(d);
  d.addEventListener('click',function(e){if(e.target===d||e.target.closest('.vm-pop-x,.vm-pop-cont'))close();});
  document.documentElement.classList.add('vm-noscroll');
  requestAnimationFrame(function(){d.classList.add('is-open');var g=d.querySelector('.vm-pop-go');if(g)g.focus();});
  state(function(s){var el=document.getElementById('vm-pop-sum');if(el&&T.incart)el.textContent=T.incart.replace('%s',s.count).replace('%s',s.total);});
};
document.addEventListener('keydown',function(e){if(e.key==='Escape')close();});
function state(cb){if(!window.jQuery)return;jQuery.getJSON('index.php?route=extension/module/vm_theme/state',function(s){
  var n=parseInt(s.count,10)||0,b=document.getElementById('vm-bn-count');
  if(b){b.textContent=n;if(n>0){b.removeAttribute('hidden');}else{b.setAttribute('hidden','');}}
  if(cb)cb(s);
});}
function hearts(ids){
  ids=(ids||[]).map(String);
  [].forEach.call(document.querySelectorAll('[onclick*="wishlist.add"]'),function(b){
    var m=(b.getAttribute('onclick')||'').match(/wishlist\.add\(\s*'?(\d+)/);if(!m)return;
    var on=ids.indexOf(m[1])>-1;b.classList.toggle('is-active',on);
    var i=b.querySelector('.fa');if(i){i.classList.toggle('fa-heart',on);i.classList.toggle('fa-heart-o',!on);}
  });
  var w=document.getElementById('wishlist-total');
  if(w){w.classList.toggle('has-items',ids.length>0);var c=w.querySelector('.vm-wl-count');
    if(!c){c=document.createElement('em');c.className='vm-wl-count';w.appendChild(c);}c.textContent=ids.length;c.hidden=!ids.length;}
}
var toastT;
function toast(html){
  var t=document.getElementById('vm-toast');
  if(!t){t=document.createElement('div');t.id='vm-toast';t.className='vm-toast';document.body.appendChild(t);}
  t.innerHTML=html;t.classList.add('is-open');clearTimeout(toastT);toastT=setTimeout(function(){t.classList.remove('is-open');},3500);
}
var favIds=[];
document.addEventListener('DOMContentLoaded',function(){
  if(!window.jQuery)return;
  var $=jQuery;
  if(window.cart){
    window.cart.add=function(id,qty){
      var btn=last;qty=parseInt(qty,10);if(!qty||qty<1)qty=1;
      $.ajax({url:'index.php?route=checkout/cart/add',type:'post',data:'product_id='+encodeURIComponent(id)+'&quantity='+qty,dataType:'json',
        beforeSend:function(){if(btn)btn.classList.add('is-loading');},
        complete:function(){if(btn)btn.classList.remove('is-loading');},
        success:function(json){
          $('.alert-dismissible, .text-danger').remove();
          if(json.redirect){location=json.redirect;return;}
          if(json.success){
            if(btn){btn.classList.add('is-added');setTimeout(function(){btn.classList.remove('is-added');},1500);}
            vmCartPopup(info(btn),qty);
            vmCartRefresh();
          }
        },
        error:function(x){if(window.console)console.log(x.responseText);}
      });
    };
  }
  if(window.wishlist){
    window.wishlist.add=function(id){
      var btn=last;
      if(favIds.indexOf(String(id))>-1){toast('<i class="fa fa-heart"></i> '+esc(T.fav_has)+' <a href="'+esc(T.wishlist)+'">'+esc(T.fav_open)+'</a>');return;}
      $.ajax({url:'index.php?route=account/wishlist/add',type:'post',data:'product_id='+encodeURIComponent(id),dataType:'json',
        success:function(json){
          $('.alert-dismissible').remove();
          if(json.redirect){location=json.redirect;return;}
          if(json.success){
            favIds.push(String(id));hearts(favIds);
            if(btn){btn.classList.add('vm-fav-pop');setTimeout(function(){btn.classList.remove('vm-fav-pop');},600);}
            var guest=!document.querySelector('a[href*="account/logout"]');
            toast('<i class="fa fa-heart"></i> '+esc(T.fav_add)+' <a href="'+esc(T.wishlist)+'">'+esc(T.fav_open)+'</a>'+(guest&&T.fav_login?'<small><a href="'+esc(T.login)+'">'+esc(T.fav_login)+'</a></small>':''));
          }
        }
      });
    };
  }
  if(document.querySelector('[onclick*="wishlist.add"]')||document.getElementById('wishlist-total')){
    state(function(s){favIds=(s.wishlist||[]).map(String);hearts(favIds);});
  }
});
})();
/* 1.3.0 car picker: make -> model -> year */
(function(){
var C=window.vmCar;if(!C)return;
var T=C.t||{},U=C.urls||{},cache={};
function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
function get(url,cb){if(cache[url]){cb(cache[url]);return;}var x=new XMLHttpRequest();x.open('GET',url);x.onload=function(){var d=[];try{d=JSON.parse(x.responseText);}catch(e){}cache[url]=d;cb(d);};x.onerror=function(){cb([]);};x.send();}
function q(u,k,v){return u+(u.indexOf('?')>-1?'&':'?')+k+'='+encodeURIComponent(v);}
function norm(s){return String(s||'').toLowerCase().replace(/[\s\-_.()]/g,'');}
function num(n){return String(n).replace(/\B(?=(\d{3})+(?!\d))/g,' ');}
var mobile=function(){return window.innerWidth<768;};
var panel=null,owner=null;
function closePanel(){if(panel){panel.parentNode.removeChild(panel);panel=null;}if(owner){[].forEach.call(owner.el.querySelectorAll('.vm-cf'),function(b){b.classList.remove('is-open');});owner=null;}document.documentElement.classList.remove('vm-noscroll-car');}
document.addEventListener('click',function(e){if(panel&&!panel.contains(e.target)&&!(owner&&e.target.closest&&e.target.closest('.vm-cf')&&owner.el.contains(e.target)))closePanel();});
document.addEventListener('keydown',function(e){if(e.key==='Escape'){if(panel)closePanel();else closeModal();}});
window.addEventListener('resize',function(){if(panel&&!mobile())place();});
function place(){if(!panel||!owner||mobile())return;var b=owner.el.querySelector('.vm-cf[data-step="'+panel.getAttribute('data-step')+'"]');if(!b)return;var r=b.getBoundingClientRect();var w=Math.max(r.width,panel.getAttribute('data-step')==='year'?300:360);var left=Math.min(r.left,window.innerWidth-w-12);panel.style.width=w+'px';panel.style.left=Math.max(12,left)+window.pageXOffset+'px';panel.style.top=(r.bottom+6+window.pageYOffset)+'px';var need=r.bottom+6+Math.min(panel.scrollHeight,440)+12-window.innerHeight;if(need>0){window.scrollBy(0,need);setTimeout(place,0);}}
function Picker(el){
  this.el=el;var s=C.sel||{};
  this.s={make_id:s.make_id||0,make:s.make||'',model_id:s.model_id||0,model:s.model||'',year:s.year||0,mcount:0,count:0};
  var me=this;
  [].forEach.call(el.querySelectorAll('.vm-cf'),function(b){b.addEventListener('click',function(e){e.stopPropagation();var st=b.getAttribute('data-step');if(panel&&owner===me&&panel.getAttribute('data-step')===st){closePanel();return;}me.open(st);});});
  var go=el.querySelector('[data-go]');if(go)go.addEventListener('click',function(){me.go();});
  var ch=el.querySelector('[data-car-change]');if(ch)ch.addEventListener('click',function(){el.classList.add('is-changing');me.open('make');});
  this.render();
  if(this.s.make_id){get(q(U.makes,'_',1),function(d){d.forEach(function(m){if(m.id==me.s.make_id){me.s.mcount=m.count;if(!me.s.model_id)me.s.count=m.count;}});me.render();});}
  if(this.s.model_id){get(q(U.models,'make_id',this.s.make_id),function(d){d.forEach(function(m){if(m.id==me.s.model_id)me.s.count=m.count;});me.render();});}
}
Picker.prototype.render=function(){
  var s=this.s,el=this.el;
  function set(step,val,ph,dis){var b=el.querySelector('.vm-cf[data-step="'+step+'"]');if(!b)return;b.querySelector('span').textContent=val||ph;b.classList.toggle('has-value',!!val);b.disabled=!!dis;}
  set('make',s.make,T.make,false);
  set('model',s.model_id?s.model:(s.make_id?T.all_models:''),T.model,!s.make_id);
  set('year',s.year?String(s.year):(s.model_id?T.all_years:''),T.year,!s.model_id);
  var go=el.querySelector('[data-go]');if(go){go.disabled=!s.make_id;go.textContent=s.make_id&&s.count?T.show_n.replace('%s',num(s.count)+' '+T.parts):T.show;}
};
Picker.prototype.go=function(){var s=this.s;if(!s.make_id)return;var u=q(U.results,'make_id',s.make_id);if(s.model_id)u=q(u,'model_id',s.model_id);if(s.year)u=q(u,'year',s.year);location.href=u;};
Picker.prototype.open=function(step){
  var me=this,s=this.s;closePanel();owner=this;
  var b=this.el.querySelector('.vm-cf[data-step="'+step+'"]');if(b)b.classList.add('is-open');
  panel=document.createElement('div');panel.className='vm-cpanel';panel.setAttribute('data-step',step);
  var title=step==='make'?T.make:(step==='model'?s.make:(s.make+' '+s.model));
  var back=step!=='make'?'<button type="button" class="vm-cp-back" aria-label="'+esc(T.back)+'"><i class="fa fa-angle-left"></i></button>':'<span></span>';
  panel.innerHTML='<div class="vm-cp-head">'+back+'<b>'+esc(title)+'</b><button type="button" class="vm-cp-x" aria-label="×">&times;</button></div>'+
    (step!=='year'?'<div class="vm-cp-search"><i class="fa fa-search"></i><input type="text" autocomplete="off" placeholder="'+esc(step==='make'?T.search_make:T.search_model)+'"></div>':'')+
    '<div class="vm-cp-body"><div class="vm-cp-loading">'+esc(T.loading)+'</div></div>';
  document.body.appendChild(panel);
  if(mobile())document.documentElement.classList.add('vm-noscroll-car');
  place();
  panel.querySelector('.vm-cp-x').onclick=closePanel;
  var bk=panel.querySelector('.vm-cp-back');if(bk)bk.onclick=function(e){e.stopPropagation();me.open(step==='year'?'model':'make');};
  var inp=panel.querySelector('input'),body=panel.querySelector('.vm-cp-body');
  var url=step==='make'?q(U.makes,'_',1):(step==='model'?q(U.models,'make_id',s.make_id):q(U.years,'model_id',s.model_id));
  get(url,function(list){
    if(!panel)return;
    function draw(f){body.innerHTML=me['draw_'+step](list,f||'');body.scrollTop=0;}
    draw('');place();
    if(inp){inp.addEventListener('input',function(){draw(inp.value);});inp.addEventListener('keydown',function(e){if(e.key==='Enter'){var first=body.querySelector('[data-id]');if(first)first.click();}});if(!mobile())setTimeout(function(){inp.focus();},30);}
    body.addEventListener('click',function(e){var it=e.target.closest('[data-id]');if(!it)return;e.stopPropagation();me.pick(step,it,list);});
  });
};
Picker.prototype.draw_make=function(list,f){
  var h='',n=norm(f);
  if(!list.length)return '<div class="vm-cp-empty">'+esc(T.nothing)+'</div>';
  if(!n){
    var pop=list.filter(function(m){return m.pop>0;}).sort(function(a,b){return b.pop-a.pop;}).slice(0,12);
    if(pop.length){h+='<div class="vm-cp-label">'+esc(T.popular)+'</div><div class="vm-cp-pop">'+pop.map(function(m){return '<button type="button" data-id="'+m.id+'" class="vm-cp-chip'+(m.id==this.s.make_id?' is-sel':'')+'"><span class="vm-cp-logo">'+esc(m.name.charAt(0))+'</span>'+esc(m.name)+'</button>';},this).join('')+'</div><div class="vm-cp-label">'+esc(T.all_makes)+'</div>';}
  }
  var letter='',items=list.filter(function(m){return !n||norm(m.name).indexOf(n)>-1;});
  if(!items.length)return h+'<div class="vm-cp-empty">'+esc(T.nothing)+'</div>';
  h+='<ul class="vm-cp-list">';
  items.forEach(function(m){var L=m.name.charAt(0).toUpperCase();if(!n&&L!==letter){letter=L;h+='<li class="vm-cp-letter">'+esc(L)+'</li>';}h+='<li><button type="button" data-id="'+m.id+'"'+(m.id==this.s.make_id?' class="is-sel"':'')+'><span>'+esc(m.name)+'</span><em>'+num(m.count)+'</em></button></li>';},this);
  return h+'</ul>';
};
Picker.prototype.draw_model=function(list,f){
  var n=norm(f),h='<ul class="vm-cp-list">',fam=null;
  if(!n)h+='<li><button type="button" data-id="0" class="vm-cp-all'+(!this.s.model_id?' is-sel':'')+'"><span>'+esc(T.all_models)+'</span><em>'+num(this.s.mcount||'')+'</em></button></li>';
  var items=list.filter(function(m){return !n||norm(m.name).indexOf(n)>-1||norm(m.family).indexOf(n)>-1;});
  if(!items.length)return '<div class="vm-cp-empty">'+esc(T.nothing)+'</div>';
  var fams={};items.forEach(function(m){fams[m.family]=(fams[m.family]||0)+1;});
  items.forEach(function(m){
    if(m.family!==fam){fam=m.family;if(fams[fam]>1||norm(fam)!==norm(m.name))h+='<li class="vm-cp-letter vm-cp-fam">'+esc(fam)+'</li>';}
    h+='<li><button type="button" data-id="'+m.id+'"'+(m.id==this.s.model_id?' class="is-sel"':'')+'><span>'+esc(m.name)+(m.years?'<small>'+esc(m.years)+'</small>':'')+'</span><em>'+num(m.count)+'</em></button></li>';
  },this);
  return h+'</ul>';
};
Picker.prototype.draw_year=function(list){
  var h='<div class="vm-cp-years"><button type="button" data-id="0" class="vm-cp-yall'+(!this.s.year?' is-sel':'')+'">'+esc(T.all_years)+'</button>';
  list.forEach(function(y){h+='<button type="button" data-id="'+y+'"'+(y==this.s.year?' class="is-sel"':'')+'>'+y+'</button>';},this);
  return h+'</div>';
};
Picker.prototype.pick=function(step,it,list){
  var s=this.s,id=parseInt(it.getAttribute('data-id'),10)||0,me=this;
  if(step==='make'){var m=list.filter(function(x){return x.id==id;})[0];if(!m)return;if(s.make_id!=id){s.model_id=0;s.model='';s.year=0;}s.make_id=id;s.make=m.name;s.mcount=m.count;s.count=m.count;this.render();this.open('model');return;}
  if(step==='model'){
    if(!id){s.model_id=0;s.model='';s.year=0;s.count=s.mcount;this.render();closePanel();this.focusGo();return;}
    var md=list.filter(function(x){return x.id==id;})[0];if(!md)return;if(s.model_id!=id)s.year=0;s.model_id=id;s.model=md.name;s.count=md.count;this.render();
    get(q(U.years,'model_id',id),function(y){if(y&&y.length)me.open('year');else{closePanel();me.focusGo();}});return;
  }
  s.year=id;this.render();closePanel();this.focusGo();
};
Picker.prototype.focusGo=function(){var g=this.el.querySelector('[data-go]');if(g){g.classList.add('vm-pulse');g.focus();setTimeout(function(){g.classList.remove('vm-pulse');},900);}};
var modal=null;
function closeModal(){if(modal){modal.parentNode.removeChild(modal);modal=null;document.documentElement.classList.remove('vm-noscroll');}}
window.vmCarOpen=function(){
  closePanel();closeModal();
  modal=document.createElement('div');modal.className='vm-pop vm-carmodal is-open';
  modal.innerHTML='<div class="vm-pop-box"><button type="button" class="vm-pop-x" aria-label="×">&times;</button><div class="vm-pop-head"><span class="vm-pop-ok vm-pop-car"><i class="fa fa-car"></i></span>'+esc(T.title)+'</div>'+
   '<div class="vm-carbar vm-carbar-modal" data-vm-car><div class="vm-carbar-pick"><div class="vm-carbar-form">'+
   ['make','model','year'].map(function(st){return '<button type="button" class="vm-cf" data-step="'+st+'"><span>'+esc(T[st])+'</span><i class="fa fa-angle-down"></i></button>';}).join('')+
   '<button type="button" class="vm-cf-go" data-go disabled>'+esc(T.show)+'</button></div></div></div>'+
   (C.garage?'<a class="vm-carmodal-forget" href="'+esc(U.clear)+'">'+'<i class="fa fa-times"></i> '+esc(T.forget)+': '+esc(C.garage)+'</a>':'')+'</div>';
  document.body.appendChild(modal);document.documentElement.classList.add('vm-noscroll');
  modal.addEventListener('click',function(e){if(e.target===modal||e.target.closest('.vm-pop-x'))closeModal();});
  var p=new Picker(modal.querySelector('[data-vm-car]'));
  if(!p.s.make_id)p.open('make');
};
document.addEventListener('DOMContentLoaded',function(){[].forEach.call(document.querySelectorAll('[data-vm-car]'),function(el){new Picker(el);});});
})();

/* 1.4.0 header search with category, cart button, hero slider, hit tabs */
function vmCartRefresh(){
  if(!window.jQuery)return;
  jQuery.get('index.php?route=common/cart/info',function(html){var n=jQuery('<div>').html(html).find('#cart');if(n.length){jQuery('#cart').replaceWith(n);}});
}
(function(){
function go(){
  var box=document.getElementById('search');if(!box)return;
  var i=box.querySelector('input[name="search"]'),c=box.querySelector('.vm-search-cat');
  var url=(document.querySelector('base')?document.querySelector('base').href:'')+'index.php?route=product/search';
  var v=i?i.value.trim():'';
  if(v)url+='&search='+encodeURIComponent(v);
  if(c&&c.value)url+='&category_id='+encodeURIComponent(c.value)+'&sub_category=true';
  location=url;
}
document.addEventListener('click',function(e){if(e.target.closest&&e.target.closest('#search .vm-search-go')){e.preventDefault();go();}});
document.addEventListener('keydown',function(e){if(e.key==='Enter'&&e.target.closest&&e.target.closest('#search')&&e.target.name==='search'){e.preventDefault();go();}});
document.addEventListener('DOMContentLoaded',function(){
  if(window.jQuery){jQuery(document).ajaxSuccess(function(ev,xhr,opt){if(opt&&opt.url&&/checkout\/cart\/(remove|edit)|cart\/add/.test(opt.url)){setTimeout(vmCartRefresh,150);}});}
  // hero slider
  [].forEach.call(document.querySelectorAll('[data-vm-hero]'),function(h){
    var sl=h.querySelectorAll('.vm-hero-slide'),dots=h.querySelectorAll('.vm-hero-dots button'),cur=0,tm;
    if(sl.length<2)return;
    function show(n){cur=(n+sl.length)%sl.length;[].forEach.call(sl,function(x,k){x.classList.toggle('is-active',k===cur);});[].forEach.call(dots,function(x,k){x.classList.toggle('is-active',k===cur);});}
    function auto(){clearInterval(tm);tm=setInterval(function(){show(cur+1);},6500);}
    [].forEach.call(dots,function(d,k){d.addEventListener('click',function(){show(k);auto();});});
    h.addEventListener('mouseenter',function(){clearInterval(tm);});h.addEventListener('mouseleave',auto);
    var x0=null;h.addEventListener('touchstart',function(e){x0=e.touches[0].clientX;},{passive:true});
    h.addEventListener('touchend',function(e){if(x0===null)return;var dx=e.changedTouches[0].clientX-x0;if(Math.abs(dx)>40){show(cur+(dx<0?1:-1));auto();}x0=null;});
    auto();
  });
  // hit tabs
  [].forEach.call(document.querySelectorAll('[data-vm-tabs]'),function(sec){
    var btns=sec.querySelectorAll('.vm-tabs-pills button');
    [].forEach.call(btns,function(b){b.addEventListener('click',function(){
      var c=b.getAttribute('data-cat');[].forEach.call(btns,function(x){x.classList.toggle('is-active',x===b);});
      [].forEach.call(sec.querySelectorAll('.vm-card'),function(card){card.style.display=(!c||card.classList.contains(c))?'':'none';});
    });});
  });
});
})();
