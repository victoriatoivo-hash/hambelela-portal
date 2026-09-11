    <header class="ess-topbar">
        <?php if (!empty($essHeadingPartial)) { include $essHeadingPartial; } else { ?>
        <div class="ess-welcome"><span class="ess-section-label">YOUR WORKSPACE</span><h1><span data-ess-greeting><?= $essGreeting ?></span>, <?= $essEscape($essFirstName) ?></h1><p>Welcome to your business command center.</p></div>
        <?php } ?>
        <div class="ess-topbar-actions">
            <label class="ess-search"><i data-lucide="search" aria-hidden="true"></i><input type="search" data-ess-search placeholder="Find a module…" aria-label="Search dashboard modules" aria-controls="ess-modules" autocomplete="off"><span aria-hidden="true">/</span></label>
            <div class="ess-account-slot" data-portal-header-status-target></div>
        </div>
    </header>
