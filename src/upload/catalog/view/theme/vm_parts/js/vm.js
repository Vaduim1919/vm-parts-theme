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
            setTimeout(function(){$('#cart > button').html('<i class="fa fa-shopping-cart"></i> <span id="cart-total">'+json.total+'</span>');},100);
            $('#cart > ul').load('index.php?route=common/cart/info ul li');
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
