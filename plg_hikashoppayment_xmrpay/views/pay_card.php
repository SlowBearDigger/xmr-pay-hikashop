<?php

/**
 * The xmr-pay branded payment card — the single canonical source. Self-contained and scoped under
 * .xmrpay-pay so it renders identically on any cart template. Vendored into each adapter at build
 * time (do not edit the copies). Expects in the including scope:
 *   $uri (monero: URI or ''), $sub (subaddress), $xmr (XMR amount string or '' when the price could
 *   not be locked), $fiat (formatted fiat HTML/text), $err (bool: node/rate hiccup), $qrLibUrl,
 *   $pollUrl (full URL or ''), $returnUrl.
 * The QR is drawn client-side; the subaddress never leaves the browser. Scripts are emitted inline in
 * the body so they run with the DOM nodes already present.
 */

defined('_JEXEC') or die('Restricted access');

$brandUrl  = 'https://xmrpay.shop';
$githubUrl = 'https://github.com/SlowBearDigger';
$locked    = ((string) $xmr !== '');   // was the price actually locked? if not, show a clean error state
?>
<style>
.xmrpay-pay{--xmr:#ff6600;--xmr-d:#e65c00;--ink:#1a1a1a;--mut:#6b7280;--line:#e5e7eb;--bg:#fff;--soft:#f9fafb;--ok:#16a34a;
  max-width:480px;margin:24px auto;background:var(--bg);border:1px solid var(--line);border-radius:16px;
  box-shadow:0 8px 30px rgba(0,0,0,.06);overflow:hidden;color:var(--ink);
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;line-height:1.45;}
.xmrpay-pay *{box-sizing:border-box;}
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
        Nothing has been charged — please refresh this page in a moment, or contact the store and we'll send you a
        payment link.</div>
    <?php else : ?>
      <p class="xmrpay-pay__sub">Scan the code or copy the address into your wallet.</p>

      <?php if ($err) : ?>
        <div class="xmrpay-pay__notice">Heads up: a Monero node was briefly unreachable. Your amount below is locked —
          send it and the order confirms automatically once the network catches up.</div>
      <?php endif; ?>

      <div class="xmrpay-pay__amount"><?php echo htmlspecialchars((string) $xmr); ?><span>XMR</span></div>
      <?php if ((string) $fiat !== '') : ?><div class="xmrpay-pay__fiat">&asymp; <?php echo htmlspecialchars(strip_tags((string) $fiat), ENT_QUOTES); ?></div><?php endif; ?>

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

      <div class="xmrpay-pay__status" id="xmrpay-status" role="status" aria-live="polite"><span class="xmrpay-pay__dot" aria-hidden="true"></span> Waiting for your payment&hellip;</div>
      <p class="xmrpay-pay__hint">This page updates by itself — keep it open until it confirms.</p>
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
<script>
(function(){
  function draw(){
    var el=document.getElementById('xmrpay-qr');
    if(!el) return;
    if(!window.QRCode){ setTimeout(draw,150); return; }
    if(el.children.length) return;
    new QRCode(el,{text:el.getAttribute('data-uri'),width:208,height:208,correctLevel:QRCode.CorrectLevel.M});
  }
  draw();
})();
</script>
<?php endif; ?>
<?php if ($locked) : ?>
<script>
(function(){
  var btn=document.getElementById('xmrpay-copy'), code=document.getElementById('xmrpay-addr');
  if(btn&&code){ btn.addEventListener('click',function(){
    var t=code.textContent;
    var done=function(){ btn.textContent='Copied'; btn.className='xmrpay-pay__copy ok';
      setTimeout(function(){ btn.textContent='Copy'; btn.className='xmrpay-pay__copy'; },1600); };
    if(navigator.clipboard&&navigator.clipboard.writeText){ navigator.clipboard.writeText(t).then(done,done); }
    else { var r=document.createRange(); r.selectNode(code); var s=window.getSelection(); s.removeAllRanges(); s.addRange(r); try{document.execCommand('copy');}catch(e){} s.removeAllRanges(); done(); }
  }); }
})();
</script>
<?php endif; ?>
<?php if ($locked && !empty($pollUrl)) : ?>
<script>
(function(){
  var url=<?php echo json_encode($pollUrl); ?>, ret=<?php echo json_encode($returnUrl); ?>, done=false, errs=0;
  function poll(){
    if(done) return;
    fetch(url,{credentials:'same-origin',headers:{'Accept':'application/json'}})
      .then(function(r){return r.json();})
      .then(function(d){
        errs=0;
        if(d&&d.paid){
          done=true;
          var s=document.getElementById('xmrpay-status');
          if(s){ s.className='xmrpay-pay__status paid'; s.innerHTML='<span class="xmrpay-pay__dot" aria-hidden="true"></span> Payment received — thank you!'; }
          setTimeout(function(){ if(ret){window.location=ret;}else{window.location.reload();} },1600);
        }
      }).catch(function(){ if(++errs>=20){ done=true; } });  // give up quietly after ~4 min of network errors
  }
  setInterval(poll,12000); poll();
})();
</script>
<?php endif; ?>
