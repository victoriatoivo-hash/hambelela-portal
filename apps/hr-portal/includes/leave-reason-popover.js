(function () {
  'use strict';

  function initialiseLeaveReasonPopovers(root) {
    var histories = (root || document).querySelectorAll('[data-leave-history]');
    if (!histories.length || document.documentElement.dataset.leaveReasonPopoversInitialised === 'true') return;
    document.documentElement.dataset.leaveReasonPopoversInitialised = 'true';

    var activeTrigger = null;
    var popover = document.createElement('div');
    popover.id = 'leave-reason-popover';
    popover.className = 'leave-reason-popover';
    popover.setAttribute('data-leave-reason-popover', '');
    popover.setAttribute('role', 'dialog');
    popover.setAttribute('aria-modal', 'false');
    popover.setAttribute('aria-labelledby', 'leave-reason-popover-title');
    popover.hidden = true;
    popover.innerHTML = '<div class="leave-reason-popover__header"><h4 id="leave-reason-popover-title">Reason for rejection</h4><button type="button" class="leave-reason-popover__close" data-close-leave-reason aria-label="Close rejection reason">&times;</button></div><div class="leave-reason-popover__body"><p class="leave-reason-popover__text"></p><dl class="leave-reason-popover__details"><div><dt>Decision date</dt><dd data-leave-decision-date></dd></div><div><dt>Reviewed by</dt><dd data-leave-reviewed-by></dd></div></dl></div>';
    document.body.appendChild(popover);

    function positionPopover() {
      if (!activeTrigger || popover.hidden) return;
      var triggerRect = activeTrigger.getBoundingClientRect();
      var popoverRect = popover.getBoundingClientRect();
      var gap = 6;
      var edge = 10;
      var left = Math.max(edge, Math.min(triggerRect.left, window.innerWidth - popoverRect.width - edge));
      var top = triggerRect.bottom + gap;
      if (top + popoverRect.height > window.innerHeight - edge) top = triggerRect.top - popoverRect.height - gap;
      top = Math.max(edge, Math.min(top, window.innerHeight - popoverRect.height - edge));
      popover.style.left = left + 'px';
      popover.style.top = top + 'px';
    }

    function closePopover(restoreFocus) {
      if (!activeTrigger) return;
      var trigger = activeTrigger;
      popover.hidden = true;
      popover.style.left = '';
      popover.style.top = '';
      trigger.setAttribute('aria-expanded', 'false');
      activeTrigger = null;
      if (restoreFocus) trigger.focus();
    }

    function openPopover(trigger) {
      closePopover(false);
      activeTrigger = trigger;
      popover.querySelector('.leave-reason-popover__text').textContent = trigger.dataset.reason || '';
      popover.querySelector('[data-leave-decision-date]').textContent = trigger.dataset.decisionDate || 'Not recorded';
      popover.querySelector('[data-leave-reviewed-by]').textContent = trigger.dataset.reviewedBy || 'HR Administration';
      popover.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
      window.requestAnimationFrame(positionPopover);
    }

    document.addEventListener('click', function (event) {
      var trigger = event.target.closest('[data-leave-reason-trigger]');
      if (trigger && trigger.closest('[data-leave-history]')) {
        event.preventDefault();
        if (activeTrigger === trigger && !popover.hidden) closePopover(true);
        else openPopover(trigger);
        return;
      }
      if (event.target.closest('[data-close-leave-reason]')) {
        closePopover(true);
        return;
      }
      if (activeTrigger && !popover.contains(event.target)) closePopover(false);
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && activeTrigger) closePopover(true);
    });
    window.addEventListener('resize', positionPopover);
    window.addEventListener('scroll', positionPopover, true);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { initialiseLeaveReasonPopovers(document); });
  else initialiseLeaveReasonPopovers(document);
}());
