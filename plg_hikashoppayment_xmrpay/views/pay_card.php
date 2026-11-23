<?php
// Render the payment card and its status polling.


(defined('_JEXEC') || defined('XMRPAY_HOST')) or die('Restricted access');

$brandUrl  = 'https://xmrpay.shop';
$githubUrl = 'https://github.com/SlowBearDigger';
$locked    = ((string) $xmr !== '');
?>
<style>
.xmrpay-pay{--xmr:#ff6600;--xmr-d:#e65c00;--ink:#1a1a1a;--mut:#6b7280;--line:#e5e7eb;--bg:#fff;--soft:#f9fafb;--ok:#16a34a;
  max-width:480px;margin:24px auto;background:var(--bg);border:1px solid var(--line);border-radius:16px;
  box-shadow:0 8px 30px rgba(0,0,0,.06);overflow:hidden;color:var(--ink);
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;line-height:1.45;}
.xmrpay-pay *{box-sizing:border-box;}
.xmrpay-pay [hidden]{display:none!important;}
.xmrpay-pay__bar{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid var(--line);}
.xmrpay-pay__brand{display:flex;align-items:center;gap:8px;font-weight:700;font-size:15px;letter-spacing:-.01em;}
.xmrpay-pay__mono{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:7px;background:var(--xmr);color:#fff;font-weight:800;font-size:16px;}
.xmrpay-pay__net{font-size:11px;font-weight:600;color:var(--mut);text-transform:uppercase;letter-spacing:.06em;}
.xmrpay-pay__body{padding:24px 20px;text-align:center;}
.xmrpay-pay__title{margin:0 0 4px;font-size:18px;font-weight:700;}
.xmrpay-pay__sub{margin:0 0 18px;font-size:13px;color:var(--mut);}
.xmrpay-pay__amount{font-size:30px;font-weight:800;letter-spacing:-.02em;color:var(--xmr);font-variant-numeric:tabular-nums;word-break:break-all;}
.xmrpay-pay__amount span{font-size:18px;color:var(--ink);margin-left:4px;}
.xmrpay-pay__fiat{font-size:13px;color:var(--mut);margin-top:2px;}
.xmrpay-pay__qr{margin:20px auto;width:232px;height:232px;padding:12px;border:1px solid var(--line);border-radius:14px;background:#fff;display:flex;align-items:center;justify-content:center;}
.xmrpay-pay__qr img,.xmrpay-pay__qr canvas{display:block;border-radius:4px;}
.xmrpay-pay__label{font-size:12px;color:var(--mut);text-align:left;margin:0 0 6px;font-weight:600;}
.xmrpay-pay__addr{display:flex;align-items:stretch;gap:8px;}
.xmrpay-pay__addr code{flex:1;min-width:0;background:var(--soft);border:1px solid var(--line);border-radius:10px;padding:10px 12px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;text-align:left;word-break:break-all;color:var(--ink);}
.xmrpay-pay__copy{flex:0 0 auto;min-height:44px;border:1px solid var(--line);background:#fff;border-radius:10px;padding:0 14px;font-size:13px;font-weight:600;cursor:pointer;color:var(--ink);transition:.15s;}
.xmrpay-pay__copy:hover{border-color:var(--xmr);color:var(--xmr);}
.xmrpay-pay__copy.ok{border-color:var(--ok);color:var(--ok);}
.xmrpay-pay__open{display:block;min-height:44px;margin:18px 0 4px;padding:13px;border-radius:11px;background:var(--xmr);color:#fff;font-weight:700;font-size:15px;text-decoration:none;text-align:center;transition:.15s;}
.xmrpay-pay__open:hover{background:var(--xmr-d);color:#fff;}
.xmrpay-pay__copy:focus-visible,.xmrpay-pay__open:focus-visible,.xmrpay-pay__foot a:focus-visible{outline:2px solid var(--xmr);outline-offset:2px;}
.xmrpay-pay__status{display:flex;align-items:center;justify-content:center;gap:9px;margin-top:16px;font-size:14px;font-weight:600;color:var(--mut);}
.xmrpay-pay__dot{width:10px;height:10px;border-radius:50%;background:var(--xmr);box-shadow:0 0 0 0 rgba(255,102,0,.5);animation:xmrpay-pulse 1.6s infinite;}
@keyframes xmrpay-pulse{0%{box-shadow:0 0 0 0 rgba(255,102,0,.5)}70%{box-shadow:0 0 0 10px rgba(255,102,0,0)}100%{box-shadow:0 0 0 0 rgba(255,102,0,0)}}
@media (prefers-reduced-motion: reduce){.xmrpay-pay__dot{animation:none;}}
.xmrpay-pay__status.paid{color:var(--ok);}
.xmrpay-pay__status.paid .xmrpay-pay__dot{background:var(--ok);animation:none;box-shadow:none;}
.xmrpay-pay__status.warn{color:#b45309;font-weight:600;}
.xmrpay-pay__status.warn .xmrpay-pay__dot{background:#f59e0b;animation:none;box-shadow:none;}
.xmrpay-pay__hint{font-size:12px;color:var(--mut);margin-top:8px;}
.xmrpay-pay__notice{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;border-radius:10px;padding:12px 14px;font-size:13px;text-align:left;margin:4px 0 16px;}
.xmrpay-pay__foot{display:flex;align-items:center;justify-content:center;gap:14px;padding:13px 20px;border-top:1px solid var(--line);background:var(--soft);font-size:12px;}
.xmrpay-pay__foot a{color:var(--mut);text-decoration:none;font-weight:600;}
.xmrpay-pay__foot a:hover{color:var(--xmr);}
.xmrpay-pay__foot b{color:var(--ink);}
</style>

<div class="xmrpay-pay" id="xmrpay-pay">
  <div class="xmrpay-pay__bar">
    <span class="xmrpay-pay__brand"><span class="xmrpay-pay__mono" aria-hidden="true">ɱ</span> xmr&#8209;pay</span>
    <span class="xmrpay-pay__net">Monero</span>
  </div>

  <div class="xmrpay-pay__body">
    <h2 class="xmrpay-pay__title">Pay with Monero</h2>

    <?php if (!$locked) : ?>
      <p class="xmrpay-pay__sub">Your order is saved.</p>
      <div class="xmrpay-pay__notice">We couldn't set the Monero price just now (the price source was unreachable).
        Nothing has been charged. Please refresh this page in a moment, or contact the store and we'll send you a
        payment link.</div>
    <?php else : ?>
      <div class="xmrpay-pay__instructions"<?php echo !empty($pollUrl) ? ' hidden' : ''; ?>>
      <p class="xmrpay-pay__sub">Scan the code or copy the address into your wallet.</p>

      <?php if ($err) : ?>
        <div class="xmrpay-pay__notice">A Monero node is unavailable. Your quoted amount is fixed.
          This page will update when the store can verify your payment.</div>
      <?php endif; ?>

      <div class="xmrpay-pay__amount"><?php echo htmlspecialchars((string) $xmr); ?><span>XMR</span></div>
      <?php if ((string) $fiat !== '') : ?><div class="xmrpay-pay__fiat">Order total: <?php echo htmlspecialchars(strip_tags((string) $fiat), ENT_QUOTES); ?></div><?php endif; ?>

      <?php if (!empty($uri)) : ?>
        <div class="xmrpay-pay__qr"><div id="xmrpay-qr" role="img" aria-label="Monero payment QR code" data-uri="<?php echo htmlspecialchars($uri); ?>"></div></div>
      <?php endif; ?>

      <p class="xmrpay-pay__label" id="xmrpay-addr-label">Send to this address</p>
      <div class="xmrpay-pay__addr">
        <code id="xmrpay-addr" aria-labelledby="xmrpay-addr-label"><?php echo htmlspecialchars((string) $sub); ?></code>
        <button type="button" class="xmrpay-pay__copy" id="xmrpay-copy" aria-label="Copy the Monero address">Copy</button>
      </div>

      <?php if (!empty($uri)) : ?>
        <a class="xmrpay-pay__open" href="<?php echo htmlspecialchars($uri); ?>">Open in a Monero wallet</a>
      <?php endif; ?>

      </div>

      <div class="xmrpay-pay__status" id="xmrpay-status" role="status" aria-live="polite"><span class="xmrpay-pay__dot" aria-hidden="true"></span> Waiting for your payment&hellip;</div>
      <p class="xmrpay-pay__hint">This page updates automatically. Keep it open until payment is confirmed.</p>
    <?php endif; ?>
  </div>

  <div class="xmrpay-pay__foot">
    <span>Powered by <b>xmr&#8209;pay</b></span>
    <a href="<?php echo $brandUrl; ?>" target="_blank" rel="noopener">xmrpay.shop</a>
    <a href="<?php echo $githubUrl; ?>" target="_blank" rel="noopener">GitHub</a>
  </div>
</div>

<?php if ($locked && !empty($uri)) : ?>
<script src="<?php echo htmlspecialchars((string) $qrLibUrl, ENT_QUOTES); ?>"></script>
<?php endif; ?>
<?php if ($locked) : ?>
<script>
(function(){
  var btn=document.getElementById('xmrpay-copy'), code=document.getElementById('xmrpay-addr');
  if(btn&&code){ btn.addEventListener('click',function(){
    var t=code.textContent;
    var done=function(){ btn.textContent='Copied'; btn.className='xmrpay-pay__copy ok';
      setTimeout(function(){ btn.textContent='Copy'; btn.className='xmrpay-pay__copy'; },1600); };
    var fallback=function(){
      var copied=false, s=window.getSelection();
      try { var r=document.createRange(); r.selectNode(code); s.removeAllRanges(); s.addRange(r); copied=document.execCommand('copy'); } catch(e){}
      if(copied){ s.removeAllRanges(); done(); }
      else { btn.textContent='Copy failed. Select the address manually.'; btn.className='xmrpay-pay__copy'; }
    };
    try {
      if(navigator.clipboard&&navigator.clipboard.writeText){ navigator.clipboard.writeText(t).then(done,fallback); }
      else fallback();
    } catch(e){ fallback(); }
  }); }
})();
</script>
<?php endif; ?>
<?php if ($locked) : ?>
<script>
(function(){
  var qrElement=document.getElementById('xmrpay-qr'), qr=null, drawnUri='';
  function draw(){
    if(!qrElement) return;
    if(!window.QRCode){ setTimeout(draw,150); return; }
    var currentUri=qrElement.getAttribute('data-uri');
    if(currentUri===drawnUri) return;
    if(qr){ qr.makeCode(currentUri); }
    else { qr=new QRCode(qrElement,{text:currentUri,width:208,height:208,correctLevel:QRCode.CorrectLevel.M}); }
    drawnUri=currentUri;
  }
  draw();
  <?php if (!empty($pollUrl)) : ?>
  var url=<?php echo json_encode($pollUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>, ret=<?php echo json_encode($returnUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>, done=false, inFlight=false, errs=0, warns=0;
  var originalUri=<?php echo json_encode($uri, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>, originalAmount=<?php echo json_encode((string) $xmr, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  var wallet=document.querySelector('.xmrpay-pay__open'), amount=document.querySelector('.xmrpay-pay__amount');
  var instructions=document.querySelectorAll('.xmrpay-pay__instructions,.xmrpay-pay__sub,.xmrpay-pay__amount,.xmrpay-pay__fiat,.xmrpay-pay__qr,.xmrpay-pay__label,.xmrpay-pay__addr,.xmrpay-pay__open');
  var dot='<span class="xmrpay-pay__dot" aria-hidden="true"></span> ';
  function set(cls,msg){ var s=document.getElementById('xmrpay-status'); if(s){ s.className='xmrpay-pay__status'+(cls?' '+cls:''); s.innerHTML=dot; s.appendChild(document.createTextNode(msg)); } }
  function showInstructions(visible){
    for(var i=0;i<instructions.length;i++){ instructions[i].hidden=!visible; }
  }
  function validAmount(value){
    return typeof value==='string'&&/^(0|[1-9][0-9]*)(\.[0-9]{1,12})?$/.test(value)&&/[1-9]/.test(value);
  }
  function setAmount(value){
    if(typeof originalUri!=='string'||!validAmount(value)) return false;
    var paymentUri=originalUri.replace(/([?&]tx_amount=)[^&]*/,function(match,prefix){ return prefix+value; });
    if(!originalUri||paymentUri===originalUri&&value!==originalAmount) return false;
    if(wallet) wallet.setAttribute('href',paymentUri);
    if(amount&&amount.firstChild) amount.firstChild.nodeValue=value;
    if(qrElement){ qrElement.setAttribute('data-uri',paymentUri); draw(); }
    return true;
  }
  function poll(){
    if(done||inFlight) return;
    inFlight=true;
    fetch(url,{credentials:'same-origin',headers:{'Accept':'application/json'}})
      .then(function(r){return r.json();})
      .then(function(d){
        errs=0;
        showInstructions(false); set('warn', 'Payment status is unavailable. Please wait before sending another payment.');
        if(!d||typeof d.paid!=='boolean') return;
        if(d.paid){
          done=true; showInstructions(false); set('paid','Payment received: thank you!');
          if(ret){ try{ var target=new URL(ret,location.href); if(target.origin===location.origin && /^https?:$/.test(target.protocol)) setTimeout(function(){ window.location=target.href; },1600); }catch(e){} }
          return;
        }
        if(d.status==='partial'){
          if(validAmount(d.received)&&setAmount(d.shortfall)){
            warns=0; showInstructions(true);
            set('warn','Received '+d.received+' XMR : please send '+d.shortfall+' XMR more to the same address.');
          }
          return;
        }
        if(d&&d.status==='processing'){
          warns=0; showInstructions(false);
          set('', 'Payment detected. Waiting for confirmation; please do not send another payment.');
          return;
        }
        if(d&&(d.status==='node-error'||d.status==='crypto-missing'||d.status==='error')){
          if(++warns>=2){ set('warn', d.status==='crypto-missing'
            ? 'Payment verification is unavailable on the store right now: please contact the store owner.'
            : "Can't reach the Monero network at the moment. Payment status will update when the store can verify it. Contact the store if the problem persists."); }
        } else if(d&&d.status==='pending') { warns=0; setAmount(originalAmount); showInstructions(true); set('', 'Waiting for your payment…'); }
      }).catch(function(){
        showInstructions(false); set('warn', 'Payment status is unavailable. Please wait before sending another payment.');
        if(++errs>=20){ done=true; }
      }).then(function(){ inFlight=false; });  // give up quietly after ~4 min of network errors
  }
  showInstructions(false); set('', 'Checking payment status…');
  setInterval(poll,12000); poll();
  <?php endif; ?>
})();
</script>
<?php endif; ?>
