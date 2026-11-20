<?php if(!defined('DRIVER_OFFLINE_LAB')||!DRIVER_OFFLINE_LAB)exit; /* Isolated supplied visual fixture, never a production conversation. */ ?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#f7f9f6"><title>Hambelela Driver — Dispatch Chat (Phase 08 preview)</title>


<link rel="stylesheet" href="/assets/css/driver-dashboard.css"><link rel="stylesheet" href="/assets/css/driver-dashboard-integration.css"><link rel="stylesheet" href="/assets/css/driver-dispatch-messages.css">
</head><body class="ho-driver-page">

<main style="height:100dvh;min-height:0" class="ho-driver-dashboard ho-driver-dispatch" aria-label="Dispatch chat" data-preview-only="true">

 <header class="hm-header">
  <div class="hm-brand" aria-hidden="true">H</div>
  <div class="hm-header-titles"><div class="hm-eyebrow">HAMBELELA <span>/ DRIVER</span></div><h1 class="hm-title">Dispatch chat</h1><p class="hm-subtitle">Connected to Hambelela team</p></div>
  <button class="hm-phone-button" id="dispatch-call" type="button" aria-label="Call dispatch"><svg class="ho-driver-dashboard__icon" viewBox="0 0 24 24" fill="none" aria-hidden="true" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.9v3a2 2 0 0 1-2.2 2A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7l.5 3a2 2 0 0 1-.6 2L7.2 10a16 16 0 0 0 6.8 6.8l1.3-1.8a2 2 0 0 1 2-.6l3 .5a2 2 0 0 1 1.7 2Z"/></svg></button>
 </header>
 <section class="hm-contact" aria-label="Dispatch contact">
  <span class="hm-avatar" aria-hidden="true">HO</span><div class="hm-contact-copy"><div class="hm-contact-title">Hambelela Dispatch</div><div class="hm-contact-status"><span class="hm-availability-dot" aria-hidden="true"></span>Available during shop hours</div></div>
  <button class="hm-info" type="button" id="dispatch-info" aria-label="Dispatch availability details"><svg class="ho-driver-dashboard__icon" viewBox="0 0 24 24" fill="none" aria-hidden="true" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 7h.01"/></svg></button>
 </section>
 <section class="hm-history" id="dispatch-history" role="log" aria-label="Message history" aria-live="polite" aria-relevant="additions text">
  <div class="hm-date">TODAY · 20 NOVEMBER</div><div class="hm-msg-group" id="dispatch-message-list">
   <div class="hm-bubble hm-bubble--incoming"><p>Good morning, Israel. Your first 3 parcels are ready for collection.</p><div class="hm-message-meta"><time>08:04</time></div></div>
   <div class="hm-bubble hm-bubble--outgoing"><p>Morning. I have collected the parcels and confirmed the quantities.</p><div class="hm-message-meta"><time>08:21</time><span class="hm-receipt" aria-label="Read">✓✓</span></div></div>
   <div class="hm-bubble hm-bubble--incoming"><p>Great. Please deliver Pioneers Park first, then Olympia. The customer at Olympia requested a call before arrival.</p><div class="hm-message-meta"><time>08:24</time></div></div>
   <div class="hm-bubble hm-bubble--outgoing"><p>Understood. I will call before I arrive.</p><div class="hm-message-meta"><time>08:27</time><span class="hm-receipt" aria-label="Read">✓✓</span></div></div>
  </div>
 </section>
 <section class="hm-compose-zone" aria-label="Write to dispatch">
  <h2 class="hm-quick-title">Quick replies</h2>
  <div class="hm-quick-list" role="group" aria-label="Suggested replies">
   <button type="button" class="hm-quick" data-reply="At customer location">At customer location</button>
   <button type="button" class="hm-quick" data-reply="Running late">Running late</button>
   <button type="button" class="hm-quick" data-reply="Need assistance">Need assistance</button>
  </div>
  <div id="dispatch-attachment-view" class="hm-attachment-selected" hidden><span id="dispatch-attachment-name"></span><button id="dispatch-attachment-remove" class="hm-remove-attachment" type="button" aria-label="Remove attachment">Remove</button></div>
  <label class="hm-urgency" id="dispatch-urgency-row" hidden><input id="dispatch-urgent" type="checkbox">Mark as urgent for dispatch review</label>
  <form class="hm-composer" id="dispatch-compose-form">
   <button type="button" class="hm-attach" id="dispatch-attach" aria-label="Add attachment"><svg class="ho-driver-dashboard__icon" viewBox="0 0 24 24" fill="none" aria-hidden="true" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="m21 11.5-8.5 8.5a6 6 0 0 1-8.5-8.5L13 2.5a4 4 0 0 1 5.7 5.7l-8.5 8.5a2 2 0 0 1-2.8-2.8L15 6.5"/></svg></button>
   <input class="hm-sr" type="file" id="dispatch-file" accept="image/jpeg,image/png,image/webp,application/pdf" aria-label="Choose attachment">
   <label for="dispatch-message" class="hm-sr">Message</label>
   <textarea id="dispatch-message" class="hm-message-input" rows="1" maxlength="2000" placeholder="Type a message..."></textarea>
   <button type="submit" class="hm-send" id="dispatch-send" aria-label="Send message" disabled><svg class="ho-driver-dashboard__icon" viewBox="0 0 24 24" fill="none" aria-hidden="true" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button>
  </form>
  <p id="dispatch-action-status" class="hm-action-status" role="status" hidden></p>
 </section>
 <nav class="ho-driver-dashboard__bottomnav" aria-label="Driver primary navigation">
  <a class="ho-driver-dashboard__tab" href="dashboard-preview.html"><svg class="ho-driver-dashboard__icon" viewBox="0 0 24 24" fill="none" aria-hidden="true" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/></svg><span>Home</span></a>
  <a class="ho-driver-dashboard__tab" href="deliveries-preview.html"><svg class="ho-driver-dashboard__icon" viewBox="0 0 24 24" fill="none" aria-hidden="true" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="m12 2 9 5-9 5-9-5 9-5Z"/><path d="M3 7v10l9 5 9-5V7M12 12v10"/></svg><span>Deliveries</span></a>
  <a class="ho-driver-dashboard__tab" href="live-route-preview.html"><svg class="ho-driver-dashboard__icon" viewBox="0 0 24 24" fill="none" aria-hidden="true" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21 3 6"/><path d="M9 3v15M15 6v15"/></svg><span>Map</span></a>
  <a class="ho-driver-dashboard__tab" href="errands-preview.html"><svg class="ho-driver-dashboard__icon" viewBox="0 0 24 24" fill="none" aria-hidden="true" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="18" height="14" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 12h18"/></svg><span>Errands</span></a>
  <button class="ho-driver-dashboard__tab" id="dispatch-more" type="button" aria-current="page"><svg class="ho-driver-dashboard__icon" viewBox="0 0 24 24" fill="none" aria-hidden="true" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg><span>More</span></button>
 </nav>
 <div id="dispatch-modal-layer" class="hm-modal-layer" hidden aria-hidden="true">
  <section class="hm-dialog" role="dialog" aria-modal="true" aria-labelledby="dispatch-dialog-title">
   <div class="hm-dialog-head"><h2 id="dispatch-dialog-title"></h2><button type="button" id="dispatch-dialog-close" class="hm-dialog-close" aria-label="Close dialog">×</button></div>
   <div id="dispatch-dialog-body"></div>
   <div class="hm-dialog-actions"><button type="button" class="hm-dialog-button hm-dialog-button--primary" id="dispatch-dialog-ok">Close</button></div>
  </section>
 </div>
</main>

</body></html>
