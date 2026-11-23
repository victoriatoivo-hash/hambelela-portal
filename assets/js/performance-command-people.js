(() => {
 'use strict';
 // Display allowlist only; identities are resolved from active API records, never written.
 const eligible=p=>/^(?:ndinelao|dinelao|klaudia|claudia|secilia|cecilia|hope|moses)\b/i.test(String(p.full_name||p.name||'').trim());
 window.HambelelaPerformancePeople={eligible};
})();
