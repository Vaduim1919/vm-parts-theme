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
