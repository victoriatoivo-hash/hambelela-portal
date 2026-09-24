<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_role('owner_admin');
if (empty($_SESSION['miv_shipping_csrf'])) $_SESSION['miv_shipping_csrf'] = bin2hex(random_bytes(24));
$pageTitle = 'MIV Shipping | ' . APP_NAME;
$activeApp = 'miv-shipping';
$extraStylesheets = [[
    'path' => 'assets/css/miv-shipping.css',
    'version' => (string) filemtime(BASE_PATH . '/assets/css/miv-shipping.css'),
]];
include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace miv-workspace">
<section id="mivApp" class="miv-app" data-extract-url="<?=htmlspecialchars(BASE_URL.'/apps/miv-shipping/extract.php',ENT_QUOTES,'UTF-8')?>" data-csrf="<?=htmlspecialchars((string)$_SESSION['miv_shipping_csrf'],ENT_QUOTES,'UTF-8')?>">
  <header class="miv-hero">
    <div><p>MIV SHIPPING</p><h1>China → Namibia Shipping</h1><span>Create customer quotes for consolidated orders through South Africa.</span></div>
    <div class="miv-hero-rate"><small>SA → Namibia</small><strong id="heroNamRate">N$70/kg</strong></div>
  </header>

  <nav class="miv-tabs" aria-label="MIV Shipping">
    <button class="is-active" data-tab="quote">New Quote</button>
    <button data-tab="saved">Saved Quotes</button>
    <button data-tab="settings">Settings</button>
  </nav>

  <section class="miv-view is-active" data-view="quote">
    <div class="miv-card">
      <div class="miv-card-head"><div><h2>Customer Quote</h2><p id="draftStatus">Draft saves automatically on this device.</p></div><button class="miv-btn miv-btn-ghost" id="resetQuote" type="button">Reset</button></div>
      <div class="miv-grid miv-grid-2">
        <label>Customer name<input id="customer" placeholder="Customer name" required></label>
        <label>Phone number<input id="phone" inputmode="tel" placeholder="Optional"></label>
        <label>Quote reference<input id="reference" placeholder="Generated automatically"></label>
        <label>Status<select id="status"><option>Draft</option><option>Sent</option><option>Accepted</option><option>Ordered</option><option>In Transit</option><option>Completed</option><option>Cancelled</option></select></label>
      </div>
    </div>

    <div class="miv-card">
      <div class="miv-card-head"><div><h3>Product Screenshot</h3><p>Upload the Chinese product screenshot. MIV can read visible name, price, quantity, weight and battery information.</p></div></div>
      <div class="miv-upload">
        <input id="productImage" type="file" accept="image/*" hidden>
        <button class="miv-btn miv-btn-secondary" id="chooseImage" type="button">Choose Screenshot</button>
        <span id="imageName">No screenshot selected.</span>
        <img id="imagePreview" alt="Selected product screenshot">
        <button class="miv-btn miv-btn-ghost" id="extractImage" type="button" disabled>Extract Product Details</button>
      </div>
      <div class="miv-extract" id="extractResult" hidden><strong>Extraction result</strong><p id="extractText"></p><button class="miv-btn miv-btn-secondary" id="applyExtract" type="button">Apply to newest item</button></div>
    </div>

    <div class="miv-card">
      <div class="miv-card-head"><div><h3>Order Items</h3><p>Use the exact product weight shown by the Chinese app whenever available.</p></div><button class="miv-btn miv-btn-primary" id="addItem" type="button">+ Add Item</button></div>
      <div id="items"></div>
    </div>

    <div class="miv-summary-grid">
      <div class="miv-card miv-breakdown">
        <h3>Internal Calculation</h3>
        <div><span>Total weight</span><b id="outWeight">0.000 kg</b></div>
        <div><span>Products subtotal</span><b id="outItemsCny">¥0.00</b></div>
        <div><span>Products in NAD</span><b id="outItemsNad">N$0.00</b></div>
        <div><span>China freight</span><b id="outChina">¥0.00</b></div>
        <div><span>Payment charge</span><b id="outPayment">¥0.00</b></div>
        <div><span>China freight in NAD</span><b id="outChinaNad">N$0.00</b></div>
        <div><span>SA → Namibia</span><b id="outNam">N$0.00</b></div>
        <div><span>Admin & handling</span><b id="outAdmin">N$0.00</b></div>
        <div class="miv-strong"><span>Shipping total</span><b id="outShipping">N$0.00</b></div>
      </div>
      <div class="miv-total-card">
        <span>ESTIMATED CUSTOMER TOTAL</span><strong id="outTotal">N$0.00</strong><small id="outBasis">Items + shipping · 0.000 kg</small>
        <div class="miv-actions"><button class="miv-btn miv-btn-light" id="previewQuote" type="button">Preview</button><button class="miv-btn miv-btn-light" id="saveQuote" type="button">Save Quote</button></div>
      </div>
    </div>
    <div class="miv-live"><div><small>LIVE TOTAL</small><span id="liveWeight">0.000 kg</span></div><strong id="liveTotal">N$0.00</strong></div>
  </section>

  <section class="miv-view" data-view="saved">
    <div class="miv-card">
      <div class="miv-card-head"><div><h2>Saved Quotes</h2><p>Open, duplicate or delete quotes saved in this browser.</p></div><button class="miv-btn miv-btn-primary" data-go-quote type="button">+ New Quote</button></div>
      <div class="miv-grid miv-grid-2"><input id="quoteSearch" placeholder="Search customer, quote or product"><select id="statusFilter"><option value="">All statuses</option><option>Draft</option><option>Sent</option><option>Accepted</option><option>Ordered</option><option>In Transit</option><option>Completed</option><option>Cancelled</option></select></div>
      <div id="savedQuotes" class="miv-saved-list"></div>
    </div>
  </section>

  <section class="miv-view" data-view="settings">
    <div class="miv-card">
      <div class="miv-card-head"><div><h2>Shipping Settings</h2><p>These defaults apply to new quotes only. Saved quotes keep their original rate snapshot.</p></div></div>
      <div class="miv-grid miv-grid-2">
        <label>Normal China freight (¥/kg)<input id="normalRate" type="number" min="0" step="0.01"></label>
        <label>Battery freight (¥/kg)<input id="batteryRate" type="number" min="0" step="0.01"></label>
        <label>SA → Namibia (N$/kg)<input id="namRate" type="number" min="0" step="0.01"></label>
        <label>China payment charge (%)<input id="paymentRate" type="number" min="0" step="0.1"></label>
        <label>Admin & handling (%)<input id="adminRate" type="number" min="0" step="0.1"></label>
        <label>CNY → NAD (N$ per ¥1)<input id="fxRate" type="number" min="0" step="0.0001"><small>Review this before sending a quote.</small></label>
        <label>Quote prefix<input id="quotePrefix"></label>
        <label>Quote validity (days)<input id="validDays" type="number" min="1" step="1"></label>
      </div>
      <label>Customer disclaimer<textarea id="disclaimer" rows="4"></textarea></label>
      <button class="miv-btn miv-btn-primary" id="saveSettings" type="button">Save Settings</button>
    </div>
  </section>

  <div class="miv-modal" id="quoteModal" hidden>
    <div class="miv-modal-panel">
      <article class="miv-quote-paper" id="quotePaper">
        <header><p>MIV SHIPPING</p><h2>China → Namibia Quotation</h2><span id="qMeta"></span></header>
        <section>
          <div class="miv-quote-meta"><div><small>CUSTOMER</small><strong id="qCustomer"></strong><span id="qPhone"></span></div><div><small>QUOTE</small><strong id="qReference"></strong><span id="qDate"></span></div></div>
          <div class="miv-quote-total"><small>TOTAL PAYABLE</small><strong id="qTotal"></strong><span id="qWeight"></span></div>
          <div class="miv-quote-sums"><div><span>Products subtotal</span><b id="qProducts"></b></div><div><span>Shipping total</span><b id="qShipping"></b></div><div><span>Total payable</span><b id="qGrand"></b></div></div>
          <div id="qItems"></div>
          <p class="miv-disclaimer" id="qDisclaimer"></p><p class="miv-disclaimer" id="qValidity"></p>
          <div class="miv-actions miv-no-print"><button class="miv-btn miv-btn-primary" id="sharePdf" type="button">Share PDF</button><button class="miv-btn miv-btn-secondary" id="downloadPdf" type="button">Download PDF</button><button class="miv-btn miv-btn-ghost" id="copyQuote" type="button">Copy Summary</button><button class="miv-btn miv-btn-ghost" id="closeModal" type="button">Close</button></div>
        </section>
      </article>
    </div>
  </div>
  <div class="miv-toast" id="mivToast"></div>
</section>
</main>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.2/dist/jspdf.umd.min.js"></script>
<script src="<?=BASE_URL?>/assets/js/miv-shipping.js?v=<?=filemtime(BASE_PATH.'/assets/js/miv-shipping.js')?>"></script>
<?php include BASE_PATH . '/shared/footer.php'; ?>