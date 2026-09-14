# Courier Essentials redesign — local review

Courier uses the same extracted sidebar, mobile navigation and topbar partials as the owner Dashboard. No Courier-only sidebar CSS or additional font import is introduced. The local accent is `#AF542B`; global olive, cream and Jost tokens remain in `ess-dashboard.css`.

The four KPI values, file input, form names, date filtering and all server request handlers are retained. Quick actions point to existing tools, export, refresh and queue controls. Courier partners are the existing `wb_allowed_couriers()` choices, not a newly invented partner database. “Add courier” adds a checked choice to this upload only.

Records have accessible Queue/History tabs. Existing search/sort/group/hide operations still target the queue; date filters still target sent history. Labels explain that distinction. Staff navigation uses existing feature permissions, including the employee-specific Input VAT grant.

## Verification

- PHP lint and Dashboard rendering/navigation/inspiration regression tests.
- `tests/ess-courier-render.php`: permission-controlled upload, escaped labels, four metrics, independent upload and unique record panels.
- `tests/ess-courier-navigation.php`: owner and employee feature boundaries.
- JavaScript syntax checks; original controller reaches its ready state.
- Local browser checks: multi-file selection, file removal, courier selection/addition, notes, date picker, records tabs, filter/sort/group controls, sidebar collapse, mobile navigation and drawers.
- The local fixture uses captured records and rejects every mutation. A submission correctly reaches the original error handler with an explicit read-only message.

No production deployment or production data mutation was performed. Actual server persistence, sending, archiving, deletion and download responses have not been end-to-end exercised in this local fixture. The PHP business-handler prefix is unchanged from the existing revision.
