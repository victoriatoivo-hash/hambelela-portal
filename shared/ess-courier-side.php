<aside class="courier-side-stack" aria-label="Courier shortcuts and partners">
 <section class="courier-panel"><header class="courier-panel-header"><span class="courier-section-icon"><i data-lucide="zap" aria-hidden="true"></i></span><h2>Quick Actions</h2></header>
 <div class="courier-quick-grid">
 <?php if ($canManageWaybills): ?><button type="button" class="courier-quick-action" data-courier-tools-open><span class="courier-quick-icon"><i data-lucide="wrench" aria-hidden="true"></i></span><span>Courier tools<small>Trash, archive & activity</small></span></button><?php endif; ?>
 <?php if ($canExportWaybills): ?><a class="courier-quick-action" href="courier.php?action=waybill_export_csv&amp;date_from=<?= wb_e($historyDateFrom) ?>&amp;date_to=<?= wb_e($historyDateTo) ?>"><span class="courier-quick-icon"><i data-lucide="file-down" aria-hidden="true"></i></span><span>Export report<small>Download CSV</small></span></a><?php endif; ?>
 <button type="button" class="courier-quick-action" data-ess-refresh><span class="courier-quick-icon"><i data-lucide="refresh-cw" aria-hidden="true"></i></span><span>Refresh waybills<small>Get latest records</small></span></button>
 <a class="courier-quick-action" href="#courier-records"><span class="courier-quick-icon"><i data-lucide="list-checks" aria-hidden="true"></i></span><span>Manage queue<small>Review & send</small></span></a>
 </div></section>
 <section class="courier-panel"><header class="courier-panel-header"><span class="courier-section-icon"><i data-lucide="truck" aria-hidden="true"></i></span><div><h2>Courier Partners</h2><p>Available courier selections</p></div></header>
 <?php foreach (wb_allowed_couriers() as $partner): ?><div class="courier-partner-row"><i data-lucide="truck" aria-hidden="true"></i><span><?= wb_e($partner) ?></span><small>Available</small></div><?php endforeach; ?>
 <?php if ($canUploadWaybills): ?><button class="courier-partner-add" type="button" data-ess-add-courier><i data-lucide="plus" aria-hidden="true"></i>Add courier to this upload</button><?php endif; ?>
 </section>
 <div class="courier-service-note"><i data-lucide="package-check" aria-hidden="true"></i><p>Reliable delivery builds happy customers.</p></div>
</aside>
