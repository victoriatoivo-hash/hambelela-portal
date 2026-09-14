# Packing redesign — local review

Preview: http://127.0.0.1:8799/ (local PHP review router outside the repository).

## Delivered

- Essentials shell, Jost typography, teal Packing accent, responsive KPI layout.
- Compact toolbar with persistent search and Clear filters.
- Month headings and table presentation; configured priority/status colours retained.
- Item details, website/files tabs, new-item dialog, Packing tools and bulk presentation.
- Invoice progress and collapsible manual fallback, with separate manual multi-item mode.
- Physical quantities remain separate and more prominent than weighted workload; exact existing formula tooltip retained.

## Verified

- JavaScript syntax and PHP lint; git diff whitespace checks.
- Existing static tests: physical workload, manual multi-item, received quantity review,
  two-stage workflow, role visibility/sync/notifications, multi-file and website separation.
- Browser: main board, new-item dialog, manual received quantity confirmation, assignment summary,
  item drawer opening, tools/Columns, invoice layout; narrow 390px layout.

## Release boundary

Not published. Preview uses labelled demonstration records, not customer records.
Preview blocks writes and PDF extraction. Persistence, actual PDF extraction, exports and
destructive/recovery actions have not been exercised end-to-end against a connected database.
Keep the separate Task Management performance changes out of the Packing release unless approved.
